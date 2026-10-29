# Установка Go Dozor Agent

## Требования

- Сервер Linux или macOS с готовым Go-бинарником. **Go на сервере не нужен.**
- Laravel PHP SDK `pepperfm/dozor-agent` (инструментация остаётся на PHP).
- У Dozor Server должен быть доступен ingest v1 по HTTPS.
- Отдельный пользователь и доступная spool-директория.

## Linux / systemd

После выпуска GitHub Release (например `v1.0.0`):

```bash
sudo DOZOR_VERSION=v1.0.0 bash deploy/install.sh
sudo cp deploy/agent.env.example /etc/dozor-agent/agent.env
sudo chmod 0600 /etc/dozor-agent/agent.env
sudo cp deploy/dozor-agent.service /etc/systemd/system/dozor-agent.service
# Заполнить /etc/dozor-agent/agent.env, указать реальный токен и ingest URL
sudo systemctl daemon-reload
sudo systemctl enable --now dozor-agent
sudo journalctl -u dozor-agent -f
```

Не публикуйте токен в аргументах командной строки или Git. В `agent.env` должен храниться токен приложения, которым пользуется PHP SDK. Go вычисляет те же 7 символов XXH128 самостоятельно. TCP v1 доступен **только на loopback**.

Для Laravel .env: `DOZOR_ENABLED=true`, `DOZOR_TOKEN=<тот же токен>`, `DOZOR_INGEST_URI=127.0.0.1:4815`. Приложение и бинарник должны находиться в одном сетевом пространстве (хосте).

## Laravel Sail

Запускайте Go **внутри контейнера `laravel.test`**, если PHP SDK обращается к `127.0.0.1`. Такой адрес другого контейнера — не тот же самый loopback.

1. Возьмите Linux-бинарник архитектуры вашего Sail-контейнера из релиза или соберите `GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build`.
2. Поместите бинарник в директорию проекта, монтируемую Sail, например `./bin/dozor-agent`.
3. Запустите `./vendor/bin/sail exec laravel.test ./bin/dozor-agent --debug` в отдельном терминале, обеспечив передачу `DOZOR_TOKEN`, `DOZOR_HOSTED_INGEST_URL` и `DOZOR_INGEST_URI` в среду процесса (через compose environment или явные `-e` при docker compose exec).
4. Для локального Dozor Server на компьютере используйте `DOZOR_HOSTED_INGEST_URL=http://host.docker.internal:82/api/v1/ingest`. Он доступен из Sail, а не с VPS.

`--debug` показывает TUI только при наличии TTY, иначе пишет краткие безопасные метрики в лог. `dozor-agent status --json` проверяет TCP PING и spool. У PHP SDK продолжают работать `php artisan dozor:status --json` и `--strict`.

## Перезапуск и сбои

Spool использует `queue/`, `processing/`, `failed/`. После перезапуска подтверждённые, но не доставленные пакеты возвращаются из processing в очередь. На сетевом уровне применяется **at-least-once** доставка: при сбое после HTTP 202 возможна повторная отправка, сервер должен использовать идемпотентность.

Для отката: сначала дождитесь нулевой очереди (включая processing) и остановите Go service; затем можно включить прежний `php artisan dozor:agent`. **Нельзя направлять PHP daemon на spool с сырыми Go-батчами.** Запускать обе реализации на одном TCP-порту запрещено.

## Безопасность

Не публикуйте `DOZOR_INGEST_URI` за пределы 127.0.0.1 / ::1. Семисимвольный hash — не полноценная авторизация. Прямой TCP через Docker-контейнеры требовал бы нового аутентифицированного протокола; в текущем варианте запускайте daemon внутри Laravel-контейнера.
