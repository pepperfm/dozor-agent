# Project: Dozor Agent

## Overview
Dozor Agent is a self-hostable Laravel instrumentation package and local ingest agent starter for Laravel applications. It captures request, query, queue, log, cache, HTTP, and application event telemetry, then buffers and ships those records through a local TCP agent with fail-safe behavior.

The repository is structured as a reusable Composer package rather than a standalone application. Its primary job is to integrate cleanly into host Laravel apps, expose Artisan commands for the local agent runtime, and keep telemetry collection non-fatal when the worker or hosted ingest endpoint is unavailable.

## Core Features
- Laravel package auto-discovery with `DozorServiceProvider`
- Request tracing middleware and lifecycle stage tracking
- Event-driven watchers for queries, queues, cache, logs, outgoing HTTP, and application events
- Local TCP ingest client/server with framed payload transport
- Buffered ingest, spool queue retry logic, and hosted ingest shipping
- Runtime telemetry state reporting via `dozor:status`
- Payload redaction, sampling, and request filtering controls

## Tech Stack
- **Language:** PHP 8.4
- **Framework:** Laravel package for Laravel 11+
- **Console Runtime:** Symfony Console 7.2+
- **Database:** None required by default; local NDJSON file storage is used for ingested records
- **ORM:** Not applicable in this package itself
- **Testing:** PHPUnit 12
- **Integrations:** Laravel events, middleware, queue lifecycle, local TCP ingest, optional hosted ingest endpoint, Supervisor/systemd deployment examples

## Architecture Notes
- The package follows a layered package architecture tailored to Laravel libraries.
- Bootstrap and framework integration live at the outer edge in the service provider, config, facade, middleware registration, and Artisan commands.
- Core telemetry behavior lives in package services such as `Dozor`, request filtering, sampling, redaction, payload shaping, and trace transformation.
- Runtime transport concerns live behind ingest and agent components so transport failures degrade safely instead of breaking the host application.
- The package prefers fail-safe instrumentation: when bootstrapping or transport fails, it falls back to `NullIngest` or suppresses telemetry rather than crashing the app.

## Non-Functional Requirements
- Logging: runtime and bootstrap failures must be observable without making package startup fatal
- Error handling: instrumentation should degrade safely when sockets, hosted ingest, or event listeners fail
- Security: redact sensitive payload and header fields by default; keep token handling and transport secrets out of emitted payloads
- Performance: request/query/log/event capture must remain lightweight and configurable via sampling and ignore rules
- Configurability: all operational behavior should remain environment-driven through `DOZOR_*` variables
- Testability: core capture and agent behaviors should remain covered by unit and integration tests

## Architecture
See `.ai-factory/ARCHITECTURE.md` for detailed architecture guidelines.
Pattern: Layered Architecture
