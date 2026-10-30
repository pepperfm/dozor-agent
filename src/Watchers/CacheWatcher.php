<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Dozor\Contracts\DozorContract;

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
                'key' => $event->key,
                'store' => $event->storeName,
            ]);
        } catch (\Throwable) {
        }
    }

    public function missed(CacheMissed $event): void
    {
        try {
            $this->core->recordCacheEvent([
                'operation' => 'miss',
                'key' => $event->key,
                'store' => $event->storeName,
            ]);
        } catch (\Throwable) {
        }
    }

    public function written(KeyWritten $event): void
    {
        try {
            $this->core->recordCacheEvent([
                'operation' => 'write',
                'key' => $event->key,
                'store' => $event->storeName,
            ]);
        } catch (\Throwable) {
        }
    }
}
