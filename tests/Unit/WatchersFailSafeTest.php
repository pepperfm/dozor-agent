<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\DozorContract;
use Dozor\Tests\TestCase;
use Dozor\Watchers\CacheWatcher;
use Dozor\Watchers\QueryWatcher;
use Dozor\Watchers\QueueWatcher;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use RuntimeException;

final class WatchersFailSafeTest extends TestCase
{
    public function test_watchers_swallow_core_exceptions(): void
    {
        $core = $this->createStub(DozorContract::class);
        $core->method('recordQuery')->willThrowException(new RuntimeException('recordQuery failed'));
        $core->method('recordJobStarted')->willThrowException(new RuntimeException('recordJobStarted failed'));
        $core->method('recordJobFinished')->willThrowException(new RuntimeException('recordJobFinished failed'));
        $core->method('recordCacheEvent')->willThrowException(new RuntimeException('recordCacheEvent failed'));

        $queryWatcher = new QueryWatcher($core);
        $queueWatcher = new QueueWatcher($core);
        $cacheWatcher = new CacheWatcher($core);

        $queryWatcher($this->createStub(QueryExecuted::class));
        $queueWatcher->started($this->createStub(JobProcessing::class));
        $queueWatcher->finished($this->createStub(JobProcessed::class));
        $queueWatcher->finished($this->createStub(JobFailed::class));
        $cacheWatcher->hit(new CacheHit('array', 'cache-hit-key', 'value'));
        $cacheWatcher->missed(new CacheMissed('array', 'cache-miss-key'));
        $cacheWatcher->written(new KeyWritten('array', 'cache-write-key', 'value'));

        self::assertTrue(true);
    }
}
