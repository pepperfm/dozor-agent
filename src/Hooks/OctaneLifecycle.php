<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

final class OctaneLifecycle
{
    public function register(DozorContract $core): void
    {
        $this->listen('Laravel\\Octane\\Events\\RequestReceived', function (object $event) use ($core): void {
            $request = $event->request ?? null;
            if ($request instanceof Request) {
                $this->requestReceived($core, $request);
            }
        });
        $this->listen('Laravel\\Octane\\Events\\RequestTerminated', function (object $event) use ($core): void {
            $request = $event->request ?? null;
            $response = $event->response ?? null;
            if ($request instanceof Request && $response instanceof Response) {
                $this->requestTerminated($core, $request, $response);
            }
        });
        $this->listen('Laravel\\Octane\\Events\\WorkerStarting', function () use ($core): void {
            $this->workerBoundary($core);
        });
        $this->listen('Laravel\\Octane\\Events\\WorkerStopping', function () use ($core): void {
            $this->workerBoundary($core);
        });
    }

    public function requestReceived(DozorContract $core, Request $request): void
    {
        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        $core->beginRequest($request, is_numeric($startedAt) ? (float) $startedAt : null);
    }

    public function requestTerminated(DozorContract $core, Request $request, Response $response): void
    {
        if (!$core->hasActiveRequestTrace()) {
            return;
        }

        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        $startedAt = is_numeric($startedAt) ? (float) $startedAt : microtime(true);
        $pending = $request->attributes->get('dozor.pending_exception');
        $core->finishRequest(
            $request,
            $response,
            $startedAt,
            $pending instanceof \Throwable ? $pending : null,
        );
        $core->digest();
    }

    public function workerBoundary(DozorContract $core): void
    {
        $core->abandonActiveRequest();
    }

    private function listen(string $event, callable $listener): void
    {
        if (!class_exists($event)) {
            return;
        }

        try {
            Event::listen($event, $listener);
        } catch (\Throwable $e) {
            logger()->warning('dozor.octane.hooks_unavailable', [
                'class' => $e::class,
            ]);
        }
    }
}
