<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Tests\TestCase;
use Dozor\Tracing\RequestLifecycleStage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DozorLifecycleEndStageTest extends TestCase
{
    public function test_finish_request_closes_lifecycle_with_end_stage(): void
    {
        $capturedRequestRecord = null;

        $ingest = $this->createMock(IngestContract::class);
        $ingest->expects($this->once())
            ->method('write')
            ->willReturnCallback(static function (array $record) use (&$capturedRequestRecord): void {
                if (($record['type'] ?? null) === 'request') {
                    $capturedRequestRecord = $record;
                }
            });

        $dozor = new Dozor($ingest, [
            'enabled' => true,
            'token' => 'token',
            'app_name' => 'app',
            'environment' => 'test',
            'server' => 'node-1',
        ]);

        $request = Request::create('/end-stage', 'GET');
        $startedAt = microtime(true) - 0.02;

        $dozor->beginRequest($request, $startedAt);
        $dozor->transitionLifecycleStage(RequestLifecycleStage::BeforeMiddleware->value);
        $dozor->transitionLifecycleStage(RequestLifecycleStage::Action->value);
        $dozor->finishRequest($request, new Response('ok', 200), $startedAt);

        self::assertIsArray($capturedRequestRecord);
        $payload = $capturedRequestRecord['payload'] ?? null;
        self::assertIsArray($payload);
        $lifecycleStages = $payload['lifecycle_stages'] ?? null;
        self::assertIsArray($lifecycleStages);

        $stageNames = array_map(
            static fn(array $stage): string => (string) ($stage['name'] ?? ''),
            array_filter($lifecycleStages, static fn(mixed $stage): bool => is_array($stage)),
        );

        self::assertContains(RequestLifecycleStage::End->value, $stageNames);
        self::assertSame(RequestLifecycleStage::End->value, (string) end($stageNames));
    }
}
