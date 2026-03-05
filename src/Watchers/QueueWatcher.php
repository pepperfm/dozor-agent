<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

final readonly class QueueWatcher
{
    public function __construct(private DozorContract $core)
    {
    }

    public function started(JobProcessing $event): void
    {
        try {
            $this->core->recordJobStarted($event);
        } catch (Throwable) {
        }
    }

    public function finished(JobProcessed|JobFailed $event): void
    {
        try {
            $this->core->recordJobFinished($event);
        } catch (Throwable) {
        }
    }
}
