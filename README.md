# woyra/woyra-php

The official PHP SDK for the [Woyra](https://woyra.io) email API.

- PHP 8.1+, uses `ext-curl` and `ext-json`, no other dependencies (no Guzzle).
- Retries network errors, `429` and `5xx` automatically: up to 3 retries with exponential backoff and jitter, honouring `Retry-After`. Other `4xx` errors are never retried.
- Sends get an `Idempotency-Key` automatically, and every retry reuses it, so a retried send never becomes a second email.
- Includes a webhook signature verifier.

## Install

```sh
composer require woyra/woyra-php
```

## Quickstart

```php
<?php
require 'vendor/autoload.php';

$woyra = new Woyra\Client('wk_live_...'); // or new Woyra\Client() to read WOYRA_API_KEY

$email = $woyra->emails->send([
    'from' => 'Acme <noreply@yourdomain.com>',
    'to' => 'abebe@example.com',          // a string, or an array of strings
    'subject' => 'Your code',
    'text' => 'Your code is 483920',
]);
echo $email['id'], ' ', $email['status'];
```

`to`, `cc`, `bcc` and `reply_to` each take one address or an array of them, and `Name <address>` is accepted. Responses are decoded associative arrays.

### Options

```php
$woyra = new Woyra\Client('wk_live_...', [
    'base_url' => 'https://kenya.woyra.io', // default https://api.woyra.io (or WOYRA_BASE_URL)
    'max_retries' => 3,                     // retries after the first attempt
    'timeout' => 30.0,                      // per attempt, in seconds
    'api_version' => '2026-09-01',          // sent as Woyra-Version when set
]);
```

## Send with a template

```php
$template = $woyra->templates->create([
    'name' => 'welcome',
    'subject' => 'Welcome, {{name}}',
    'html' => '<p>Hi {{name}}, your plan is {{plan}}.</p>',
]);
print_r($template['variables']); // ['name', 'plan']

$woyra->emails->send([
    'from' => 'noreply@yourdomain.com',
    'to' => 'abebe@example.com',
    'template_id' => $template['id'],
    'variables' => ['name' => 'Abebe', 'plan' => 'Pro'],
    'track_opens' => true,
    'track_clicks' => true,
]);
```

Templates also have `get($id)`, `list(['limit' => 10, 'cursor' => ...])`, `update($id, ['subject' => ...])` and `remove($id)`.

## Batch

Up to 100 emails in one request, all or nothing, under one idempotency key:

```php
$result = $woyra->emails->batch([
    ['from' => 'noreply@yourdomain.com', 'to' => 'a@example.com', 'subject' => 'Digest', 'text' => '...'],
    ['from' => 'noreply@yourdomain.com', 'to' => ['b@example.com', 'c@example.com'], 'subject' => 'Digest', 'text' => '...'],
]);
```

## Schedule and cancel

```php
$scheduled = $woyra->emails->send([
    'from' => 'noreply@yourdomain.com',
    'to' => 'abebe@example.com',
    'subject' => 'Reminder',
    'text' => 'Your appointment is tomorrow.',
    'scheduled_at' => new DateTimeImmutable('+1 day'), // or an RFC 3339 string
]);

$woyra->emails->cancel($scheduled['id']);
```

`emails->get($id)` returns an email with every status it has had; `emails->list(['limit' => 25, 'cursor' => ...])` pages through them.

## Idempotency

A key is generated for you on every `POST`. To make a send safe to repeat across process restarts, derive the key from what the send is about:

```php
$woyra->emails->send($params, ['idempotency_key' => "order-{$order->id}-receipt"]);
```

## Domains and webhook endpoints

```php
$domain = $woyra->domains->create('yourdomain.com');
print_r($domain['records']); // DNS records to publish
$woyra->domains->verify($domain['id']);

$endpoint = $woyra->webhooks->create('https://api.yourapp.com/hooks/woyra', ['email.delivered', 'email.opened', 'email.clicked']);
echo $endpoint['secret']; // shown once: store it
```

Also `domains->list()`, `domains->remove($id)`, `webhooks->list()` and `webhooks->remove($id)`.

## Verify webhooks

Verify the **raw** body, before any JSON decoding. `Webhook::verify` checks the HMAC-SHA256 signature in constant time, rejects timestamps more than 5 minutes away, and returns the decoded event:

```php
use Woyra\Webhook;
use Woyra\WebhookVerificationException;

$payload = file_get_contents('php://input');
try {
    $event = Webhook::verify($payload, getallheaders(), getenv('WOYRA_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}
if ($event['type'] === 'email.delivered') {
    // $event['data']['email_id'] ...
}
http_response_code(204);
```

`$woyra->webhooks->verify(...)` is the same function. Headers may come from `getallheaders()`, `$_SERVER`, or a PSR-7 `$request->getHeaders()`. Deliveries are at-least-once: deduplicate on `$event['id']`.

## Errors

Every error is a `Woyra\WoyraException`:

```php
use Woyra\WoyraException;

try {
    $woyra->emails->send(['from' => 'noreply@yourdomain.com', 'to' => 'not-an-address', 'subject' => 'x', 'text' => 'x']);
} catch (WoyraException $e) {
    echo $e->status;        // 400
    echo $e->errorCode;     // 'invalid_request'  (branch on this)
    echo $e->getMessage();  // '"not-an-address" is not a valid email address.'
    echo $e->param;         // 'to'
    echo $e->requestId;     // 'req_...' (quote it to support)
    echo $e->docUrl;
}
```

Getters (`getStatus()`, `getErrorCode()`, `getParam()`, `getRequestId()`, `getDocUrl()`) are available too. `Woyra\ConnectionException` (`errorCode` `connection_error`, `status` 0) means no response arrived after every retry. `Woyra\WebhookVerificationException` is thrown by webhook verification.

## Development

```sh
php tests/run.php   # or: composer test
```

The runner needs nothing but PHP: it starts a local mock API with `php -S` and runs every test against it.

## License

MIT. Copyright (c) 2026 MINAC GROUP LLC.
