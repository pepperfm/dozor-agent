<?php

declare(strict_types=1);

namespace Dozor;

use Countable;

/**
 * @internal
 */
final class RecordsBuffer implements Countable
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $records = [];

    public bool $full = false;

    public function __construct(private readonly int $length)
    {
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        if ($this->full) {
            \array_shift($this->records);
        }

        $this->records[] = $record;

        $this->full = $this->count() >= $this->length;
    }

    public function count(): int
    {
        return \count($this->records);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function pull(string $tokenHash): Payload
    {
        if ($this->records === []) {
            return Payload::json([], $tokenHash);
        }

        $records = $this->records;

        $this->flush();

        return Payload::json($records, $tokenHash);
    }

    public function flush(): void
    {
        $this->records = [];
        $this->full = false;
    }
}
