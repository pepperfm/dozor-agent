<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Hooks\OctaneLifecycle;
use Dozor\Tests\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OctaneLifecycleTest extends TestCase
{
    public function test_register_is_quiet_when_octane_is_absent(): void
    {
        $core = new Dozor($this->createStub(IngestContract::class), ['enabled' => true]);

        new OctaneLifecycle()->register($core);

        self::assertFalse(class_exists('Laravel\\Octane\\Events\\RequestReceived'));
    }

    public function test_worker_boundary_drops_a_hanging_request_without_a_payload(): void
    {
        $records = [];
        $ingest = $this->createStub(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });
        $core = new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
        $core->beginRequest(Request::create('/old', 'GET'), microtime(true));

        (new OctaneLifecycle())->workerBoundary($core);

        self::assertFalse($core->hasActiveRequestTrace());
        self::assertSame([], $records);
    }

    public function test_request_terminated_finishes_once(): void
    {
        $records = [];
        $ingest = $this->createStub(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });
        $core = new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
        $request = Request::create('/orders', 'GET');
        $request->server->set('REQUEST_TIME_FLOAT', (string) microtime(true));
        $request->attributes->set('dozor.pending_exception', new \RuntimeException('before controller'));
        $core->beginRequest($request, microtime(true));
        $hooks = new OctaneLifecycle();
        $response = new Response('nope', 500);

        $hooks->requestTerminated($core, $request, $response);
        $hooks->requestTerminated($core, $request, $response);

        $requests = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'request',
        ));
        $exceptions = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'exception',
        ));
        self::assertCount(1, $requests);
        self::assertSame(500, $requests[0]['payload']['status']);
        self::assertCount(1, $exceptions);
        self::assertSame(\RuntimeException::class, $exceptions[0]['payload']['class']);
        self::assertFalse($core->hasActiveRequestTrace());
    }
}
