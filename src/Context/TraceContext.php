<?php

declare(strict_types=1);

namespace Dozor\Context;

final class TraceContext
{
    /**
     * @param array<string, mixed> $requestMeta
     */
    public function __construct(
        public string $traceId,
        public float $startedAt,
        public array $requestMeta = [],
    ) {
    }
}
