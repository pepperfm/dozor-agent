<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Console\AgentDebugDashboard;
use Dozor\Telemetry\AgentRuntimeState;
use Dozor\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class AgentDebugDashboardTest extends TestCase
{
    public function test_non_tty_debug_stream_reports_pipeline_without_terminal_escapes(): void
    {
        $output = new BufferedOutput();
        $dashboard = $this->dashboard($output, false);

        $dashboard->start();
        $dashboard->received(3);
        $dashboard->queued(3);
        $dashboard->flush(1, true);
        $dashboard->shipped(false, 1);
        $dashboard->shipped(true, 2);
        $dashboard->shutdown('SIGTERM');
        $dashboard->stop();

        $stream = $output->fetch();
        self::assertStringContainsString('DEBUG STREAM', $stream);
        self::assertStringContainsString('Accepted 3 telemetry record(s)', $stream);
        self::assertStringContainsString('Durable batch queued (3 source records)', $stream);
        self::assertStringContainsString('Remote upload failed (attempt 1)', $stream);
        self::assertStringContainsString('Remote batch delivered (attempt 2)', $stream);
        self::assertStringContainsString('Shutdown requested (SIGTERM)', $stream);
        self::assertStringNotContainsString("\033[", $stream);
    }

    public function test_tui_restores_terminal_when_stopped(): void
    {
        $output = new BufferedOutput();
        $dashboard = $this->dashboard($output, true);

        $dashboard->start();
        $dashboard->received(4);
        $dashboard->tick();
        $dashboard->stop();

        $screen = $output->fetch();
        self::assertStringContainsString("\033[?1049h\033[?25l", $screen);
        self::assertStringContainsString('DOZOR', $screen);
        self::assertStringContainsString('LIVE EVENTS', $screen);
        self::assertStringContainsString('4 records', $screen);
        self::assertStringContainsString("\033[?25h\033[?1049l", $screen);
    }

    private function dashboard(BufferedOutput $output, bool $interactive): AgentDebugDashboard
    {
        return new AgentDebugDashboard(
            output: $output,
            runtimeState: new AgentRuntimeState($this->makeTemporaryDirectory('dozor-debug') . '/runtime-state.json'),
            spoolQueue: null,
            appName: 'test-app',
            serverName: 'test-host',
            environment: 'testing',
            listenOn: '127.0.0.1:4815',
            shippingEnabled: true,
            interactive: $interactive,
        );
    }
}
