# Сравнение производительности PHP и Go daemon

Сначала прогоните PHP baseline с **отдельным тестовым портом и spool**, затем Go. Не используйте production-токены и production spool.

Go (конкурентный бенчмарк, подтверждение после fsync):
```bash
cd runtime/go
go test -run '^$' -bench BenchmarkACK -benchtime 5s -benchmem ./internal/benchmark
```

PHP (последовательный baseline: 1000 записей; запускать при включённом тестовом PHP daemon):
```bash
DOZOR_INGEST_URI=127.0.0.1:4816 DOZOR_TOKEN=benchmark php runtime/go/benchmarks/php-ack.php 1000
```

Сценарии не полностью идентичны по конкурентности: Go использует `RunParallel`, PHP — последовательные подключения. Не сравнивайте throughput численно как apples-to-apples без одинаковой нагрузки. Для обоих измеряйте p50/p95/p99 ACK, размер spool, CPU/RSS и задержку HTTP доставки. В CI производительный benchmark **не запускается**, чтобы не выдавать перегруженный shared runner за реальный baseline.
