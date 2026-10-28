# AGENTS.md

> Project map for AI agents. Keep this file up-to-date as the project evolves.

## Project Overview
Dozor Agent is a reusable Laravel package that instruments host applications and runs a local ingest agent for self-hosted telemetry. The repository combines Laravel integration points, telemetry capture logic, and an agent runtime with retryable shipping.

## Tech Stack
- **Language:** PHP 8.4
- **Framework:** Laravel package for Laravel 11+
- **Database:** None required by default; local NDJSON storage is the current persistence layer
- **ORM:** Not applicable
- **Testing:** PHPUnit 12

## Project Structure
```text
.
├── .ai-factory/                  # AI project context generated for this repository
├── agent/
│   └── helpers.php               # Composer-autoloaded package helper functions
├── config/
│   └── dozor.php                 # Published package configuration and env mapping
├── deploy/
│   └── supervisor/               # Deployment-ready Supervisor example configs
├── examples/
│   ├── supervisor/               # Example Supervisor service config
│   ├── systemd/                  # Example systemd service config
│   └── record-shape.md           # Example emitted record structure
├── public/
│   └── dozor-agent-brand-pack/   # Brand assets and usage guidance
├── src/
│   ├── Agent/                    # Local ingest server, spool queue, hosted transport
│   │   └── Transport/            # Hosted ingest transport implementations
│   ├── Console/                  # Artisan commands (`dozor:agent`, `dozor:status`)
│   ├── Context/                  # Trace context objects
│   ├── Contracts/                # Package contracts and service abstractions
│   ├── Facades/                  # Laravel facade definitions
│   ├── Filters/                  # Request and event filtering logic
│   ├── Http/
│   │   └── Middleware/           # Request tracing middleware
│   ├── Hooks/                    # Event listeners for request lifecycle stage transitions
│   ├── Redaction/                # Payload/header redaction logic
│   ├── Sampling/                 # Deterministic sampling behavior
│   ├── Telemetry/                # Agent runtime state and telemetry persistence
│   ├── Tracing/                  # Trace batch transformation and lifecycle helpers
│   ├── Watchers/                 # Event listeners that emit telemetry records
│   ├── Dozor.php                 # Main instrumentation service
│   ├── DozorServiceProvider.php  # Package registration and Laravel event wiring
│   ├── Ingest.php                # Local ingest client
│   ├── NullIngest.php            # Fail-safe no-op ingest implementation
│   ├── Payload.php               # Common telemetry payload object
│   └── RecordsBuffer.php         # In-memory ingest buffering
├── tests/
│   ├── Integration/              # Runtime, transport, and end-to-end package tests
│   ├── Unit/                     # Focused unit tests for core components
│   └── TestCase.php              # Shared Laravel test bootstrap
├── .mcp.json                     # Project-local MCP server configuration
├── composer.json                 # Package metadata, dependencies, autoload rules
├── phpunit.xml                   # PHPUnit configuration
├── README.md                     # Main package documentation
├── NOTICE.md                     # Project notices
└── UPSTREAM_SOURCES.md           # Upstream inspiration and source mapping
```

## Key Entry Points
| File | Purpose |
|------|---------|
| `composer.json` | Declares package metadata, dependencies, autoloading, and Laravel package discovery |
| `src/DozorServiceProvider.php` | Registers bindings, publishes config, wires listeners, commands, and middleware |
| `src/Dozor.php` | Core instrumentation service that builds traces and emits telemetry records |
| `src/Http/Middleware/TraceRequestBootstrap.php` | Starts request trace early and finalizes it in terminate |
| `src/Console/AgentCommand.php` | Boots and runs the local ingest agent and shipping pipeline |
| `config/dozor.php` | Central runtime configuration and `DOZOR_*` environment mapping |
| `.mcp.json` | Project-level MCP configuration for local tooling integrations |

## Documentation
| Document | Path | Description |
|----------|------|-------------|
| README | `README.md` | Main package overview, install, env, and usage guide |
| Notice | `NOTICE.md` | Notice and attribution material |
| Upstream sources | `UPSTREAM_SOURCES.md` | Notes on upstream inspiration and source lineage |
| Record shape | `examples/record-shape.md` | Example structure of emitted telemetry payloads |
| Brand pack | `public/dozor-agent-brand-pack/README.md` | Brand asset usage and packaging notes |

## AI Context Files
| File | Purpose |
|------|---------|
| `AGENTS.md` | This file - project structure map |
| `.ai-factory/DESCRIPTION.md` | Project specification and tech stack |
| `.ai-factory/ARCHITECTURE.md` | Architecture decisions and guidelines |

## Agent Rules
- Never combine shell commands with `&&`, `||`, or `;` - execute each command as a separate tool call.
- Keep this file factual: reflect the repository as it exists today, not an imagined target structure.
