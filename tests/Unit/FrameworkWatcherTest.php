<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\DozorContract;
use Dozor\Tests\TestCase;
use Dozor\Watchers\FrameworkWatcher;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

final class FrameworkWatcherTest extends TestCase
{
    public function test_it_records_command_outcome_without_arguments(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->expects(self::once())->method('startFrameworkCommand')->with('cache:clear');
        $core->expects(self::once())->method('finishFrameworkCommand')->with('cache:clear');
        $core->expects(self::once())->method('recordFrameworkEvent')
            ->with(
                'artisan.cache:clear',
                self::callback(static fn(array $data): bool =>
                    $data['exit_code'] === 0
                    && $data['failed'] === false
                    && isset($data['duration_ms'])
                    && !array_key_exists('arguments', $data)
                ),
            );

        $watcher = new FrameworkWatcher($core);
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $watcher->commandStarting(new CommandStarting('cache:clear', $input, $output));
        $watcher->commandFinished(new CommandFinished('cache:clear', $input, $output, 0));
    }
}
