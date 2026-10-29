<?php

declare(strict_types=1);

/**
 * Сопоставимый с Go тест TCP ACK. Запускать отдельно с уже работающим
 * PHP daemon на свободном тестовом порту и отдельной spool-директорией.
 */
require dirname(__DIR__, 3) . '/src/PayloadType.php';
require dirname(__DIR__, 3) . '/src/Payload.php';

$address = getenv('DOZOR_INGEST_URI') ?: '127.0.0.1:4815';
$token = getenv('DOZOR_TOKEN') ?: 'benchmark';
$count = max(1, (int) ($argv[1] ?? 1000));
$latencies = [];

for ($i = 0; $i < $count; $i++) {
    $payload = \Dozor\Payload::json([
        ['type' => 'request', 'trace_id' => '01J00000000000000000000001', 'payload' => ['duration_ms' => 2]],
    ], substr(hash('xxh128', $token), 0, 7));
    $started = hrtime(true);
    $socket = stream_socket_client('tcp://' . $address, $errorCode, $error, 3);
    if (!is_resource($socket)) {
        throw new RuntimeException("Socket unavailable: $error");
    }
    stream_set_timeout($socket, 5);
    $frame = $payload->pull();
    $written = 0;
    while ($written < strlen($frame)) {
        $size = fwrite($socket, substr($frame, $written));
        if ($size === false || $size === 0) {
            throw new RuntimeException('Incomplete frame write');
        }
        $written += $size;
    }
    $ack = fread($socket, 4);
    fclose($socket);
    if ($ack !== '2:OK') {
        throw new RuntimeException('ACK rejected: ' . $ack);
    }
    $latencies[] = (hrtime(true) - $started) / 1_000_000;
}
sort($latencies, SORT_NUMERIC);
$p = static fn (float $fraction): float => $latencies[min($count - 1, (int) ($count * $fraction))];
printf(
    "n=%d p50=%.3fms p95=%.3fms p99=%.3fms avg=%.3fms\n",
    $count, $p(0.5), $p(0.95), $p(0.99), array_sum($latencies) / $count
);
