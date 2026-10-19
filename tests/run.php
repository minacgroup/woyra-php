<?php

declare(strict_types=1);

// A dependency-free test runner: `php tests/run.php`. It starts a local mock
// of the Woyra API with PHP's built-in server and runs every test against it.

require __DIR__ . '/autoload.php';

use Woyra\Client;
use Woyra\ConnectionException;
use Woyra\Webhook;
use Woyra\WebhookVerificationException;
use Woyra\WoyraException;

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // silenced with @
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ---------------------------------------------------------------- harness

final class AssertionFailed extends Exception
{
}

/** @var array<string, callable> $tests */
$tests = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][$name] = $fn;
}

function ok(bool $cond, string $message = 'assertion failed'): void
{
    if (!$cond) {
        throw new AssertionFailed($message);
    }
}

function eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function throws(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("expected $class, nothing was thrown");
}

// ------------------------------------------------------------- mock server

$state = sys_get_temp_dir() . '/woyra-php-test-' . bin2hex(random_bytes(6));
mkdir($state);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = array_merge(getenv(), ['WOYRA_MOCK_STATE' => $state, 'PHP_CLI_SERVER_WORKERS' => '4']);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/server.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    $env,
);
register_shutdown_function(static function () use ($server, $state): void {
    proc_terminate($server);
    proc_close($server);
    foreach (glob($state . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($state);
});
for ($i = 0; $i < 100; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($s) {
        fclose($s);
        break;
    }
    usleep(50_000);
}
define('BASE_URL', "http://127.0.0.1:$port");
define('STATE', $state);
define('UUID', '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

function reset_mock(): void
{
    file_put_contents(STATE . '/queue.json', '[]');
    file_put_contents(STATE . '/requests.json', '[]');
}

function reply(int|string $status, mixed $body = null, array $headers = []): void
{
    $queue = json_decode((string) file_get_contents(STATE . '/queue.json'), true) ?: [];
    $queue[] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    file_put_contents(STATE . '/queue.json', json_encode($queue));
}

/** @return list<array{method: string, path: string, headers: array<string, string>, raw: string, body: mixed}> */
function requests(): array
{
    return json_decode((string) file_get_contents(STATE . '/requests.json'), true) ?: [];
}

function calls(): array
{
    return array_map(static fn ($r) => $r['method'] . ' ' . $r['path'], requests());
}

function client(array $options = []): Client
{
    return new Client('wk_test_123', $options + ['base_url' => BASE_URL, 'retry_base_delay' => 0.001, 'retry_max_delay' => 0.005]);
}

function email(array $over = []): array
{
    return $over + [
        'object' => 'email',
        'id' => 'email_01K0TEST',
        'status' => 'accepted',
        'to' => 'abebe@example.com',
        'from' => 'noreply@yourdomain.com',
        'subject' => 'Hi',
        'created_at' => '2026-10-10T08:00:00Z',
    ];
}

function api_error(string $code, string $message, array $extra = []): array
{
    return ['error' => $extra + ['code' => $code, 'message' => $message, 'doc_url' => "https://docs.woyra.io/errors/$code", 'request_id' => 'req_123']];
}

// ------------------------------------------------------------------ tests

test('sends the bearer token, User-Agent and JSON', function (): void {
    reply(202, email());
    $sent = client()->emails->send(['from' => 'a@yourdomain.com', 'to' => 'abebe@example.com', 'subject' => 'Hi', 'text' => 'Hello']);
    eq('email_01K0TEST', $sent['id']);
    $r = requests()[0];
    eq('POST', $r['method']);
    eq('/v1/emails', $r['path']);
    eq('Bearer wk_test_123', $r['headers']['authorization']);
    eq('woyra-php/' . Client::VERSION, $r['headers']['user-agent']);
    eq('application/json', $r['headers']['content-type']);
});

test('reads the API key and base URL from the environment', function (): void {
    reply(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
    putenv('WOYRA_API_KEY=wk_test_env');
    putenv('WOYRA_BASE_URL=' . BASE_URL);
    try {
        (new Client())->emails->list();
    } finally {
        putenv('WOYRA_API_KEY');
        putenv('WOYRA_BASE_URL');
    }
    eq('Bearer wk_test_env', requests()[0]['headers']['authorization']);
});

test('throws when no API key is available', function (): void {
    putenv('WOYRA_API_KEY');
    $e = throws(WoyraException::class, fn () => new Client());
    eq('authentication_required', $e->errorCode);
});

test('generates an idempotency key and reuses it across a retried 500', function (): void {
    reply(500, api_error('internal_error', 'boom'));
    reply(202, email());
    client()->emails->send(['to' => 'a@example.com', 'from' => 'b@yourdomain.com', 'subject' => 's', 'text' => 't']);
    $r = requests();
    eq(2, count($r));
    $key = $r[0]['headers']['idempotency-key'];
    ok(preg_match(UUID, $key) === 1, "not a UUID: $key");
    eq($key, $r[1]['headers']['idempotency-key']);
});

test('uses a fresh key per logical send', function (): void {
    reply(202, email());
    reply(202, email());
    $c = client();
    $c->emails->send(['to' => 'a@example.com']);
    $c->emails->send(['to' => 'a@example.com']);
    $r = requests();
    ok($r[0]['headers']['idempotency-key'] !== $r[1]['headers']['idempotency-key']);
});

test('honours a caller-supplied idempotency key', function (): void {
    reply(503, api_error('route_unavailable', 'later'));
    reply(202, ['object' => 'list', 'data' => [email()]]);
    client()->emails->batch([['to' => 'a@example.com']], ['idempotency_key' => 'digest-2026-10-09']);
    eq(['digest-2026-10-09', 'digest-2026-10-09'], array_map(static fn ($r) => $r['headers']['idempotency-key'], requests()));
});

test('retries 429 and 5xx, then succeeds', function (): void {
    reply(429, api_error('rate_limited', 'slow down'), ['Retry-After' => '0']);
    reply(502);
    reply(503, api_error('route_unavailable', 'later'));
    reply(202, email());
    $sent = client()->emails->send(['to' => 'a@example.com']);
    eq('email_01K0TEST', $sent['id']);
    eq(4, count(requests()));
});

test('retries a network timeout with the same key', function (): void {
    reply('hang');
    reply(202, email());
    $sent = client(['timeout' => 0.4])->emails->send(['to' => 'a@example.com']);
    eq('email_01K0TEST', $sent['id']);
    $r = requests();
    eq(2, count($r));
    eq($r[0]['headers']['idempotency-key'], $r[1]['headers']['idempotency-key']);
});

test('gives up after three retries with the last error', function (): void {
    for ($i = 0; $i < 5; $i++) {
        reply(500, api_error('internal_error', "boom $i"));
    }
    $e = throws(WoyraException::class, fn () => client()->emails->get('email_1'));
    eq(500, $e->status);
    eq('boom 3', $e->getMessage());
    eq(4, count(requests()));
});

test('throws a ConnectionException when the server is unreachable', function (): void {
    $c = new Client('wk_test_123', ['base_url' => 'http://127.0.0.1:1', 'max_retries' => 1, 'retry_base_delay' => 0.001]);
    $e = throws(ConnectionException::class, fn () => $c->emails->get('email_1'));
    eq('connection_error', $e->errorCode);
    eq(0, $e->status);
});

test('never retries a 400 and exposes typed error fields', function (): void {
    reply(400, api_error('invalid_request', '"nope" is not a valid email address.', ['param' => 'to']));
    reply(202, email());
    $e = throws(WoyraException::class, fn () => client()->emails->send(['to' => 'nope']));
    eq(400, $e->status);
    eq(400, $e->getStatus());
    eq('invalid_request', $e->errorCode);
    eq('"nope" is not a valid email address.', $e->getMessage());
    eq('to', $e->param);
    eq('req_123', $e->requestId);
    eq('https://docs.woyra.io/errors/invalid_request', $e->docUrl);
    eq(1, count(requests()));
});

test('never retries other 4xx', function (): void {
    foreach ([401, 402, 403, 404, 409] as $status) {
        reset_mock();
        reply($status, api_error('x', 'no'));
        $e = throws(WoyraException::class, fn () => client()->emails->get('email_1'));
        eq($status, $e->status);
        eq(1, count(requests()), "status $status");
    }
});

test('falls back to the Woyra-Request-Id header for a non-JSON error', function (): void {
    reply(404, null, ['Woyra-Request-Id' => 'req_hdr']);
    $e = throws(WoyraException::class, fn () => client()->emails->get('email_1'));
    eq('unknown_error', $e->errorCode);
    eq('req_hdr', $e->requestId);
});

test('parses Retry-After seconds and dates', function (): void {
    eq(2.0, Client::parseRetryAfter('2'));
    eq(null, Client::parseRetryAfter(null));
    eq(3.0, Client::parseRetryAfter('Thu, 01 Jan 1970 00:00:10 GMT', 7));
});

test('passes string and array addresses through unchanged', function (): void {
    reply(202, email());
    client()->emails->send([
        'from' => 'Woyra <noreply@yourdomain.com>',
        'to' => 'abebe@example.com',
        'cc' => ['a@example.com', 'b@example.com'],
        'bcc' => 'audit@yourdomain.com',
        'reply_to' => ['support@yourdomain.com'],
        'subject' => 'Hi',
        'html' => '<p>Hi</p>',
    ]);
    $b = requests()[0]['body'];
    eq('abebe@example.com', $b['to']);
    eq(['a@example.com', 'b@example.com'], $b['cc']);
    eq('audit@yourdomain.com', $b['bcc']);
    eq(['support@yourdomain.com'], $b['reply_to']);
});

test('sends a template with variables and tracking flags', function (): void {
    reply(202, email());
    $params = [
        'from' => 'noreply@yourdomain.com',
        'to' => 'abebe@example.com',
        'template_id' => 'tmpl_123',
        'variables' => ['name' => 'Abebe'],
        'track_opens' => true,
        'track_clicks' => false,
    ];
    client()->emails->send($params);
    eq($params, requests()[0]['body']);
});

test('encodes empty object fields as JSON objects', function (): void {
    reply(202, email());
    client()->emails->send(['to' => 'a@example.com', 'template_id' => 'tmpl_1', 'variables' => [], 'metadata' => []]);
    ok(str_contains(requests()[0]['raw'], '"variables":{}'), requests()[0]['raw']);
    ok(str_contains(requests()[0]['raw'], '"metadata":{}'), requests()[0]['raw']);
});

test('serialises a DateTime scheduled_at and cancels', function (): void {
    reply(202, email(['status' => 'scheduled']));
    reply(200, email(['status' => 'canceled']));
    $c = client();
    $when = new DateTimeImmutable('2026-10-11 11:00:00', new DateTimeZone('Africa/Addis_Ababa'));
    $sent = $c->emails->send(['to' => 'a@example.com', 'scheduled_at' => $when]);
    $canceled = $c->emails->cancel($sent['id']);
    eq('2026-10-11T08:00:00Z', requests()[0]['body']['scheduled_at']);
    eq('POST /v1/emails/email_01K0TEST/cancel', calls()[1]);
    eq('canceled', $canceled['status']);
});

test('batch posts a JSON array', function (): void {
    reply(202, ['object' => 'list', 'data' => [email(), email(['id' => 'email_2'])]]);
    $res = client()->emails->batch([['to' => 'a@example.com'], ['to' => ['b@example.com', 'c@example.com']]]);
    $r = requests()[0];
    eq('/v1/emails/batch', $r['path']);
    ok(str_starts_with($r['raw'], '['), 'body is not an array');
    eq(['b@example.com', 'c@example.com'], $r['body'][1]['to']);
    ok(preg_match(UUID, $r['headers']['idempotency-key']) === 1);
    eq(2, count($res['data']));
});

test('get and list', function (): void {
    reply(200, email());
    reply(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
    $c = client();
    $c->emails->get('email/../x');
    $c->emails->list(['limit' => 10, 'cursor' => 'email_9']);
    $r = requests();
    eq('/v1/emails/email%2F..%2Fx', $r[0]['path']);
    ok(!isset($r[0]['headers']['idempotency-key']), 'GET carried an idempotency key');
    eq('/v1/emails?limit=10&cursor=email_9', $r[1]['path']);
});

test('template CRUD', function (): void {
    $tmpl = ['object' => 'template', 'id' => 'tmpl_1', 'name' => 'welcome', 'subject' => 'Hi {{name}}', 'variables' => ['name']];
    reply(201, $tmpl);
    reply(200, $tmpl);
    reply(200, ['object' => 'list', 'data' => [$tmpl], 'has_more' => false]);
    reply(200, $tmpl);
    reply(204);
    $c = client();
    $created = $c->templates->create(['name' => 'welcome', 'subject' => 'Hi {{name}}', 'html' => '<p>Hi {{name}}</p>']);
    eq(['name'], $created['variables']);
    $c->templates->get('tmpl_1');
    $c->templates->list(['limit' => 5]);
    $c->templates->update('tmpl_1', ['subject' => 'Hello {{name}}']);
    $c->templates->remove('tmpl_1');
    eq(['POST /v1/templates', 'GET /v1/templates/tmpl_1', 'GET /v1/templates?limit=5', 'PATCH /v1/templates/tmpl_1', 'DELETE /v1/templates/tmpl_1'], calls());
    eq(['subject' => 'Hello {{name}}'], requests()[3]['body']);
});

test('domains', function (): void {
    reply(201, ['object' => 'domain', 'id' => 'dom_1', 'status' => 'pending']);
    reply(200, ['object' => 'list', 'data' => []]);
    reply(200, ['object' => 'domain', 'id' => 'dom_1', 'status' => 'verified']);
    reply(204);
    $c = client();
    $c->domains->create('example.com');
    $c->domains->list();
    eq('verified', $c->domains->verify('dom_1')['status']);
    $c->domains->remove('dom_1');
    eq(['domain' => 'example.com'], requests()[0]['body']);
    eq(['POST /v1/domains', 'GET /v1/domains', 'POST /v1/domains/dom_1/verify', 'DELETE /v1/domains/dom_1'], calls());
});

test('webhook endpoints', function (): void {
    reply(201, ['object' => 'webhook_endpoint', 'id' => 'we_1', 'secret' => 'whsec_1']);
    reply(200, ['object' => 'list', 'data' => [], 'has_more' => false]);
    reply(204);
    $c = client();
    $ep = $c->webhooks->create('https://x.test/hook', ['email.opened', 'email.clicked']);
    $c->webhooks->list(['cursor' => 'we_0']);
    $c->webhooks->remove('we_1');
    eq('whsec_1', $ep['secret']);
    eq(['url' => 'https://x.test/hook', 'events' => ['email.opened', 'email.clicked']], requests()[0]['body']);
    eq(['POST /v1/webhook_endpoints', 'GET /v1/webhook_endpoints?cursor=we_0', 'DELETE /v1/webhook_endpoints/we_1'], calls());
});

// Reference vector produced by backend/internal/webhooks/signature.go's Sign.
const SECRET = 'whsec_test_secret';
const TS = 1764547200;
const BODY = '{"id":"evt_01K0TEST","type":"email.delivered","created_at":"2025-12-01T00:00:00Z","data":{"email_id":"email_01K0TEST"}}';
const SIG = 'v1=f81611964c3b234625bec19c8c51e960aabced3721dc6b6698be5354fda35f8a';

/** signature.go's algorithm, written out independently of the SDK. */
function reference(string $secret, int $ts, string $body): string
{
    return 'v1=' . bin2hex(hash_hmac('sha256', $ts . '.' . $body, $secret, true));
}

function wh(string $sig, int|string $ts): array
{
    return ['Woyra-Signature' => $sig, 'Woyra-Timestamp' => (string) $ts];
}

function rejected(string $code, callable $fn): void
{
    $e = throws(WebhookVerificationException::class, $fn);
    eq($code, $e->errorCode);
}

test('webhook: reference implementation matches the Go vector', function (): void {
    eq(SIG, reference(SECRET, TS, BODY));
    eq(SIG, Webhook::sign(SECRET, TS, BODY));
});

test('webhook: accepts the backend vector and returns the event', function (): void {
    $event = Webhook::verify(BODY, wh(SIG, TS), SECRET, 300, TS + 10);
    eq('evt_01K0TEST', $event['id']);
    eq('email.delivered', $event['type']);
    eq('email_01K0TEST', $event['data']['email_id']);
});

test('webhook: accepts a fresh signature via $client->webhooks, $_SERVER and PSR-7 style headers', function (): void {
    $now = time();
    $body = json_encode(['id' => 'evt_2', 'type' => 'email.opened', 'created_at' => 'x', 'data' => new stdClass()]);
    $server = ['HTTP_WOYRA_SIGNATURE' => reference(SECRET, $now, $body), 'HTTP_WOYRA_TIMESTAMP' => (string) $now];
    eq('email.opened', client()->webhooks->verify($body, $server, SECRET)['type']);
    $psr7 = ['woyra-signature' => [reference(SECRET, $now, $body)], 'woyra-timestamp' => [(string) $now]];
    eq('evt_2', Webhook::verify($body, $psr7, SECRET)['id']);
});

test('webhook: rejects a tampered body', function (): void {
    rejected('invalid_signature', fn () => Webhook::verify(str_replace('delivered', 'failed', BODY), wh(SIG, TS), SECRET, 300, TS));
});

test('webhook: rejects the wrong secret and an unprefixed signature', function (): void {
    rejected('invalid_signature', fn () => Webhook::verify(BODY, wh(SIG, TS), 'whsec_other', 300, TS));
    rejected('invalid_signature', fn () => Webhook::verify(BODY, wh(substr(SIG, 3), TS), SECRET, 300, TS));
});

test('webhook: rejects stale and far-future timestamps', function (): void {
    ok(Webhook::verify(BODY, wh(SIG, TS), SECRET, 300, TS + 300) !== []);
    rejected('signature_expired', fn () => Webhook::verify(BODY, wh(SIG, TS), SECRET, 300, TS + 301));
    rejected('signature_expired', fn () => Webhook::verify(BODY, wh(SIG, TS), SECRET, 300, TS - 301));
    $stale = time() - 600;
    rejected('signature_expired', fn () => Webhook::verify(BODY, wh(reference(SECRET, $stale, BODY), $stale), SECRET));
});

test('webhook: rejects missing headers and a bad timestamp', function (): void {
    rejected('missing_header', fn () => Webhook::verify(BODY, [], SECRET));
    rejected('invalid_timestamp', fn () => Webhook::verify(BODY, wh(SIG, 'soon'), SECRET));
    rejected('missing_secret', fn () => Webhook::verify(BODY, wh(SIG, TS), ''));
});

test('webhook: accepts one match among several signatures', function (): void {
    ok(Webhook::verify(BODY, wh('v1=' . str_repeat('0', 64) . ', ' . SIG, TS), SECRET, 300, TS) !== []);
});

// ------------------------------------------------------------------- run

$failed = 0;
foreach ($tests as $name => $fn) {
    reset_mock();
    try {
        $fn();
        echo "ok   - $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL - $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
$total = count($tests);
echo "\n" . ($total - $failed) . " passed, $failed failed, $total total\n";
exit($failed === 0 ? 0 : 1);
