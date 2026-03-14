<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Foundation\Events\Terminating;
use Throwable;

final readonly class TerminatingListener
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(Terminating $event): void
    {
        try {
            $this->core->transitionLifecycleStage(RequestLifecycleStage::Terminating->value);
        } catch (Throwable) {
        }
    }
}
