<?php

declare(strict_types=1);

namespace Dozor\Console;

use Illuminate\Console\Command;
use Dozor\Contracts\DozorContract;
use Throwable;

final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dozor:status';

    /**
     * @var string
     */
    protected $description = 'Get the current status of the Dozor agent.';

    public function handle(DozorContract $dozor): int
    {
        if (! $dozor->enabled()) {
            $this->components->error('Dozor is disabled');

            return 1;
        }

        try {
            $dozor->ping();

            $this->components->info('The Dozor agent is running and accepting connections');

            return 0;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 1;
        }
    }
}
