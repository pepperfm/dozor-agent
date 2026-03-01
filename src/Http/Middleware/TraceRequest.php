<?php

declare(strict_types=1);

namespace Dozor\Http\Middleware;

use Closure;
use Dozor\Contracts\DozorContract;
use Illuminate\Http\Request;
use Throwable;

final readonly class TraceRequest
{
    public function __construct(private DozorContract $core)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->core->enabled()) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $this->core->beginRequest($request);

        try {
            $response = $next($request);
            $this->core->finishRequest($request, $response, $startedAt);

            return $response;
        } catch (Throwable $e) {
            $this->core->recordException($e, [
                'phase' => 'http',
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);
            $this->core->finishRequest($request, null, $startedAt, $e);

            throw $e;
        } finally {
            $this->core->digest();
        }
    }
}
