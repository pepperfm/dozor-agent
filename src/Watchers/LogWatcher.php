<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Illuminate\Log\Events\MessageLogged;
use Dozor\Contracts\DozorContract;

final readonly class LogWatcher
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(MessageLogged $event): void
    {
        try {
            $this->core->recordLogMessage(
                level: $event->level,
                message: $event->message,
                context: $event->context,
            );
        } catch (\Throwable) {
        }
    }
}
