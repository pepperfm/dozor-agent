<?php

declare(strict_types=1);

namespace Dozor\Http\Middleware;

use Closure;
use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Http\Request;
use Throwable;

final readonly class TraceRequest
{
    private const STARTED_AT_ATTRIBUTE = 'dozor.started_at';
    private const PENDING_FINISH_ATTRIBUTE = 'dozor.pending_finish';

    public function __construct(private DozorContract $core)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->core->enabled()) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $this->core->beginRequest($request, $startedAt);
        $request->attributes->set(self::STARTED_AT_ATTRIBUTE, $startedAt);
        $request->attributes->set(self::PENDING_FINISH_ATTRIBUTE, false);
        $this->core->beginLifecycleStage(RequestLifecycleStage::Bootstrap->value);
        $this->core->endLifecycleStage(RequestLifecycleStage::Bootstrap->value);
        $this->core->beginLifecycleStage(RequestLifecycleStage::Middleware->value, [
            'middleware_count' => count($request->route()?->gatherMiddleware() ?? []),
        ]);
        $this->core->beginLifecycleStage(RequestLifecycleStage::Controller->value);

        try {
            $response = $next($request);
            $this->core->endLifecycleStage(RequestLifecycleStage::Controller->value);
            $this->core->beginLifecycleStage(RequestLifecycleStage::Render->value);
            $this->core->endLifecycleStage(RequestLifecycleStage::Render->value);
            $this->core->endLifecycleStage(RequestLifecycleStage::Middleware->value);
            $this->core->beginLifecycleStage(RequestLifecycleStage::Sending->value);
            $request->attributes->set(self::PENDING_FINISH_ATTRIBUTE, true);

            return $response;
        } catch (Throwable $e) {
            $this->core->endLifecycleStage(RequestLifecycleStage::Controller->value, [
                'exception_class' => $e::class,
            ]);
            $this->core->endLifecycleStage(RequestLifecycleStage::Middleware->value, [
                'exception_class' => $e::class,
            ]);
            $this->core->beginLifecycleStage(RequestLifecycleStage::Sending->value);
            $this->core->recordException($e, [
                'phase' => 'http',
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);
            $this->core->finishRequest($request, null, $startedAt, $e);
            $this->core->digest();

            throw $e;
        }
    }

    public function terminate(Request $request, \Symfony\Component\HttpFoundation\Response $response): void
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

        $this->core->finishRequest($request, $response, $startedAt);
        $this->core->digest();
    }
}
