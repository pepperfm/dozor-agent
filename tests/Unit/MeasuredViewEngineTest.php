<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Contracts\DozorContract;
use Dozor\Tests\TestCase;
use Dozor\Tracing\MeasuredViewEngine;
use Illuminate\Contracts\View\Engine;

final class MeasuredViewEngineTest extends TestCase
{
    public function test_it_records_measured_template_duration_without_view_data_or_absolute_path(): void
    {
        $delegate = new class implements Engine {
            public function get($path, array $data = [])
            {
                usleep(2000);

                return '<main>ok</main>';
            }
        };

        $core = $this->createMock(DozorContract::class);
        $core->expects(self::once())->method('recordFrameworkEvent')
            ->with('view.rendered', self::callback(static fn(array $data): bool =>
                $data['view'] === 'page.blade.php'
                && $data['duration_ms'] >= 1
                && !isset($data['contents'])
                && !isset($data['payload'])
            ));

        self::assertSame(
            '<main>ok</main>',
            (new MeasuredViewEngine($delegate, $core))->get('/srv/private/views/page.blade.php', ['password' => 'secret']),
        );
    }

    public function test_it_preserves_view_exceptions_even_when_reporting_fails(): void
    {
        $delegate = new class implements Engine {
            public function get($path, array $data = [])
            {
                throw new \RuntimeException('render failed');
            }
        };
        $core = $this->createStub(DozorContract::class);
        $core->method('recordFrameworkEvent')->willThrowException(new \RuntimeException('telemetry failed'));

        $this->expectExceptionMessage('render failed');
        (new MeasuredViewEngine($delegate, $core))->get('/views/page.blade.php');
    }
}
