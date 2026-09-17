<?php

namespace AuthEduroam;

use DOMDocument;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Authenticator
{
    private const PAGE_URL = 'https://analysis.eduroam.edu.cn/checkc/pkudetection';
    private const AUTH_URL = 'https://analysis.eduroam.edu.cn/checkc/peapmschap';
    private const BACKUP_AUTH_URL = 'https://eduroam.seesea.site/api/auth/test';

    /**
     * Return success or an AuthEduroam::auth.eduroam.error translation suffix.
     * Never return or log testinfo: the upstream diagnostics can contain passwords.
     */
    public function authenticate(string $username, string $password): string
    {
        $result = $this->authenticateWithPku($username, $password);
        if ($result === 'success' || $result === 'credential') {
            return $result;
        }

        // Retry once on the backup only when the primary cannot decide reliably.
        return $this->authenticateWithSeesea($username, $password);
    }

    private function authenticateWithPku(string $username, string $password): string
    {
        // Each login needs its own session, shared only by these two requests.
        $http = Http::withOptions([
            'cookies' => new CookieJar(),
            'connect_timeout' => 10,
            'allow_redirects' => false,
        ])->timeout(30);

        try {
            $page = $http->get(self::PAGE_URL);
            if (!$page->successful()) {
                return 'failure';
            }

            $token = $this->csrfToken($page->body());
            if ($token === null) {
                return 'failure';
            }

            $response = $http->asForm()->post(self::AUTH_URL, [
                'username' => $username,
                'passwd' => $password,
                '_csrf-f' => $token,
            ]);
        } catch (ConnectionException $exception) {
            return 'failure';
        }

        if (!$response->successful()) {
            if (in_array($response->status(), [400, 403], true)) {
                return 'illegal';
            }

            return in_array($response->status(), [408, 504], true) ? 'timeout' : 'failure';
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['logtitle'], $data['testinfo']) || !is_array($data['testinfo'])) {
            return 'unknown';
        }

        // The live API returns ["SUCCESS"] / ["FAILURE"]; also accept strings.
        $status = $data['logtitle'];
        if (is_array($status) && count($status) === 1 && isset($status[0])) {
            $status = $status[0];
        }

        foreach ($data['testinfo'] as $line) {
            if (!is_string($line)) {
                return 'unknown';
            }
        }

        if ($status === 'SUCCESS' && in_array(
            'CTRL-EVENT-EAP-SUCCESS EAP authentication completed successfully',
            $data['testinfo'],
            true
        )) {
            return 'success';
        }

        $log = implode("\n", $data['testinfo']);
        if (strpos($log, 'EAPOL test timed out') !== false) {
            return 'timeout';
        }

        if ($status === 'FAILURE' && (
            strpos($log, 'EAP Failure') !== false ||
            strpos($log, 'EAP authentication failed') !== false ||
            strpos($log, 'Access-Reject') !== false
        )) {
            return 'credential';
        }

        return 'unknown';
    }

    private function authenticateWithSeesea(string $username, string $password): string
    {
        try {
            // This site has a separate JSON API; never forward PKU cookies or CSRF.
            $response = Http::withOptions([
                'connect_timeout' => 10,
                'allow_redirects' => false,
            ])->timeout(30)->asJson()->post(self::BACKUP_AUTH_URL, [
                'login' => $username,
                'password' => $password,
            ]);
        } catch (ConnectionException $exception) {
            return 'failure';
        }

        if (!$response->successful()) {
            return in_array($response->status(), [408, 504], true) ? 'timeout' : 'failure';
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return 'unknown';
        }

        // Match the primary's protocol; success on an unrelated test is insufficient.
        $results = array_values(array_filter($data['results'], function ($result) {
            return is_array($result) && ($result['method'] ?? null) === 'PEAP_MSCHAPV2';
        }));
        if (count($results) !== 1 || !isset($results[0]['success']) || !is_bool($results[0]['success'])) {
            return 'unknown';
        }

        $result = $results[0];
        if ($result['success'] === true) {
            return 'success';
        }

        // Use only diagnostic categories, never expose the remote output itself.
        $log = $result['output'] ?? null;
        if (!is_string($log)) {
            return 'unknown';
        }
        if (strpos($log, 'EAPOL test timed out') !== false) {
            return 'timeout';
        }
        if (strpos($log, 'EAP Failure') !== false ||
            strpos($log, 'EAP authentication failed') !== false ||
            strpos($log, 'Access-Reject') !== false) {
            return 'credential';
        }

        return 'unknown';
    }

    private function csrfToken(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->loadHTML($html, LIBXML_NONET);
            foreach ($document->getElementsByTagName('meta') as $meta) {
                if ($meta->getAttribute('name') === 'csrf-token') {
                    $token = $meta->getAttribute('content');

                    return $token !== '' ? $token : null;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return null;
    }
}
