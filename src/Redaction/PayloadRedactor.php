<?php

declare(strict_types=1);

namespace Dozor\Redaction;

use Throwable;

use function array_keys;
use function get_debug_type;
use function get_resource_type;
use function is_bool;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_scalar;
use function is_string;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function strtolower;
use function trim;

final readonly class PayloadRedactor
{
    /**
     * @param array<int, string> $payloadFields
     * @param array<int, string> $headerFields
     */
    public function __construct(
        private array $payloadFields,
        private array $headerFields,
        private int $maxPayloadBytes = 16384,
        private int $truncatePreviewBytes = 2048,
        private string $oversizeBehavior = 'drop',
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function redactPayload(array $payload): array
    {
        return $this->redactByKeys($payload, $this->normalizedPayloadFields());
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public function redactHeaders(array $headers): array
    {
        return $this->redactByKeys($headers, $this->normalizedHeaderFields());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    public function enforcePayloadLimit(array $payload, string $recordType): ?array
    {
        if ($this->maxPayloadBytes <= 0) {
            return $payload;
        }

        $json = $this->encode($payload);
        if ($json === null) {
            return null;
        }

        $size = mb_strlen($json, '8bit');
        if ($size <= $this->maxPayloadBytes) {
            return $payload;
        }

        if ($this->oversizeBehavior === 'truncate') {
            return [
                '_meta' => [
                    'oversize' => true,
                    'record_type' => $recordType,
                    'original_size_bytes' => $size,
                    'max_payload_bytes' => $this->maxPayloadBytes,
                    'behavior' => 'truncate',
                ],
                'preview' => mb_substr($json, 0, $this->truncatePreviewBytes),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $sensitiveKeys
     *
     * @return array<string, mixed>
     */
    private function redactByKeys(array $payload, array $sensitiveKeys): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            $payload[$key] = $this->normalizeValue($value, $sensitiveKeys);
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedPayloadFields(): array
    {
        return $this->normalizeFields($this->payloadFields);
    }

    /**
     * @return array<int, string>
     */
    private function normalizedHeaderFields(): array
    {
        return $this->normalizeFields($this->headerFields);
    }

    /**
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            $name = strtolower(trim($field));
            if ($name !== '') {
                $normalized[] = $name;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): ?string
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (Throwable) {
            return null;
        }

        return is_string($json) ? $json : null;
    }

    private function normalizeValue(mixed $value, array $sensitiveKeys): mixed
    {
        if (is_array($value)) {
            return $this->redactByKeys($value, $sensitiveKeys);
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if ($value instanceof Throwable) {
            return [
                '_type' => $value::class,
                'message' => mb_substr($value->getMessage(), 0, 500),
            ];
        }

        if (is_object($value)) {
            return [
                '_type' => $value::class,
            ];
        }

        if (is_resource($value)) {
            return [
                '_type' => 'resource',
                'resource_type' => get_resource_type($value),
            ];
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return [
            '_type' => get_debug_type($value),
        ];
    }
}
