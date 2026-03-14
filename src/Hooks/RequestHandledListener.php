<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

final readonly class RequestHandledListener
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(RequestHandled $event): void
    {
        try {
            $this->core->transitionLifecycleStage(RequestLifecycleStage::Sending->value);
        } catch (Throwable) {
        }
    }
}
