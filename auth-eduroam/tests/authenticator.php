<?php

// Run with Blessing Skin's Composer autoloader, or a compatible Laravel 8 install:
// php auth-eduroam/tests/authenticator.php /path/to/vendor/autoload.php
$autoload = $argv[1] ?? dirname(__DIR__, 3).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Pass the path to Blessing Skin's vendor/autoload.php.\n");
    exit(1);
}

require $autoload;
require __DIR__.'/../src/Authenticator.php';

use AuthEduroam\Authenticator;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

const PAGE_URL = 'https://analysis.eduroam.edu.cn/checkc/pkudetection';
const AUTH_URL = 'https://analysis.eduroam.edu.cn/checkc/peapmschap';
const BACKUP_AUTH_URL = 'https://eduroam.seesea.site/api/auth/test';
const SUCCESS_LINE = 'CTRL-EVENT-EAP-SUCCESS EAP authentication completed successfully';
const TOKEN_PAGE = '<html><head><meta content="csrf+token&amp;value=" name="csrf-token"></head></html>';

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function backupSuccess(): array
{
    return ['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => true, 'output' => SUCCESS_LINE]]];
}

function authenticateWithResponse($body, int $status, bool $expectBackup): string
{
    Http::swap(new Factory());
    $urls = [];
    Http::fake(function ($request) use ($body, $status, &$urls) {
        $urls[] = $request->url();
        if ($request->url() === PAGE_URL) {
            return Http::response(TOKEN_PAGE);
        }
        if ($request->url() === BACKUP_AUTH_URL) {
            return Http::response(backupSuccess());
        }
        check($request->url() === AUTH_URL, 'Unexpected remote URL.');

        return Http::response($body, $status);
    });

    $result = (new Authenticator())->authenticate('user@example.invalid', 'dummy-password');
    $expectedUrls = [PAGE_URL, AUTH_URL];
    if ($expectBackup) {
        $expectedUrls[] = BACKUP_AUTH_URL;
    }
    check($urls === $expectedUrls, 'Incorrect fallback decision, request order, or retry count.');

    return $result;
}

$cases = [
    'array success' => [['logtitle' => ['SUCCESS'], 'testinfo' => [SUCCESS_LINE]], 200, 'success'],
    'string success' => [['logtitle' => 'SUCCESS', 'testinfo' => [SUCCESS_LINE]], 200, 'success'],
    'access reject' => [['logtitle' => ['FAILURE'], 'testinfo' => ['RADIUS message: code=3 (Access-Reject)']], 200, 'credential'],
    'EAP rejection' => [['logtitle' => 'FAILURE', 'testinfo' => ['EAP Failure']], 200, 'credential'],
    'EAP failure event' => [['logtitle' => ['FAILURE'], 'testinfo' => ['CTRL-EVENT-EAP-FAILURE EAP authentication failed']], 200, 'credential'],
    'upstream timeout' => [['logtitle' => ['FAILURE'], 'testinfo' => ['EAPOL test timed out']], 200, 'timeout'],
    'success title alone' => [['logtitle' => ['SUCCESS'], 'testinfo' => []], 200, 'unknown'],
    'success log alone' => [['logtitle' => ['FAILURE'], 'testinfo' => [SUCCESS_LINE]], 200, 'unknown'],
    'reflected success text' => [['logtitle' => ['SUCCESS'], 'testinfo' => ['identity: '.SUCCESS_LINE]], 200, 'unknown'],
    'ambiguous title' => [['logtitle' => ['SUCCESS', 'FAILURE'], 'testinfo' => [SUCCESS_LINE]], 200, 'unknown'],
    'non-string title' => [['logtitle' => true, 'testinfo' => [SUCCESS_LINE]], 200, 'unknown'],
    'malformed log' => [['logtitle' => 'SUCCESS', 'testinfo' => [[SUCCESS_LINE]]], 200, 'unknown'],
    'non-array log' => [['logtitle' => 'SUCCESS', 'testinfo' => SUCCESS_LINE], 200, 'unknown'],
    'missing fields' => [[], 200, 'unknown'],
    'null JSON' => ['null', 200, 'unknown'],
    'invalid JSON' => ['<html>Upstream error</html>', 200, 'unknown'],
    'HTTP error with success body' => [['logtitle' => 'SUCCESS', 'testinfo' => [SUCCESS_LINE]], 500, 'failure'],
    'CSRF rejection' => ['', 400, 'illegal'],
    'forbidden' => ['', 403, 'illegal'],
    'request timeout' => ['', 408, 'timeout'],
    'gateway timeout' => ['', 504, 'timeout'],
    'rate limit' => ['', 429, 'failure'],
    'redirect' => ['', 302, 'failure'],
];

$passed = 0;
foreach ($cases as $name => [$body, $status, $expected]) {
    $expectBackup = !in_array($expected, ['success', 'credential'], true);
    check(authenticateWithResponse($body, $status, $expectBackup) === ($expectBackup ? 'success' : $expected), $name.' returned the wrong result.');
    $passed++;
}

// Exercise actual Guzzle cookie middleware and form encoding underneath Http::fake.
Http::swap(new Factory());
$requests = [];
$jars = [];
$username = 'user+tag@example.invalid';
$password = 'dummy+&= %密码';
Http::fake(function ($request, $options) use (&$requests, &$jars, $username, $password) {
    $requests[] = $request;
    check($options['cookies'] instanceof CookieJar, 'Missing cookie jar.');
    check($options['allow_redirects'] === false, 'Credential requests must not follow redirects.');
    check($options['connect_timeout'] === 10 && $options['timeout'] === 30, 'Missing bounded timeouts.');
    if ($request->url() === PAGE_URL) {
        check($request->method() === 'GET', 'The session page requires GET.');
        check($request->header('Cookie') === [], 'A new login must not inherit old cookies.');
        $jars[] = $options['cookies'];

        return Http::response(TOKEN_PAGE, 200, [
            'Set-Cookie' => [
                'advanced-frontend=test-session; Path=/; Secure; HttpOnly',
                '_csrf-f=test-cookie; Path=/; Secure; HttpOnly',
            ],
        ]);
    }

    check($request->url() === AUTH_URL && $request->method() === 'POST', 'Incorrect authentication endpoint or method.');
    check($options['cookies'] === end($jars), 'Session must be shared between GET and POST.');
    $cookies = implode('; ', $request->header('Cookie'));
    check(strpos($cookies, 'advanced-frontend=test-session') !== false, 'Session cookie was not sent.');
    check(strpos($cookies, '_csrf-f=test-cookie') !== false, 'CSRF cookie was not sent.');
    check(strpos(implode('; ', $request->header('Content-Type')), 'application/x-www-form-urlencoded') === 0, 'Credentials must be form encoded.');
    check($request->data() === [
        'username' => $username,
        'passwd' => $password,
        '_csrf-f' => 'csrf+token&value=',
    ], 'Credentials or HTML-encoded CSRF token were corrupted.');

    return Http::response(['logtitle' => ['SUCCESS'], 'testinfo' => [SUCCESS_LINE]]);
});
$authenticator = new Authenticator();
check($authenticator->authenticate($username, $password) === 'success', 'Session handshake failed.');
check($authenticator->authenticate($username, $password) === 'success', 'Second session handshake failed.');
check(count($requests) === 4 && count($jars) === 2 && $jars[0] !== $jars[1], 'Login attempts must use isolated sessions without retries.');
$passed++;

foreach (['<html>No token</html>', '<meta name="csrf-token" content="">', ''] as $page) {
    Http::swap(new Factory());
    $urls = [];
    Http::fake(function ($request) use ($page, &$urls) {
        $urls[] = $request->url();
        if ($request->url() === BACKUP_AUTH_URL) {
            return Http::response(backupSuccess());
        }
        check($request->url() === PAGE_URL, 'Credentials sent without a CSRF token.');

        return Http::response($page);
    });
    check($authenticator->authenticate($username, $password) === 'success' && $urls === [PAGE_URL, BACKUP_AUTH_URL], 'Missing token must skip the primary POST and try the backup once.');
    $passed++;
}

foreach ([302, 500] as $status) {
    Http::swap(new Factory());
    $urls = [];
    Http::fake(function ($request) use ($status, &$urls) {
        $urls[] = $request->url();
        if ($request->url() === BACKUP_AUTH_URL) {
            return Http::response(backupSuccess());
        }
        check($request->url() === PAGE_URL, 'Credentials sent after an unsuccessful page request.');

        return Http::response(TOKEN_PAGE, $status, ['Location' => 'https://example.invalid/']);
    });
    check($authenticator->authenticate($username, $password) === 'success' && $urls === [PAGE_URL, BACKUP_AUTH_URL], 'Unsuccessful page must trigger one backup attempt.');
    $passed++;
}

foreach ([PAGE_URL, AUTH_URL] as $failedUrl) {
    Http::swap(new Factory());
    $urls = [];
    Http::fake(function ($request) use ($failedUrl, &$urls) {
        $urls[] = $request->url();
        if ($request->url() === $failedUrl) {
            throw new ConnectionException('Simulated connection failure');
        }
        if ($request->url() === BACKUP_AUTH_URL) {
            return Http::response(backupSuccess());
        }

        return Http::response(TOKEN_PAGE);
    });
    $expectedUrls = $failedUrl === PAGE_URL ? [PAGE_URL, BACKUP_AUTH_URL] : [PAGE_URL, AUTH_URL, BACKUP_AUTH_URL];
    check($authenticator->authenticate($username, $password) === 'success' && $urls === $expectedUrls, 'Connection errors must trigger one backup attempt.');
    $passed++;
}

$backupCases = [
    'backup success' => [backupSuccess(), 200, 'success'],
    'backup credential rejection' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => 'Access-Reject']]], 200, 'credential'],
    'backup EAP failure' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => 'EAP Failure']]], 200, 'credential'],
    'backup EAP failure event' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => 'EAP authentication failed']]], 200, 'credential'],
    'backup timeout' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => 'EAPOL test timed out']]], 200, 'timeout'],
    'backup success string' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => 'true']]], 200, 'unknown'],
    'backup success number' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => 1]]], 200, 'unknown'],
    'backup missing success' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'output' => SUCCESS_LINE]]], 200, 'unknown'],
    'backup log alone' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => SUCCESS_LINE]]], 200, 'unknown'],
    'unrelated protocol success' => [['results' => [['method' => 'OTHER', 'success' => true]]], 200, 'unknown'],
    'mixed protocol results' => [['results' => [
        ['method' => 'OTHER', 'success' => true],
        ['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => 'Access-Reject'],
    ]], 200, 'credential'],
    'duplicate protocol results' => [['results' => [
        ['method' => 'PEAP_MSCHAPV2', 'success' => true],
        ['method' => 'PEAP_MSCHAPV2', 'success' => false],
    ]], 200, 'unknown'],
    'backup malformed log' => [['results' => [['method' => 'PEAP_MSCHAPV2', 'success' => false, 'output' => []]]], 200, 'unknown'],
    'backup empty results' => [['results' => []], 200, 'unknown'],
    'backup malformed results' => [['results' => 'SUCCESS'], 200, 'unknown'],
    'backup non-object result' => [['results' => ['SUCCESS']], 200, 'unknown'],
    'backup error payload' => [['error' => 'Service unavailable'], 200, 'unknown'],
    'backup invalid JSON' => ['<html>Service Unavailable</html>', 200, 'unknown'],
    'backup null JSON' => ['null', 200, 'unknown'],
    'both routes down' => ['', 503, 'failure'],
    'backup HTTP error with success body' => [backupSuccess(), 500, 'failure'],
    'backup HTTP timeout' => ['', 504, 'timeout'],
    'backup rate limit' => ['', 429, 'failure'],
    'backup redirect' => ['', 302, 'failure'],
];
foreach ($backupCases as $name => [$body, $status, $expected]) {
    Http::swap(new Factory());
    $urls = [];
    Http::fake(function ($request) use ($body, $status, &$urls) {
        $urls[] = $request->url();
        if ($request->url() === PAGE_URL) {
            return Http::response('', 503);
        }
        check($request->url() === BACKUP_AUTH_URL, 'Unexpected URL after primary failure.');

        return Http::response($body, $status);
    });
    check($authenticator->authenticate($username, $password) === $expected, $name.' returned the wrong result.');
    check($urls === [PAGE_URL, BACKUP_AUTH_URL], 'The backup must be attempted exactly once.');
    $passed++;
}

