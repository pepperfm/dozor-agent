<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

final readonly class QueryWatcher
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(QueryExecuted $event): void
    {
        try {
            $this->core->recordQuery($event);
        } catch (Throwable) {
        }
    }
}
