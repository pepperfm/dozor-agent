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

Запуск локального агента: `php artisan dozor:agent` (на production держать под systemd). Встроенный адрес TCP `127.0.0.1:4815` доступен только локально, удалённый адрес — это **HTTPS ingest URL**, не локальный сокет.

Проверка: `php artisan dozor:status --strict`. Команда проверяет локальный агент и заполненность настроек отправки; она **не гарантирует** доставку последних событий. `php artisan dozor:status --json` выдаёт машиночитаемый отчёт.

## Что собираем по умолчанию

- HTTP requests, длительность и коды ответов;
- исключения и события очередей;
- SQL timings без текста SQL;
- исходящие HTTP-вызовы без заголовков.

**По умолчанию не собираются:** тела запросов, входящие и исходящие HTTP-заголовки, содержимое логов, произвольные события приложений, шумные события кеша. Эти режимы можно включить отдельно через `DOZOR_CAPTURE_REQUEST_PAYLOAD`, `DOZOR_CAPTURE_REQUEST_HEADERS`, `DOZOR_CAPTURE_OUTGOING_HTTP_HEADERS` и `DOZOR_CAPTURE_CACHE`. Исключения могут содержать чувствительные значения в сообщениях: передавайте в Dozor только данные своих доверенных приложений.

Доставка выполняется батчами через локальную очередь (spool). Если remote URL/токен не настроены, данные останутся локальными — `dozor:status --strict` укажет на неполную конфигурацию. Периодически проверяйте использование диска в `storage/app/dozor`.

Дополнительные параметры CLI: `php artisan help dozor:agent`. Примеры systemd-сервиса: `examples/systemd/dozor-agent.service.example`.

Платформа: [pepperfm/dozor](https://github.com/pepperfm/dozor).