// Use the same Authenticator twice: each attempt starts at PKU, with no cookies
// or CSRF data copied to the independent backup JSON request.
Http::swap(new Factory());
$urls = [];
Http::fake(function ($request, $options) use ($username, $password, &$urls) {
    $urls[] = $request->url();
    if ($request->url() === PAGE_URL) {
        return Http::response(TOKEN_PAGE, 200, ['Set-Cookie' => 'advanced-frontend=primary-session; Path=/; Secure']);
    }
    if ($request->url() === AUTH_URL) {
        return Http::response('', 503);
    }
    check($request->url() === BACKUP_AUTH_URL && $request->method() === 'POST', 'Incorrect backup endpoint or method.');
    check($request->header('Cookie') === [], 'Primary cookies leaked to the backup.');
    check($options['allow_redirects'] === false, 'Backup must not redirect credentials.');
    check($options['connect_timeout'] === 10 && $options['timeout'] === 30, 'Backup timeouts are missing.');
    check(strpos(implode('; ', $request->header('Content-Type')), 'application/json') === 0, 'Backup requires JSON.');
    check($request->data() === ['login' => $username, 'password' => $password], 'Backup credentials were corrupted or CSRF data leaked.');

    return Http::response(backupSuccess());
});
check($authenticator->authenticate($username, $password) === 'success', 'Backup JSON request failed.');
check($authenticator->authenticate($username, $password) === 'success', 'Second failover attempt failed.');
check($urls === [PAGE_URL, AUTH_URL, BACKUP_AUTH_URL, PAGE_URL, AUTH_URL, BACKUP_AUTH_URL], 'Every login must start at PKU.');
$passed++;

Http::swap(new Factory());
$urls = [];
Http::fake(function ($request) use (&$urls) {
    $urls[] = $request->url();
    if ($request->url() === PAGE_URL) {
        return Http::response('', 503);
    }
    check($request->url() === BACKUP_AUTH_URL, 'Unexpected URL when both routes fail.');
    throw new ConnectionException('Simulated backup connection failure');
});
check($authenticator->authenticate($username, $password) === 'failure', 'Backup connection errors must reject login without escaping.');
check($urls === [PAGE_URL, BACKUP_AUTH_URL], 'Do not retry after both routes fail.');
$passed++;

echo "Passed {$passed} authentication checks.\n";
