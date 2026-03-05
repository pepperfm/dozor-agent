<?php

declare(strict_types=1);

namespace Dozor;

use Dozor\Contracts\IngestContract;
use RuntimeException;

final class NullIngest implements IngestContract
{
    public function write(array $record): void
    {
    }

    public function writeNow(array $record): void
    {
    }

    public function flush(): void
    {
    }

    public function ping(): void
    {
        throw new RuntimeException('Dozor ingest is unavailable');
    }

    public function digest(): void
    {
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
    }
}
