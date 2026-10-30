<?php

declare(strict_types=1);

/**
 * @param resource $stream
 * @param (callable(): string)|string $payload
 */
function fwrite_all($stream, callable|string $payload): void
{
    $payload = is_callable($payload) ? $payload() : $payload;
    $originalPayloadLength = strlen($payload);
    $written = 0;

    while (true) {
        $thisWrite = fwrite($stream, $payload);
        if ($thisWrite === false) {
            throw runtime_exception_with_steam_meta('Unable to write to stream', $stream);
        }

        $written += $thisWrite;
        if ($written >= $originalPayloadLength) {
            return;
        }

        $payload = substr($payload, $thisWrite);
    }
}

/**
 * @param resource $stream
 * @param int<1, max> $length
 */
function fread_all($stream, int $length): string
{
    $content = '';
    while (strlen($content) < $length) {
        $remaining = $length - strlen($content);
        $thisRead = fread($stream, $remaining);

        if ($thisRead === false) {
            throw runtime_exception_with_steam_meta('Unable to read from stream', $stream);
        }
        if ($thisRead === '') {
            if (feof($stream)) {
                break;
            }

            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                throw $content
                    |> strlen(...)
                    |> (static fn($x) => sprintf('Read timed out before receiving full frame [expected: %d bytes, received: %d bytes]', $length, $x,))
                    |> (static fn($x) => runtime_exception_with_steam_meta($x, $stream,));
            }

            continue;
        }

        $content .= $thisRead;
    }

    if (strlen($content) < $length) {
        throw $content
            |> strlen(...)
            |> (static fn($x) => sprintf('Incomplete stream read [expected: %d bytes, received: %d bytes]', $length, $x,))
            |> (static fn($x) => runtime_exception_with_steam_meta($x, $stream,));
    }

    return $content;
}

/**
 * @param resource $stream
 */
function stream_get_formatted_meta_data($stream): string
{
    if (stream_closed($stream)) {
        return 'closed: true';
    }

    $meta = stream_get_meta_data($stream);

    return array_reduce(array_keys($meta), static function ($carry, $key) use ($meta) {
        try {
            return $carry . $key . ': ' . match ($meta[$key]) {
                true => 'true',
                false => 'false',
                default => $meta[$key],
            } . PHP_EOL;
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return $carry;
        }
    }, '');
}

/**
 * @param resource $stream
 */
function fclose_safely($stream): void
{
    try {
        if (!stream_closed($stream)) {
            fclose($stream);
        }
    } catch (\Throwable) {
        //
    }
}

/**
 * @param resource $stream
 */
function stream_closed($stream): bool
{
    return gettype($stream) === 'resource (closed)';
}

/**
 * @param resource $stream
 */
function runtime_exception_with_steam_meta(string $message, $stream): \RuntimeException
{
    return new \RuntimeException($message . PHP_EOL . '---' . PHP_EOL . stream_get_formatted_meta_data($stream));
}

/**
 * @param resource $stream
 */
function stream_configure_read_timeout($stream, float $timeout): void
{
    $timeoutVal = [
        'seconds' => $seconds = (int) $timeout,
        'microseconds' => (int) (($timeout - $seconds) * 1_000_000),
    ];

    $timeoutConfigured = stream_set_timeout(
        $stream,
        $timeoutVal['seconds'],
        $timeoutVal['microseconds'],
    );

    if ($timeoutConfigured === false) {
        throw runtime_exception_with_steam_meta('Failed configuring agent read timeout', $stream);
    }
}
