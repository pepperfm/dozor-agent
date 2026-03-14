<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;

final readonly class RequestBootedHandler
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(Application $app): void
    {
        if (!$this->core->enabled()) {
            return;
        }

        if (!$app->bound('request')) {
            return;
        }

        try {
            $request = $app->make('request');
            if (!$request instanceof Request) {
                return;
            }

            $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
            if (!is_numeric($startedAt)) {
                $startedAt = microtime(true);
            }

            $startedAt = (float) $startedAt;

            if (!$this->core->hasActiveRequestTrace()) {
                $this->core->beginRequest($request, $startedAt);
            }

            if (!$this->core->lifecycleStageIs(RequestLifecycleStage::BeforeMiddleware->value)) {
                $this->core->transitionLifecycleStage(RequestLifecycleStage::BeforeMiddleware->value);
            }
        } catch (Throwable) {
        }
    }
}
