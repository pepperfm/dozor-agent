<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Telemetry\AgentRuntimeState;
use Dozor\Tests\TestCase;

final class GoRuntimeStateCompatibilityTest extends TestCase
{
    public function test_php_can_read_go_spool_path_and_shipping_status(): void
    {
        $directory = $this->makeTemporaryDirectory('dozor-go-runtime');
        $path = $directory . '/state.json';
        $spoolPath = $directory . '/separate-go-spool';

        file_put_contents($path, json_encode([
            'context' => [
                'runtime' => 'go',
                'spool_path' => $spoolPath,
                'shipper_enabled' => true,
            ],
            'lifecycle' => [
                'status' => 'running',
            ],
            'metrics' => [
                'queue_depth' => 2,
                'failed_queue_depth' => 1,
            ],
        ], JSON_THROW_ON_ERROR));

        $state = (new AgentRuntimeState($path))->read();

        self::assertSame('go', $state['context']['runtime']);
        self::assertSame($spoolPath, $state['context']['spool_path']);
        self::assertTrue($state['context']['shipper_enabled']);
        self::assertSame(2, $state['metrics']['queue_depth']);
        self::assertSame(1, $state['metrics']['failed_queue_depth']);
    }

    public function test_legacy_php_state_retains_its_defaults(): void
    {
        $state = (new AgentRuntimeState($this->makeTemporaryDirectory('dozor-legacy-state') . '/missing.json'))->read();

        self::assertNull($state['context']['runtime']);
        self::assertNull($state['context']['spool_path']);
        self::assertFalse($state['context']['shipper_enabled']);
    }
}
