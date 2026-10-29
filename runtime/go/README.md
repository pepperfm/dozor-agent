# Dozor Agent — Go daemon + Laravel PHP SDK

Сбор телеметрии остаётся в PHP/Laravel; доставка выполняется отдельным небольшим Go-процессом. **Никакого Go SDK или Go runtime на сервере не требуется**: только готовый бинарник.

## Реализовано

- Совместимый с PHP SDK TCP протокол v1, включая XXH128, PING, ACK и 1 MiB лимит; только localhost.
- Конкурентный ingest, ограничения клиентов и таймауты. ACK только после durable записи.
- Spool с атомарной записью, fsync, lock одного процесса, queue/processing/failed, bounded capacity и восстановлением после сбоя.
- Go transformer (traces, spans, lifecycle, nested records, exceptions, truncation) с **11 PHP-generated golden fixtures** в CI.
- Независимый HTTP worker: проверенный TLS с отказом от недоверенного сертификата, Bearer token, 202, 429/5xx, Retry-After с отложенной spool-попыткой (не блокирует другие батчи), backoff+jitter, dead-letter и сохранение outbound payload между попытками.
- Периодические heartbeat, JSON runtime state, `status --json/--strict`, интерактивный `--debug` TUI и безопасные headless логи.
- Unit/race и golden fixture тесты доступны для локального запуска. GitHub Actions удалены по решению владельца; Go-бинарники для Pechka собираются и публикуются администратором вне GitHub CI.
- systemd unit, установщик с SHA256, инструкции для Sail.

## Локальная разработка

```bash
cd runtime/go
go test -race ./...
go build -o ./dozor-agent ./cmd/dozor-agent

export DOZOR_TOKEN='example-app-token'
export DOZOR_APP_NAME='my-laravel-app'
export DOZOR_ENV=local
export DOZOR_SERVER=local-dev
export DOZOR_INGEST_URI='127.0.0.1:4815'
export DOZOR_HOSTED_INGEST_URL='http://host.docker.internal:82/api/v1/ingest'
export DOZOR_GO_SPOOL_PATH="$(mktemp -d)"
export DOZOR_AGENT_STATE_PATH="$(mktemp -d)/runtime-state.json"

./dozor-agent --debug
```

`DOZOR_HOSTED_INGEST_URL` выше — **пример для Sail**, когда Dozor Server запущен на хосте. Для приложения на том же хосте укажите реальный URL сервера Dozor. Секреты только через environment-файл с ограниченными правами.

По умолчанию запускается полноценный ingest + shipping. Режим `--ingest-only` полезен для контрактных тестов, но **не отправляет события на сервер**. Для TUI требуется интерактивный терминал; без TTY `--debug` показывает строчные логи.

```bash
./dozor-agent status --json
./dozor-agent status --strict
./dozor-agent --version
```

PHP интеграция **не переписывалась**. `php artisan dozor:status --json/--strict` продолжает работать через TCP PING и общую runtime-state JSON. `php artisan dozor:agent` остаётся legacy PHP daemon для отладки/отката, но не должен запускаться вместе с Go на одном порту.

## Подключение через Печку

Печка сама устанавливает проверенный бинарник, PHP SDK и управляет процессом Supervisor, когда владелец панели настроил HTTPS-источники бинарников и SHA256. Пользователю сайта не требуется SSH. Для безопасной загрузки готовых бинарников после отключения CI выполните `bash scripts/build-pechka.sh` в `runtime/go`, опубликуйте оба Linux-бинарника на HTTPS и укажите URL/SHA256 в конфигурации Печки.

Go запускается с `--env-file=/path/to/.env`: читает только переменные `DOZOR_*`, `APP_NAME`, `APP_ENV` как данные без shell evaluation; переменные, переданные операционной системой, имеют приоритет. Не передавайте токены аргументами CLI.

## Развёртывание

См. [deploy/README.md](deploy/README.md) (systemd, Sail, права на токен, путь spool и rollback), [benchmarks/README.md](benchmarks/README.md) и [MIGRATION_CHECKLIST.md](MIGRATION_CHECKLIST.md).

## Корректность и ограничения

Подтверждение `2:OK` означает только **надёжную локальную запись**. HTTP `202` означает приём сервером, не финальную обработку. Доставка **at-least-once**, не exactly-once; дедупликация выполняется принимающим Dozor Server.

Функциональная совместимость трансформации подтверждена дифференциальными тестами для одиннадцати сценариев. Это не доказательство полной эквивалентности всех редких комбинаций 1500+ строк PHP transformer. Отдельная проверка Laravel сервера находится в `pepperfm/dozor/tests/Feature/Api/IngestV1Test.php`. Численные результаты CPU/RSS и latency baseline не выдумываем: для них есть воспроизводимые тесты, но измерение на одинаковом оборудовании остаётся отдельной задачей.

Дополнительный сквозной тест PHP-клиент → Go → **реальный** Laravel ingest → PostgreSQL добавлен в `pepperfm/dozor/tests/E2E/AgentSandboxFlowTest.php` и подключён в серверный workflow. Пока приватный GitHub Actions job завершается до появления runner/steps, его прохождение **не подтверждено**; это отдельный обязательный gate перед объявлением полной готовности.


### Публикация бинарников для Pechka без CI (macOS)

С локального `master` репозитория `dozor-agent` (нужны `go`, `gh auth login`):

```bash
cd runtime/go
./scripts/publish-pechka-release.sh v0.2.0-pechka.1
```

Скрипт проверяет чистый и актуальный `master`, запускает `go test ./...`,
собирает Linux arm64/amd64, проверяет SHA-256 и создаёт **новый**
GitHub Release без Actions. Затем печатает `PECHKA_DOZOR_AGENT_*_URL`,
`PECHKA_DOZOR_AGENT_*_SHA256` и `PECHKA_DOZOR_SDK_SHA` для
конфигурации панели. Существующий релиз не перезаписывается; для обновления
создайте новый тег. Это действие администратора панели, а не её пользователей.
