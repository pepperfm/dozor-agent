<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finish requests which never entered a web/api route middleware group
 * (notably unmatched 404 routes and early framework errors).
 *
 * App terminating callbacks run after terminable middleware. Normal requests
 * have already finalized by then; this path must not emit a second root.
 */
final readonly class RequestTerminationFallback
{
    public const PENDING_RESPONSE_ATTRIBUTE = 'dozor.pending_response';

    public function __construct(
        private DozorContract $core,
        private Application $app,
    ) {
    }

    public function __invoke(): void
    {
        try {
            if (!$this->core->enabled() || !$this->core->hasActiveRequestTrace() || !$this->app->bound('request')) {
                return;
            }

            $request = $this->app->make('request');
            if (!$request instanceof Request) {
                return;
            }

            $response = $request->attributes->get(self::PENDING_RESPONSE_ATTRIBUTE);
            if (!$response instanceof Response) {
                return;
            }

            $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
            $startedAt = is_numeric($startedAt) ? (float) $startedAt : microtime(true);

            $pending = $request->attributes->get('dozor.pending_exception');
            $this->core->finishRequest(
                $request,
                $response,
                $startedAt,
                $pending instanceof \Throwable ? $pending : null,
            );
            $this->core->digest();
        } catch (\Throwable) {
            // A monitoring fallback must not interrupt normal termination.
        }
    }
}
