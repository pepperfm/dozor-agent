# Dozor Agent

Небольшой Laravel-пакет, который отправляет телеметрию **своих** приложений в приватную панель Dozor. Требуются PHP 8.5 и Laravel 13.

## Установка в отслеживаемом проекте

```bash
composer require pepperfm/dozor-agent
php artisan vendor:publish --tag=dozor-config
```

```dotenv
DOZOR_ENABLED=true
DOZOR_TOKEN=<TOKEN_FROM_DOZOR>
DOZOR_HOSTED_INGEST_URL=https://dozor.example.com/api/v1/ingest
DOZOR_ENV=production
```

Основной агент теперь запускается как **Go-бинарник** (`dozor-agent`, под systemd или внутри Sail). Тот же Laravel PHP SDK продолжает собирать телеметрию и отправлять её на локальный TCP `127.0.0.1:4815`. Команда `php artisan dozor:agent` остаётся прежним PHP daemon для отладки и отката. Подробности: [Go runtime](runtime/go/README.md) и [deployment](runtime/go/deploy/README.md).

### Live debug TUI

Для Go daemon используйте `dozor-agent --debug`; он показывает статус приёма, spool и доставки и восстанавливает терминал после Ctrl+C. Ниже описан legacy PHP debug-режим.

```bash
php artisan dozor:agent --debug
```

С флагом `--debug` на интерактивном терминале появляется отдельный live-экран: приложение, адрес сокета, состояние отправки, количество принятых записей и доставленных батчей, глубина spool-очереди, ошибки доставки и лента событий ingest → queue → upload. Обновление ограничено по частоте, а история событий в памяти ограничена 160 записями. Для остановки — **Ctrl+C** (агент завершит работу и восстановит экран терминала).

В режиме без TTY (Supervisor, systemd, перенаправление вывода, `TERM=dumb`) `--debug` выводит обычный построчный поток событий без ANSI. Тела запросов, содержимое логов приложений, SQL, заголовки и токены **не выводятся**. Это отладка состояния самого агента, а не полный дамп телеметрии.

Без `--debug` поведение команды и её консольный вывод не меняются. Для production по умолчанию используйте обычный `php artisan dozor:agent`: терминальный экран нужен для ручной диагностики, а не для постоянного запуска под менеджером процессов.

Проверка: `php artisan dozor:status --strict`. Команда проверяет локальный агент и заполненность настроек отправки; она **не гарантирует** доставку последних событий. `php artisan dozor:status --json` выдаёт машиночитаемый отчёт.

## Что собираем по умолчанию

- HTTP requests, длительность и коды ответов;
- исключения и события очередей;
- SQL timings без текста SQL;
- исходящие HTTP-вызовы без заголовков.

**По умолчанию не собираются:** тела запросов, входящие и исходящие HTTP-заголовки, содержимое логов, произвольные события приложений, шумные события кеша. Эти режимы можно включить отдельно через `DOZOR_CAPTURE_REQUEST_PAYLOAD`, `DOZOR_CAPTURE_REQUEST_HEADERS`, `DOZOR_CAPTURE_OUTGOING_HTTP_HEADERS` и `DOZOR_CAPTURE_CACHE`. Исключения могут содержать чувствительные значения в сообщениях: передавайте в Dozor только данные своих доверенных приложений.

Шумные маршруты отключаются целиком, вместе с их SQL. `DOZOR_IGNORE_ROUTE_NAMES` и `DOZOR_IGNORE_PATHS` принимают маску `*`. Тот же список задаётся `Dozor::exclude(['api.player.*'])` или посредником `dozor.exclude` на роуте. Повтор задачи очереди имеет статус `released`. Отказы spool видны в heartbeat как `dropped_batches`. Время Inertia SSR не измеряется. Сокеты Reverb не снимаются. Текст SQL по умолчанию не собирается.

Доставка выполняется батчами через локальную очередь (spool). Если remote URL/токен не настроены, данные останутся локальными — `dozor:status --strict` укажет на неполную конфигурацию. Периодически проверяйте использование диска в `storage/app/dozor`.

Дополнительные параметры CLI: `php artisan help dozor:agent`. Примеры systemd-сервиса: `examples/systemd/dozor-agent.service.example`.

Платформа: [pepperfm/dozor](https://github.com/pepperfm/dozor).
