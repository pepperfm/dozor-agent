<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Dozor\Contracts\DozorContract;

final readonly class QueueWatcher
{
    public function __construct(private DozorContract $core)
    {
    }

    public function looping(Looping $event): void
    {
        try {
            if ($this->core->recordQueueLoop($event)) {
                $this->core->digest();
            }
        } catch (\Throwable) {
        }
    }

    public function started(JobProcessing $event): void
    {
        try {
            $this->core->recordJobStarted($event);
        } catch (\Throwable) {
        }
    }

    public function finished(JobProcessed|JobFailed $event): void
    {
        try {
            $this->core->recordJobFinished($event);
            $this->core->digest();
        } catch (\Throwable) {
        }
    }

    public function released(JobReleasedAfterException $event): void
    {
        try {
            $this->core->recordJobReleased($event);
            $this->core->digest();
        } catch (\Throwable) {
        }
    }

    public function attempted(JobAttempted $event): void
    {
        try {
            $this->core->noteJobAttempt($event);
        } catch (\Throwable) {
        }
    }

    public function workerBoundary(): void
    {
        try {
            $this->core->resetQueueWorker();
        } catch (\Throwable) {
        }
    }
}
