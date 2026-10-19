<?php

declare(strict_types=1);

// Router for `php -S`: a local mock of the Woyra API. The test process queues
// responses in $WOYRA_MOCK_STATE/queue.json; every request received is
// appended to $WOYRA_MOCK_STATE/requests.json.

$dir = getenv('WOYRA_MOCK_STATE');
$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);
$queue = json_decode((string) @file_get_contents($dir . '/queue.json'), true) ?: [];
$requests = json_decode((string) @file_get_contents($dir . '/requests.json'), true) ?: [];

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
$raw = (string) file_get_contents('php://input');
$requests[] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'raw' => $raw,
    'body' => $raw === '' ? null : json_decode($raw, true),
];
$next = array_shift($queue) ?? ['status' => 200, 'body' => new stdClass(), 'headers' => []];
file_put_contents($dir . '/queue.json', json_encode($queue));
file_put_contents($dir . '/requests.json', json_encode($requests));
flock($lock, LOCK_UN);

if ($next['status'] === 'hang') {
    // Outlast the client's timeout, so it sees a network error.
    usleep(1_500_000);
    $next = ['status' => 200, 'body' => new stdClass(), 'headers' => []];
}

http_response_code((int) $next['status']);
header('Content-Type: application/json');
foreach ($next['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
if (($next['body'] ?? null) !== null) {
    echo json_encode($next['body']);
}
return true;
