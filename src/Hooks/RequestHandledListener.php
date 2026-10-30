<?php

declare(strict_types=1);

namespace Dozor\Hooks;

use Dozor\Contracts\DozorContract;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Foundation\Http\Events\RequestHandled;

final readonly class RequestHandledListener
{
    public function __construct(private DozorContract $core)
    {
    }

    public function __invoke(RequestHandled $event): void
    {
        try {
            // Kernel::handle emits RequestHandled even when no route matched.
            // Keep the concrete response for the post-middleware fallback.
            $event->request->attributes->set(
                RequestTerminationFallback::PENDING_RESPONSE_ATTRIBUTE,
                $event->response,
            );
            $this->core->transitionLifecycleStage(RequestLifecycleStage::Sending->value);
        } catch (\Throwable) {
        }
    }
}
