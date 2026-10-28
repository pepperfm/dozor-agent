<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Collects only framework lifecycle metadata, never mail content,
 * notification payloads, console arguments, or scheduler command strings.
 */
final class FrameworkWatcher
{
    /** @var array<string, float> */
    private array $commandStartedAt = [];

    public function __construct(private readonly DozorContract $core)
    {
    }

    public function commandStarting(CommandStarting $event): void
    {
        $name = $event->command;
        if (!is_string($name) || $name === '' || str_starts_with($name, 'dozor:')) {
            return;
        }

        $this->commandStartedAt[$name] = microtime(true);
        try {
            $this->core->startFrameworkCommand($name);
        } catch (Throwable) {
        }
    }

    public function commandFinished(CommandFinished $event): void
    {
        $name = $event->command;
        if (!is_string($name) || $name === '' || str_starts_with($name, 'dozor:')) {
            return;
        }

        $start = $this->commandStartedAt[$name] ?? null;
        unset($this->commandStartedAt[$name]);

        try {
            $this->record('artisan.' . $name, [
                'command' => $name,
                'exit_code' => (int) $event->exitCode,
                'failed' => (int) $event->exitCode !== 0,
                'duration_ms' => is_float($start) ? round((microtime(true) - $start) * 1000, 2) : null,
            ]);
        } finally {
            try {
                $this->core->finishFrameworkCommand($name);
            } catch (Throwable) {
            }
        }
    }

    public function scheduledFinished(ScheduledTaskFinished $event): void
    {
        $this->record('schedule.finished', [
            'task_type' => $event->task::class,
            'duration_ms' => max(0, (float) $event->runtime * 1000),
        ]);
    }

    public function scheduledFailed(ScheduledTaskFailed $event): void
    {
        $this->record('schedule.failed', [
            'task_type' => $event->task::class,
            'failed' => true,
            'exception_class' => $event->exception::class,
        ]);
    }

    public function scheduledSkipped(ScheduledTaskSkipped $event): void
    {
        $this->record('schedule.skipped', [
            'task_type' => $event->task::class,
        ]);
    }

    public function messageSent(MessageSent $event): void
    {
        $this->record('mail.sent', [
            'message_type' => $event->message::class,
        ]);
    }

    public function notificationSent(NotificationSent $event): void
    {
        $this->record('notification.sent', [
            'notification' => $event->notification::class,
            'channel' => (string) $event->channel,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function record(string $name, array $data): void
    {
        try {
            $this->core->recordFrameworkEvent($name, $data);
        } catch (Throwable) {
            // Monitoring must never change application execution.
        }
    }
}
