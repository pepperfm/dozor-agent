# Architecture: Layered Architecture

## Overview
Dozor Agent uses a layered architecture adapted for a reusable Laravel package. The project needs clear separation between Laravel-facing integration points, core telemetry behavior, and runtime transport mechanics, but it does not need the ceremony of DDD or the operational cost of microservices.

This pattern fits the repository because the package has a focused domain, a likely small team, and strong requirements around fail-safe behavior inside host applications. The architecture keeps Laravel bootstrapping and event wiring at the outer layer, preserves reusable telemetry logic in the package core, and isolates agent transport/runtime details so failures stay contained.

## Decision Rationale
- **Project type:** Self-hosted Laravel instrumentation package with a local ingest agent
- **Tech stack:** PHP 8.4, Laravel 11+, Symfony Console, PHPUnit
- **Key factor:** The package must integrate deeply with Laravel while keeping telemetry capture and agent transport isolated, testable, and non-fatal

## Folder Structure
```text
src/
├── DozorServiceProvider.php      # Outer framework integration layer
├── Facades/                      # Laravel facade exposure
├── Console/                      # Artisan command entry points
├── Http/Middleware/              # HTTP integration points
├── Watchers/                     # Event-driven adapters from Laravel events to package records
├── Hooks/                        # Laravel event listeners that transition lifecycle stages
├── Contracts/                    # Stable abstractions for core services
├── Dozor.php                     # Core orchestration for request/trace capture
├── Context/                      # Trace state and request-scoped context
├── Filters/                      # Filtering rules
├── Sampling/                     # Sampling policy
├── Redaction/                    # Sensitive data handling
├── Tracing/                      # Trace/lifecycle transformation
├── Payload.php                   # Shared payload model
├── PayloadType.php               # Payload categorization
├── RecordsBuffer.php             # Lightweight buffering utility
├── Ingest.php                    # Local ingest client implementation
├── NullIngest.php                # Safe fallback transport
├── SocketStreamFactory.php       # Socket creation detail
├── Agent/                        # Local agent runtime and shipping pipeline
│   ├── Server.php
│   ├── SpoolQueue.php
│   └── Transport/
│       └── HostedIngestTransport.php
└── Telemetry/                    # Runtime state persistence for the agent
tests/
├── Unit/                         # Fast feedback for pure package behavior
└── Integration/                  # End-to-end and transport/runtime scenarios
```

## Dependency Rules
The main dependency direction is outer Laravel integration -> package core -> runtime transport/helpers. Core telemetry behavior should not depend on console commands, middleware registration, or deployment concerns.

- ✅ `Console`, `Http/Middleware`, and `Watchers` may depend on `Contracts`, `Dozor`, `Context`, `Tracing`, `Filters`, `Sampling`, and `Redaction`
- ✅ `DozorServiceProvider` may compose concrete implementations and wire Laravel events to watchers
- ✅ `Agent` runtime classes may depend on transport helpers, telemetry state, and trace transformers
- ✅ Tests may depend on any production layer as needed
- ❌ Core classes such as `Dozor`, `Payload`, `Context`, `Sampling`, and `Redaction` must not depend on console commands or deployment example files
- ❌ Transport implementations must not contain Laravel bootstrapping or service-provider registration logic
- ❌ Example deployment files and public brand assets must never become runtime dependencies of the package

## Layer Communication
- Laravel enters through `DozorServiceProvider`, Artisan commands, and middleware aliases.
- Framework events are translated by watchers into package-level telemetry calls on `DozorContract`.
- `Dozor` orchestrates sampling, filtering, redaction, trace state, and payload emission.
- `IngestContract` abstracts payload delivery so the package can switch between real transport and `NullIngest`.
- The agent runtime consumes stored records and optional hosted ingest transport without leaking those details back into request-time instrumentation.

## Key Principles
1. Keep host applications safe: instrumentation failures must degrade gracefully instead of crashing request or queue execution.
2. Keep package boundaries explicit: Laravel-specific wiring belongs at the edge, while telemetry shaping stays in reusable services.
3. Prefer additive extension points: add new watchers, payload types, or transport implementations without reshaping unrelated layers.

## Code Examples

### Example 1: New watcher depending on the core contract
```php
<?php

declare(strict_types=1);

namespace Dozor\Watchers;

use Dozor\Contracts\DozorContract;

final class BroadcastWatcher
{
    public function __construct(
        private readonly DozorContract $dozor,
    ) {}

    public function sent(string $channel, string $event): void
    {
        if (!$this->dozor->enabled()) {
            return;
        }

        $this->dozor->record('custom', [
            'type' => 'broadcast',
            'channel' => $channel,
            'event' => $event,
        ]);
    }
}
```

### Example 2: Service provider wires the outer layer without polluting the core
```php
<?php

declare(strict_types=1);

namespace Dozor;

use Dozor\Contracts\DozorContract;
use Dozor\Watchers\BroadcastWatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class DozorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!$this->app->make(DozorContract::class)->enabled()) {
            return;
        }

        Event::listen(
            'broadcast.sent',
            [$this->app->make(BroadcastWatcher::class), 'sent']
        );
    }
}
```

## Anti-Patterns
- ❌ Putting sampling, redaction, or filtering rules directly into middleware or console commands instead of the shared core services
- ❌ Making watchers instantiate socket transports or file storage classes directly instead of delegating through the core/ingest abstractions
- ❌ Allowing agent shipping concerns to leak into request-time instrumentation paths
- ❌ Adding new runtime behavior without unit or integration coverage in `tests/Unit` or `tests/Integration`
