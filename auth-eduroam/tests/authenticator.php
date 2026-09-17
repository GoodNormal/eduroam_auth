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
const SUCCESS_LINE = 'CTRL-EVENT-EAP-SUCCESS EAP authentication completed successfully';
const TOKEN_PAGE = '<html><head><meta content="csrf+token&amp;value=" name="csrf-token"></head></html>';

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function authenticateWithResponse($body, int $status = 200): string
{
    Http::swap(new Factory());
    Http::fake(function ($request) use ($body, $status) {
        if ($request->url() === PAGE_URL) {
            return Http::response(TOKEN_PAGE);
        }
        check($request->url() === AUTH_URL, 'Unexpected remote URL.');

        return Http::response($body, $status);
    });

    return (new Authenticator())->authenticate('user@example.invalid', 'dummy-password');
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
    check(authenticateWithResponse($body, $status) === $expected, $name.' returned the wrong result.');
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
    $count = 0;
    Http::fake(function ($request) use ($page, &$count) {
        $count++;
        check($request->url() === PAGE_URL, 'Credentials sent without a CSRF token.');

        return Http::response($page);
    });
    check($authenticator->authenticate($username, $password) === 'failure' && $count === 1, 'Missing token must stop the login.');
    $passed++;
}

foreach ([302, 500] as $status) {
    Http::swap(new Factory());
    Http::fake(function ($request) use ($status) {
        check($request->url() === PAGE_URL, 'Credentials sent after an unsuccessful page request.');

        return Http::response(TOKEN_PAGE, $status, ['Location' => 'https://example.invalid/']);
    });
    check($authenticator->authenticate($username, $password) === 'failure', 'Unsuccessful page must stop the login.');
    $passed++;
}

foreach ([PAGE_URL, AUTH_URL] as $failedUrl) {
    Http::swap(new Factory());
    Http::fake(function ($request) use ($failedUrl) {
        if ($request->url() === $failedUrl) {
            throw new ConnectionException('Simulated connection failure');
        }

        return Http::response(TOKEN_PAGE);
    });
    check($authenticator->authenticate($username, $password) === 'failure', 'Connection errors must reject login without escaping.');
    $passed++;
}

echo "Passed {$passed} authentication checks.\n";
