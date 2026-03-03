<?php

declare(strict_types=1);

namespace Dozor\Context;

final class TraceContext
{
    /**
     * @var array<string, array{started_at: float, index: int}>
     */
    private array $openLifecycleStages = [];

    /**
     * @var array<int, array{name: string, start_offset_ms: int, duration_ms: int, metadata: array<string, mixed>}>
     */
    private array $lifecycleStages = [];

    /**
     * @param array<string, mixed> $requestMeta
     */
    public function __construct(
        public string $traceId,
        public float $startedAt,
        public array $requestMeta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function beginLifecycleStage(string $name, array $metadata = [], ?float $startedAt = null): void
    {
        if ($name === '' || isset($this->openLifecycleStages[$name])) {
            return;
        }

        $startedAt ??= microtime(true);

        $this->lifecycleStages[] = [
            'name' => $name,
            'start_offset_ms' => $this->offsetMs($startedAt),
            'duration_ms' => 0,
            'metadata' => $metadata,
        ];

        $this->openLifecycleStages[$name] = [
            'started_at' => $startedAt,
            'index' => array_key_last($this->lifecycleStages),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function endLifecycleStage(string $name, array $metadata = [], ?float $endedAt = null): void
    {
        $openStage = $this->openLifecycleStages[$name] ?? null;
        if (!is_array($openStage)) {
            return;
        }

        $startedAt = (float) ($openStage['started_at'] ?? microtime(true));
        $index = (int) ($openStage['index'] ?? -1);

        if (!isset($this->lifecycleStages[$index])) {
            unset($this->openLifecycleStages[$name]);

            return;
        }

        $endedAt ??= microtime(true);
        $durationMs = max(1, (int) round(($endedAt - $startedAt) * 1000));
        $existingStage = $this->lifecycleStages[$index];
        $existingMetadata = $existingStage['metadata'] ?? [];

        $this->lifecycleStages[$index]['duration_ms'] = $durationMs;
        $this->lifecycleStages[$index]['metadata'] = array_merge(
            is_array($existingMetadata) ? $existingMetadata : [],
            $metadata,
        );

        unset($this->openLifecycleStages[$name]);
    }

    public function closeOpenLifecycleStages(?float $endedAt = null): void
    {
        $endedAt ??= microtime(true);

        foreach (array_keys($this->openLifecycleStages) as $name) {
            if (!is_string($name)) {
                continue;
            }

            $this->endLifecycleStage($name, endedAt: $endedAt);
        }
    }

    /**
     * @return array<int, array{name: string, start_offset_ms: int, duration_ms: int, metadata: array<string, mixed>}>
     */
    public function lifecycleStages(): array
    {
        return array_values($this->lifecycleStages);
    }

    public function offsetMsAt(?float $timestamp = null): int
    {
        return $this->offsetMs($timestamp ?? microtime(true));
    }

    private function offsetMs(float $timestamp): int
    {
        return max(0, (int) round(($timestamp - $this->startedAt) * 1000));
    }
}
