<?php

declare(strict_types=1);

// Contract smoke: exercise the exact Payload::json()->pull() code from the existing PHP SDK.
require dirname(__DIR__, 3) . '/src/PayloadType.php';
require dirname(__DIR__, 3) . '/src/Payload.php';

$token = getenv('DOZOR_TEST_TOKEN') ?: 'dozor-go-contract-fixture';
$hash = substr(hash('xxh128', $token), 0, 7);
$address = getenv('DOZOR_INGEST_URI') ?: '127.0.0.1:4816';
$payload = \Dozor\Payload::json([
    ['type' => 'request', 'trace_id' => 'fixture-trace', 'payload' => ['method' => 'GET', 'duration_ms' => 7]],
    ['type' => 'query', 'trace_id' => 'fixture-trace', 'payload' => ['time_ms' => 2]],
], $hash);
$socket = stream_socket_client('tcp://' . $address, $errno, $error, 2);
if (!is_resource($socket)) {
    throw new RuntimeException("TCP connect failed: $error ($errno)");
}
stream_set_timeout($socket, 2);
$frame = $payload->pull();
$written = 0;
while ($written < strlen($frame)) {
    $n = fwrite($socket, substr($frame, $written));
    if ($n === false || $n === 0) {
        throw new RuntimeException('Incomplete test frame write');
    }
    $written += $n;
}
$ack = fread($socket, 4);
fclose($socket);
if ($ack !== '2:OK') {
    throw new RuntimeException('Unexpected ingest ACK: ' . var_export($ack, true));
}
echo "PHP Payload compatibility smoke: OK\n";
