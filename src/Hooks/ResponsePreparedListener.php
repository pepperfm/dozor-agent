<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Routing\Events\ResponsePrepared;
use Throwable;

final readonly class ResponsePreparedListener
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(ResponsePrepared $event): void
    {
        try {
            if ($this->core->lifecycleStageIs(RequestLifecycleStage::Render->value)) {
                $this->core->transitionLifecycleStage(RequestLifecycleStage::AfterMiddleware->value);
            }
        } catch (Throwable) {
        }
    }
}
