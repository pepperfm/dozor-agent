<?php

declare(strict_types=1);

namespace Dozor\Console;

use Dozor\Agent\SpoolQueue;
use Dozor\Telemetry\AgentRuntimeState;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Optional, read-only live view of the agent's own pipeline.
 *
 * Never prints telemetry payloads, headers, tokens, request paths or exception
 * messages. Without a TTY, --debug becomes an append-only log stream.
 */
final class AgentDebugDashboard
{
    private const CYAN = "\033[38;5;81m";
    private const BLUE = "\033[38;5;67m";
    private const GREEN = "\033[38;5;84m";
    private const YELLOW = "\033[38;5;221m";
    private const RED = "\033[38;5;203m";
    private const DIM = "\033[38;5;245m";
    private const BOLD = "\033[1m";
    private const RESET = "\033[0m";

    /** @var list<array{time: string, level: string, message: string}> */
    private array $events = [];

    private readonly bool $interactive;

    private bool $started = false;

    private float $startedAt = 0.0;

    private float $lastRenderAt = 0.0;

    private int $receivedRecords = 0;

    private int $receivedBatches = 0;

    private int $queuedBatches = 0;

    private int $deliveredBatches = 0;

    private int $uploadFailures = 0;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly AgentRuntimeState $runtimeState,
        private readonly ?SpoolQueue $spoolQueue,
        private readonly string $appName,
        private readonly string $serverName,
        private readonly string $environment,
        private readonly string $listenOn,
        private readonly bool $shippingEnabled,
        ?bool $interactive = null,
    ) {
        $this->interactive = $interactive ?? $this->supportsTui();
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->startedAt = microtime(true);

        if ($this->interactive) {
            $this->write("\033[?1049h\033[?25l");
        } else {
            $this->write("DOZOR / AGENT   DEBUG STREAM\n");
        }

        $this->event('info', 'Local ingest socket listening');
        $this->event(
            $this->shippingEnabled ? 'success' : 'warning',
            $this->shippingEnabled ? 'Remote shipping enabled' : 'Remote shipping disabled; storing locally',
        );
        $this->render(force: true);
    }

    public function received(int $records): void
    {
        $this->receivedRecords += $records;
        $this->receivedBatches++;
        $this->event('receive', sprintf('Accepted %d telemetry record(s)', $records));
    }

    public function queued(int $records): void
    {
        $this->queuedBatches++;
        $this->event('queue', sprintf('Durable batch queued (%d source records)', $records));
    }

    public function shipped(bool $success, int $attempt): void
    {
        if ($success) {
            $this->deliveredBatches++;
            $this->event('success', sprintf('Remote batch delivered (attempt %d)', $attempt));

            return;
        }

        $this->uploadFailures++;
        $this->event('error', sprintf('Remote upload failed (attempt %d)', $attempt));
    }

    public function heartbeat(): void
    {
        $this->event('info', 'Heartbeat queued');
    }

    public function flush(int $depth, bool $forced): void
    {
        if ($depth > 0) {
            $this->event('queue', sprintf('Flushing %d queued batch(es)%s', $depth, $forced ? ' (forced)' : ''));
        }
    }

    public function error(string $message): void
    {
        $this->event('error', $message);
    }

    public function shutdown(string $signal): void
    {
        $this->event('warning', sprintf('Shutdown requested (%s); draining spool', $signal));
    }

    public function tick(): void
    {
        $this->render();
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        try {
            $this->event('info', 'Agent stopped');
            $this->render(force: true);
        } finally {
            if ($this->interactive) {
                $this->write("\033[?25h\033[?1049l");
            }

            $this->started = false;
        }
    }

    private function event(string $level, string $message): void
    {
        $entry = [
            'time' => date('H:i:s'),
            'level' => $level,
            'message' => $this->sanitize($message),
        ];

        $this->events[] = $entry;

        if (count($this->events) > 160) {
            array_shift($this->events);
        }

        if (!$this->interactive) {
            $this->write(sprintf("[%s] %-7s %s\n", $entry['time'], strtoupper($level), $entry['message']));
        } else {
            $this->render();
        }
    }

    private function render(bool $force = false): void
    {
        if (!$this->interactive || !$this->started) {
            return;
        }

        $now = microtime(true);
        if (!$force && $now - $this->lastRenderAt < 0.35) {
            return;
        }

        $this->lastRenderAt = $now;
        $terminal = new Terminal();
        $width = max(42, min(112, $terminal->getWidth() - 2));
        $height = max(12, $terminal->getHeight());
        $inner = $width - 2;
        $state = $this->runtimeState->read();
        $metrics = (array) ($state['metrics'] ?? []);
        $queue = $this->spoolQueue?->queuedBatchesCount() ?? 0;
        $failed = $this->spoolQueue?->failedBatchesCount() ?? 0;
        $uptime = (int) max(0, $now - $this->startedAt);
        $status = $this->shippingEnabled ? self::GREEN . 'REMOTE ON' : self::YELLOW . 'LOCAL ONLY';
        $top = self::BLUE . '╭' . str_repeat('─', $inner) . '╮' . self::RESET . "\n";
        $mid = self::BLUE . '├' . str_repeat('─', $inner) . '┤' . self::RESET . "\n";
        $bottom = self::BLUE . '╰' . str_repeat('─', $inner) . '╯' . self::RESET . "\n";
        $line = fn (string $text): string => $this->frameLine($text, $inner);

        $screen = $top;
        $screen .= $line('  ' . self::CYAN . self::BOLD . 'DOZOR' . self::RESET . '  /  TELEMETRY AGENT' . str_repeat(' ', 3) . self::GREEN . '● LIVE' . self::RESET);
        $screen .= $mid;
        $screen .= $line('  ' . self::DIM . 'APP' . self::RESET . '       ' . $this->sanitize($this->appName) . '   /   ' . $this->sanitize($this->environment));
        $screen .= $line('  ' . self::DIM . 'SERVER' . self::RESET . '    ' . $this->sanitize($this->serverName));
        $screen .= $line('  ' . self::DIM . 'LISTEN' . self::RESET . '    ' . $this->sanitize($this->listenOn));
        $screen .= $line('  ' . self::DIM . 'UPLINK' . self::RESET . '    ' . $status . self::RESET);
        $screen .= $mid;
        $screen .= $line(sprintf('  %-19s %-18s %s', 'RECEIVED', 'DELIVERED', 'UPTIME'));
        $screen .= $line(sprintf(
            '  %s%-19s%s %s%-18s%s %s',
            self::CYAN . self::BOLD,
            number_format($this->receivedRecords) . ' records',
            self::RESET,
            self::GREEN . self::BOLD,
            number_format($this->deliveredBatches) . ' batches',
            self::RESET,
            gmdate('H:i:s', $uptime),
        ));
        $screen .= $line(sprintf('  %-19s %-18s %s', 'QUEUE DEPTH', 'FAILED / DEAD', 'UPLOAD ERRORS'));
        $screen .= $line(sprintf(
            '  %s%-19s%s %s%-18s%s %s',
            $queue > 0 ? self::YELLOW : self::GREEN,
            $queue . ' pending',
            self::RESET,
            $failed > 0 ? self::RED : self::GREEN,
            $failed . ' dead-letter',
            self::RESET,
            $this->uploadFailures . ' session',
        ));
        $screen .= $mid;
        $screen .= $line('  ' . self::CYAN . 'LIVE EVENTS' . self::RESET . '  ' . self::DIM . '(most recent; payloads hidden)' . self::RESET);

        // Keep the screen within the terminal height; the ring buffer is bounded.
        $slots = max(1, $height - 17);
        $recent = array_slice($this->events, -$slots);

        foreach ($recent as $event) {
            $color = match ($event['level']) {
                'error' => self::RED,
                'warning' => self::YELLOW,
                'success' => self::GREEN,
                'receive' => self::CYAN,
                'queue' => self::BLUE,
                default => self::DIM,
            };

            $screen .= $line(
                '  ' . self::DIM . $event['time'] . self::RESET . ' '
                . $color . sprintf('%-7s', strtoupper($event['level'])) . self::RESET
                . ' ' . $event['message'],
            );
        }

        for ($i = count($recent); $i < $slots; $i++) {
            $screen .= $line('');
        }

        $screen .= $mid;
        $screen .= $line('  ' . self::DIM . 'CTRL+C' . self::RESET . ' stop  ·  '
            . 'queued ' . $this->queuedBatches . ' batches  ·  '
            . 'failed uploads (lifetime) ' . (int) ($metrics['failed_uploads'] ?? 0));
        $screen .= $bottom;

        $this->write("\033[H\033[2J" . rtrim($screen, "\n"));
    }

    private function frameLine(string $text, int $width): string
    {
        $plain = preg_replace('/\x1B\[[0-9;]*m/', '', $text) ?? '';
        $visibleWidth = function_exists('mb_strwidth') ? mb_strwidth($plain, 'UTF-8') : strlen($plain);

        if ($visibleWidth > $width) {
            // Avoid splitting ANSI sequences or leaking raw control characters.
            $text = $this->sanitize($plain);
            $text = function_exists('mb_strimwidth')
                ? mb_strimwidth($text, 0, max(0, $width - 1), '…', 'UTF-8')
                : substr($text, 0, max(0, $width - 1));
            $plain = $text;
            $visibleWidth = function_exists('mb_strwidth') ? mb_strwidth($plain, 'UTF-8') : strlen($plain);
        }

        return self::BLUE . '│' . self::RESET . $text
            . str_repeat(' ', max(0, $width - $visibleWidth))
            . self::BLUE . '│' . self::RESET . "\n";
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $value) ?? '[invalid text]';
    }

    private function supportsTui(): bool
    {
        if (
            !defined('STDOUT')
            || !function_exists('stream_isatty')
            || !@stream_isatty(STDOUT)
            || getenv('TERM') === 'dumb'
            || getenv('NO_COLOR') !== false
        ) {
            return false;
        }

        $terminal = new Terminal();

        return $terminal->getWidth() >= 68 && $terminal->getHeight() >= 19;
    }

    private function write(string $contents): void
    {
        $this->output->write($contents, false, OutputInterface::OUTPUT_RAW);
    }
}
