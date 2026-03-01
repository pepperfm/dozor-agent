<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

final readonly class LogWatcher
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(MessageLogged $event): void
    {
        try {
            $this->core->recordLogMessage(
                level: (string) $event->level,
                message: (string) $event->message,
                context: $event->context,
            );
        } catch (Throwable $e) {
            logger()->warning('dozor.instrumentation.logs.capture_failed', [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'level' => (string) $event->level,
            ]);
        }
    }
}
