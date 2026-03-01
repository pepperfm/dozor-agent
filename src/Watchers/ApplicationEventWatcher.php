<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Throwable;

use function get_debug_type;
use function is_array;
use function is_object;
use function is_scalar;
use function mb_substr;
use function str_starts_with;

final readonly class ApplicationEventWatcher
{
    /**
     * @param array<int, string> $allowedPrefixes
     * @param array<int, string> $ignoredEvents
     */
    public function __construct(
        private DozorContract $core,
        private array $allowedPrefixes = [],
        private array $ignoredEvents = [],
    ) {
    }

    /**
     * @param array<int, mixed> $data
     */
    public function handle(string $eventName, array $data = []): void
    {
        if ($this->isIgnored($eventName) || !$this->isAllowed($eventName)) {
            return;
        }

        $payload = [
            'event_name' => $eventName,
            'items_count' => count($data),
            'items' => [],
        ];

        foreach ($data as $item) {
            if (is_object($item)) {
                $payload['items'][] = [
                    'type' => $item::class,
                ];

                continue;
            }

            if (is_array($item)) {
                $payload['items'][] = [
                    'type' => 'array',
                    'keys' => array_slice(array_keys($item), 0, 12),
                ];

                continue;
            }

            if (is_scalar($item)) {
                $payload['items'][] = [
                    'type' => get_debug_type($item),
                    'value' => mb_substr((string) $item, 0, 255),
                ];

                continue;
            }

            $payload['items'][] = [
                'type' => get_debug_type($item),
            ];
        }

        try {
            $this->core->recordApplicationEvent($eventName, $payload);
        } catch (Throwable $e) {
            logger()->warning('dozor.instrumentation.events.capture_failed', [
                'event' => $eventName,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function isAllowed(string $eventName): bool
    {
        if ($this->allowedPrefixes === []) {
            return false;
        }

        foreach ($this->allowedPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($eventName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnored(string $eventName): bool
    {
        foreach ($this->ignoredEvents as $ignoredEvent) {
            if ($ignoredEvent !== '' && $eventName === $ignoredEvent) {
                return true;
            }
        }

        return false;
    }
}
