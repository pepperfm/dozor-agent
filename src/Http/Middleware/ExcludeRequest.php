<?php

declare(strict_types=1);

namespace Dozor\Http\Middleware;

use Illuminate\Http\Request;
use Dozor\Contracts\DozorContract;

final readonly class ExcludeRequest
{
    public function __construct(private DozorContract $core)
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        $request->attributes->set('dozor.exclude', true);
        $this->core->suppressIfExcluded($request);

        return $next($request);
    }
}
