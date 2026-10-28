<?php

declare(strict_types=1);

namespace Dozor\Tracing;

use Dozor\Contracts\DozorContract;
use Illuminate\Contracts\View\Engine;
use Throwable;

/**
 * Wraps the actual Blade/PHP template engine. This measures template execution,
 * not the unrelated response-preparation / Inertia SSR time.
 */
final readonly class MeasuredViewEngine implements Engine
{
    public function __construct(
        private Engine $delegate,
        private DozorContract $core,
    ) {
    }

    public function get($path, array $data = [])
    {
        $startedAt = microtime(true);

        try {
            return $this->delegate->get($path, $data);
        } finally {
            try {
                $this->core->recordFrameworkEvent('view.rendered', [
                    'view' => basename((string) $path),
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                    'engine' => $this->delegate::class,
                ]);
            } catch (Throwable) {
                // Observability must never alter rendering or hide a view exception.
            }
        }
    }
}
