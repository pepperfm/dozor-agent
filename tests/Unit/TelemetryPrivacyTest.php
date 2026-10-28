<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\DozorContract;
use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Tests\TestCase;
use Dozor\Tracing\TraceBatchTransformer;
use Dozor\Watchers\ApplicationEventWatcher;
use ReflectionMethod;
use RuntimeException;

final class TelemetryPrivacyTest extends TestCase
{
    public function test_outgoing_http_drops_unrecognized_metadata_and_scrubs_credentials(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });
        $core = new Dozor($ingest, ['enabled' => true, 'sampling' => ['outgoing_http' => 1.0]]);

        $core->recordOutgoingHttp([
            'method' => 'GET',
            'url' => 'https://alice:password@service.test/orders?access_token=super-secret',
            'host' => 'service.test',
            'duration_ms' => 20,
            'error_message' => 'Failed at https://service.test/orders?token=super-secret authorization=BearerSecret',
            'request_body' => 'must-never-ship',
            'debug_headers' => ['Authorization' => 'super-secret'],
        ]);

        self::assertCount(1, $records);
        $payload = $records[0]['payload'];
        self::assertSame('https://service.test/orders', $payload['url']);
        self::assertArrayNotHasKey('request_body', $payload);
        self::assertArrayNotHasKey('debug_headers', $payload);
        self::assertStringNotContainsString('super-secret', $payload['error_message']);
        self::assertStringNotContainsString('BearerSecret', $payload['error_message']);
    }

    public function test_exception_messages_do_not_forward_signed_urls(): void
    {
        $records = [];
        $ingest = $this->createMock(IngestContract::class);
        $ingest->method('writeNow')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });
        $core = new Dozor($ingest, ['enabled' => true, 'sampling' => ['exceptions' => 1.0]]);
        $core->recordException(new RuntimeException('Request failed: https://service.test/path?token=super-secret'));

        self::assertCount(1, $records);
        self::assertStringNotContainsString('super-secret', $records[0]['payload']['message']);
    }

    public function test_sql_signature_redacts_postgres_literals_comments_and_identifier_values(): void
    {
        $core = new Dozor($this->createStub(IngestContract::class), ['enabled' => true]);
        $normalize = new ReflectionMethod(Dozor::class, 'normalizeSql');
        $sql = 'select "private-user@example.test" from accounts where secret = $$super-secret$$ '
            . 'and other = $tag$another-secret$tag$ and id = 123 -- private comment';

        $normalized = $normalize->invoke($core, $sql);

        foreach (['private-user@example.test', 'super-secret', 'another-secret', 'private comment', '123'] as $secret) {
            self::assertStringNotContainsString($secret, $normalized);
        }
        self::assertStringContainsString('select', $normalized);
    }

    public function test_application_event_scalar_payloads_are_not_recorded(): void
    {
        $core = $this->createMock(DozorContract::class);
        $core->expects(self::once())->method('recordApplicationEvent')
            ->with('App\Events\Demo', self::callback(static function (array $payload): bool {
                return ($payload['items'][0]['redacted'] ?? false) === true
                    && !array_key_exists('value', $payload['items'][0]);
            }));

        (new ApplicationEventWatcher($core, ['App\Events\']))->handle('App\Events\Demo', ['super-secret']);
    }

    public function test_query_only_fragment_uses_original_trace_epoch_and_offset(): void
    {
        $transformer = new TraceBatchTransformer('App', 'token', 'production', 'host');
        $trace = $transformer->transform([[
            'type' => 'query',
            'trace_id' => str()->ulid()->toString(),
            'record_id' => str()->ulid()->toString(),
            'happened_at' => '2026-10-29T10:00:02+00:00',
            'payload' => [
                'trace_started_at' => '2026-10-29T10:00:00+00:00',
                'start_offset_ms' => 1750,
                'time_ms' => 50,
                'sql' => '[REDACTED_SQL]',
                'normalized_signature' => 'select ?',
            ],
        ]])['traces'][0];

        self::assertSame('2026-10-29T10:00:00+00:00', $trace['started_at']);
        $query = array_values(array_filter(
            $trace['spans'],
            static fn(array $span): bool => $span['kind'] === 'query',
        ));
        self::assertCount(1, $query);
        self::assertSame(1750, $query[0]['start_offset_ms']);
    }
}
