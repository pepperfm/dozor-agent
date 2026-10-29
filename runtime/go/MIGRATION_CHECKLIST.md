# PHP → Go: актуальный checklist (2026-10-29)

Исходная ревизия PHP daemon: `b0f17f47`. Реализованный Go daemon запускается как основной агент; старый PHP daemon оставлен для обратной совместимости.

| Этап | Статус | Проверки и ограничения |
|---|---|---|
| A — контракт v1 и fixtures | ✅ | Настоящий PHP Payload smoke, **11 PHP-generated golden fixtures**, golden-drift gate |
| B — конкурентный TCP ingest | ✅ | Bounded goroutines, read deadlines, localhost, ошибки при перегрузке, корректный 2:OK/5:ER |
| C — durable spool | ✅ / fault injection отдельно | temp+rename, fsync файла/каталога, capacity, singleton flock, recovery, processing, failed; disk-write-failure → 5:ER тест, status учитывает in-flight |
| D — Go transformer | ✅ 11 сценариев | Точное семантическое сравнение с PHP эталонами, включая N+1, lifecycle, truncation, порядок 33 headers, вложенный request preview, HTTP status fallback; ассоциативные headers. Дополнительные edge-cases возможны |
| E — shipping worker | ✅ | Отдельная горутина, HTTP 202, 429/5xx, долговременный Retry-After через spool без блокировки других батчей, exponential backoff, jitter, retries, неизменность sent_at |
| F — metrics и debug TUI | ✅ | TTY TUI, событийные headless debug-логи без payload/secrets, атомарный runtime state, корректный dead-letter count, очередь/ошибки/uptime/доставка |
| G — PHP SDK и конфигурация | ✅ | PHP instrumentation без breaking changes, автоматический XXH128 из DOZOR_TOKEN, status --json/--strict; PHP status использует spool_path Go и учитывает processing, Go status читает абсолютный путь/порт из state, сообщает app/server/environment |
| H — бинарники, systemd, Sail | ✅ / release tag отдельно | CI cross-build 4 платформ, release pipeline с checksum, unit, secure installer, инструкция Sail |
| I — E2E и нагрузка | Частично | PHP→Go→spool→transform→HTTP 202 E2E в публичном CI. Проверяется независимость TCP ACK от медленного HTTP receiver. В `pepperfm/dozor` добавлен PHP→Go→Laravel ingest→PostgreSQL E2E с offline recovery, но private GitHub Actions job пока не стартует (runner_id=0, steps=[]); результат НЕ подтверждён. PHP/Go p50/p95/p99 benchmark harness есть; baseline CPU/RSS и deployed E2E ещё не измерены |

## Правила доставки

- `2:OK` только после гарантированного локального fsync, при заполнении/записи с ошибкой — `5:ER`.
- `DOZOR_TOKEN` обязателен для remote shipping; Go вычисляет PHP `substr(hash('xxh128', $token), 0, 7)`.
- TCP v1 только на loopback: короткий hash не должен использоваться как аутентификация на публичном интерфейсе.
- Не запускать Go и PHP daemon одновременно на одном порту; отдельные spool-директории или гарантированное осушение очереди перед откатом.
- ACK не гарантирует моментальной видимости данных на панели: at-least-once + downstream async processing.
