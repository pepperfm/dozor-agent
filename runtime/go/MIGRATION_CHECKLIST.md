# PHP → Go: актуальный checklist (2026-10-29)

Исходная ревизия PHP daemon: `b0f17f47`. Реализованный Go daemon запускается как основной агент; старый PHP daemon оставлен для обратной совместимости.

| Этап | Статус | Проверки и ограничения |
|---|---|---|
| A — контракт v1 и fixtures | ✅ | Настоящий PHP Payload smoke, **9 PHP-generated golden fixtures**, golden-drift gate |
| B — конкурентный TCP ingest | ✅ | Bounded goroutines, read deadlines, localhost, ошибки при перегрузке, корректный 2:OK/5:ER |
| C — durable spool | ✅ / fault injection отдельно | temp+rename, fsync файла/каталога, capacity, singleton flock, recovery, processing, failed |
| D — Go transformer | ✅ 9 сценариев | Точное семантическое сравнение с PHP эталонами, включая N+1, lifecycle и truncation. Дополнительные edge-cases возможны |
| E — shipping worker | ✅ | Отдельная горутина, HTTP 202, 429/5xx, Retry-After, exponential backoff, jitter, retries, неизменность sent_at |
| F — metrics и debug TUI | ✅ | TTY TUI, headless logging, атомарный runtime state, очередь/ошибки/uptime/доставка |
| G — PHP SDK и конфигурация | ✅ | PHP instrumentation без breaking changes, автоматический XXH128 из DOZOR_TOKEN, status --json/--strict |
| H — бинарники, systemd, Sail | ✅ / release tag отдельно | CI cross-build 4 платформ, release pipeline с checksum, unit, secure installer, инструкция Sail |
| I — E2E и нагрузка | Частично | Go TCP→spool→transform→HTTP 202 E2E + Laravel серверный тест; PHP/Go p50/p95/p99 benchmark harness. Фактические baseline CPU/RSS и реальный deployed Dozor Server E2E ещё не измерены |

## Правила доставки

- `2:OK` только после гарантированного локального fsync, при заполнении/записи с ошибкой — `5:ER`.
- `DOZOR_TOKEN` обязателен для remote shipping; Go вычисляет PHP `substr(hash('xxh128', $token), 0, 7)`.
- TCP v1 только на loopback: короткий hash не должен использоваться как аутентификация на публичном интерфейсе.
- Не запускать Go и PHP daemon одновременно на одном порту; отдельные spool-директории или гарантированное осушение очереди перед откатом.
- ACK не гарантирует моментальной видимости данных на панели: at-least-once + downstream async processing.
