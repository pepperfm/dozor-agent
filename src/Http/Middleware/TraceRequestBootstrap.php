<?php

declare(strict_types=1);

namespace Dozor\Http\Middleware;

use Closure;
use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class TraceRequestBootstrap
{
    private const STARTED_AT_ATTRIBUTE = 'dozor.started_at';
    private const PENDING_FINISH_ATTRIBUTE = 'dozor.pending_finish';
    private const EXCEPTION_RECORDED_ATTRIBUTE = 'dozor.exception_recorded';

    public function __construct(private DozorContract $core)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (!$this->core->enabled()) {
            return $next($request);
        }

        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        if (!is_numeric($startedAt)) {
            $startedAt = microtime(true);
        }

        $startedAt = (float) $startedAt;

        if (!$this->core->hasActiveRequestTrace()) {
            $this->capture(fn() => $this->core->beginRequest($request, $startedAt));
        }

        $request->attributes->set(self::STARTED_AT_ATTRIBUTE, $startedAt);
        $request->attributes->set(self::PENDING_FINISH_ATTRIBUTE, true);

        if (!$this->core->lifecycleStageIs(RequestLifecycleStage::BeforeMiddleware->value)) {
            $this->capture(fn() => $this->core->transitionLifecycleStage(RequestLifecycleStage::BeforeMiddleware->value));
        }

        try {
            return $next($request);
        } catch (Throwable $e) {
            $this->recordExceptionOnce($request, $e);

            throw $e;
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        if (!$this->core->enabled()) {
            return;
        }

        if ((bool) $request->attributes->get(self::PENDING_FINISH_ATTRIBUTE, false) !== true) {
            return;
        }

        $startedAt = $request->attributes->get(self::STARTED_AT_ATTRIBUTE);
        if (!is_float($startedAt)) {
            $startedAt = microtime(true);
        }

        $this->capture(fn() => $this->core->finishRequest($request, $response, $startedAt));
        $this->capture(fn() => $this->core->digest());
    }

    private function capture(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
        }
    }

    private function recordExceptionOnce(Request $request, Throwable $e): void
    {
        if ((bool) $request->attributes->get(self::EXCEPTION_RECORDED_ATTRIBUTE, false)) {
            return;
        }

        $this->capture(function () use ($request, $e): void {
            $this->core->recordException($e, [
                'phase' => 'http',
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            $request->attributes->set(self::EXCEPTION_RECORDED_ATTRIBUTE, true);
        });
    }
}
