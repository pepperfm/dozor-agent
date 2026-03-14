<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Routing\Events\PreparingResponse;
use Throwable;

final readonly class PreparingResponseListener
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(PreparingResponse $event): void
    {
        try {
            if ($this->core->lifecycleStageIs(RequestLifecycleStage::Action->value)) {
                $this->core->transitionLifecycleStage(RequestLifecycleStage::Render->value);
            }
        } catch (Throwable) {
        }
    }
}
