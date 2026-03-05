<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Throwable;

final readonly class CacheWatcher
{
    public function __construct(private DozorContract $core)
    {
    }

    public function hit(CacheHit $event): void
    {
        try {
            $this->core->recordCacheEvent([
                'operation' => 'hit',
                'key' => (string) $event->key,
                'store' => $event->storeName,
            ]);
        } catch (Throwable) {
        }
    }

    public function missed(CacheMissed $event): void
    {
        try {
            $this->core->recordCacheEvent([
                'operation' => 'miss',
                'key' => (string) $event->key,
                'store' => $event->storeName,
            ]);
        } catch (Throwable) {
        }
    }

    public function written(KeyWritten $event): void
    {
        try {
            $this->core->recordCacheEvent([
                'operation' => 'write',
                'key' => (string) $event->key,
                'store' => $event->storeName,
            ]);
        } catch (Throwable) {
        }
    }
}
