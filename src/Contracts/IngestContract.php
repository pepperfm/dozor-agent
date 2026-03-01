<?php

declare(strict_types=1);

namespace Dozor\Contracts;

interface IngestContract
{
    /**
     * @param array<string, mixed> $record
     */
    public function write(array $record): void;

    /**
     * @param array<string, mixed> $record
     */
    public function writeNow(array $record): void;

    public function flush(): void;

    public function ping(): void;

    public function digest(): void;

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void;
}
