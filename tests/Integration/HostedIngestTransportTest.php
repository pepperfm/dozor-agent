<?php

declare(strict_types=1);

namespace Dozor\Tests\Integration;

use Dozor\Agent\Transport\HostedIngestTransport;
use Dozor\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class HostedIngestTransportTest extends TestCase
{
    public function test_it_ships_payload_to_hosted_ingest(): void
    {
        Http::fake([
            'dozor.test/*' => Http::response(['ok' => true], 202),
        ]);

        $transport = new HostedIngestTransport(
            ingestUrl: 'http://dozor.test/ingest',
            ingestToken: 'agent-token',
            connectionTimeout: 1.0,
            timeout: 1.0,
            retryAttempts: 1,
            retryBackoffMs: 1,
        );

        $shipped = $transport->ship(
            payload: ['traces' => [['id' => 'trace-1']]],
            batchId: 'batch-1',
            attempt: 1,
        );

        self::assertTrue($shipped);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'http://dozor.test/ingest'
                && $request->hasHeader('Authorization', 'Bearer agent-token')
                && $data['traces'][0]['id'] === 'trace-1';
        });
    }

    public function test_it_retries_after_connection_failure(): void
    {
        Http::fake([
            'dozor.test/*' => Http::sequence()
                ->pushFailedConnection('upstream unavailable')
                ->push(['ok' => true], 200),
        ]);

        $transport = new HostedIngestTransport(
            ingestUrl: 'http://dozor.test/ingest',
            ingestToken: null,
            connectionTimeout: 1.0,
            timeout: 1.0,
            retryAttempts: 2,
            retryBackoffMs: 1,
        );

        $shipped = $transport->ship(
            payload: ['traces' => [['id' => 'trace-retry']]],
            batchId: 'batch-retry',
            attempt: 1,
        );

        self::assertTrue($shipped);
        Http::assertSentCount(2);
    }
}
