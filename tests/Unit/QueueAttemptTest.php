<?php

declare(strict_types=1);

namespace Dozor\Tests\Unit;

use Dozor\Context\TraceContext;
use Dozor\Contracts\IngestContract;
use Dozor\Dozor;
use Dozor\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;

final class QueueAttemptTest extends TestCase
{
    public function test_released_attempt_is_one_record_and_failed_is_separate(): void
    {
        $records = [];
        $core = $this->dozor($records);
        $job = $this->job('App\\Jobs\\SendReceipt', 'job-1', 2);
        $core->recordJobStarted(new JobProcessing('redis', $job));
        $core->recordJobReleased(new JobReleasedAfterException('redis', $job, 1, new \RuntimeException('retry')));

        $jobs = $this->ofType($records, 'job');
        $exceptions = $this->ofType($records, 'exception');
        self::assertCount(1, $jobs);
        self::assertSame('released', $jobs[0]['payload']['status']);
        self::assertSame(2, $jobs[0]['payload']['attempts']);
        self::assertCount(1, $exceptions);
        self::assertNull($core->currentTrace);

        $records = [];
        $core = $this->dozor($records);
        $failed = $this->job('App\\Jobs\\SendReceipt', 'job-2', 3);
        $core->recordJobStarted(new JobProcessing('redis', $failed));
        $core->recordJobFinished(new JobFailed('redis', $failed, new \RuntimeException('dead')));
        $core->noteJobAttempt(new JobAttempted('redis', $failed, new \RuntimeException('dead'), 10));

        $jobs = $this->ofType($records, 'job');
        self::assertCount(1, $jobs);
        self::assertSame('failed', $jobs[0]['payload']['status']);
    }

    public function test_successful_attempt_does_not_add_a_second_job_record(): void
    {
        $records = [];
        $core = $this->dozor($records);
        $job = $this->job('App\\Jobs\\SendReceipt', 'job-3', 1);
        $core->recordJobStarted(new JobProcessing('redis', $job));
        $core->recordJobFinished(new JobProcessed('redis', $job));
        $core->noteJobAttempt(new JobAttempted('redis', $job, null, 4));

        $jobs = $this->ofType($records, 'job');
        self::assertCount(1, $jobs);
        self::assertSame('processed', $jobs[0]['payload']['status']);
        self::assertArrayNotHasKey('broadcast', $jobs[0]['payload']);
    }

    public function test_broadcast_job_is_marked_without_a_payload(): void
    {
        $records = [];
        $core = $this->dozor($records);
        $job = $this->job('Illuminate\\Broadcasting\\BroadcastEvent', 'job-4', 1);
        $core->recordJobStarted(new JobProcessing('redis', $job));
        $core->recordJobFinished(new JobProcessed('redis', $job));

        $jobs = $this->ofType($records, 'job');
        self::assertCount(1, $jobs);
        self::assertTrue($jobs[0]['payload']['broadcast']);
        self::assertSame('BroadcastEvent', $jobs[0]['payload']['broadcast_name']);
        self::assertArrayNotHasKey('payload', $jobs[0]['payload']);
    }

    public function test_job_inside_a_request_keeps_the_start_sampling_decision(): void
    {
        $records = [];
        $ingest = $this->createStub(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });
        $core = new Dozor($ingest, [
            'enabled' => true,
            'app_name' => 'test',
            'sampling' => ['jobs' => 0.0],
        ]);
        $core->currentTrace = new TraceContext('http-trace', microtime(true));
        $job = $this->job('App\\Jobs\\SendReceipt', 'job-5', 1);
        $core->recordJobStarted(new JobProcessing('redis', $job));
        $core->recordJobFinished(new JobProcessed('redis', $job));

        self::assertSame([], $this->ofType($records, 'job'));
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function dozor(array &$records): Dozor
    {
        $ingest = $this->createStub(IngestContract::class);
        $ingest->method('write')->willReturnCallback(static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        return new Dozor($ingest, ['enabled' => true, 'app_name' => 'test']);
    }

    private function job(string $name, string $id, int $attempts): Job
    {
        $job = $this->createStub(Job::class);
        $job->method('resolveName')->willReturn($name);
        $job->method('getQueue')->willReturn('default');
        $job->method('attempts')->willReturn($attempts);
        $job->method('uuid')->willReturn($id);

        return $job;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function ofType(array $records, string $type): array
    {
        return array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === $type,
        ));
    }
}
