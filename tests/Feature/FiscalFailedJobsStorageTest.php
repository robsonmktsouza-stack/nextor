<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FiscalFailedJobsStorageTest extends TestCase
{
    use RefreshDatabase;

    private const UUID='7b810448-f776-48d5-956e-016bf6543210';

    private function provider(): DatabaseUuidFailedJobProvider
    {
        self::assertSame('database-uuids',config('queue.failed.driver'));
        self::assertSame('failed_jobs',config('queue.failed.table'));
        $provider=$this->app->make('queue.failer');
        self::assertInstanceOf(DatabaseUuidFailedJobProvider::class,$provider);

        return $provider;
    }

    private function recordFailure(DatabaseUuidFailedJobProvider $provider): string
    {
        return $provider->log('database','fiscal',json_encode([
            'uuid'=>self::UUID,
            'displayName'=>'App\\Jobs\\ProcessNfe55Job',
            'job'=>'Illuminate\\Queue\\CallQueuedHandler@call',
            'data'=>['fixture'=>'synthetic fiscal failure'],
        ],JSON_THROW_ON_ERROR),new \RuntimeException('Synthetic fiscal worker failure'));
    }

    public function test_configured_failed_job_provider_records_and_reads_fiscal_worker_failure(): void
    {
        self::assertTrue(Schema::hasTable('failed_jobs'));
        $provider=$this->provider();
        self::assertSame(self::UUID,$this->recordFailure($provider));

        $failure=$provider->find(self::UUID);
        self::assertNotNull($failure);
        self::assertSame('database',$failure->connection);
        self::assertSame('fiscal',$failure->queue);
        self::assertSame(self::UUID,json_decode($failure->payload,true,512,JSON_THROW_ON_ERROR)['uuid']);
        self::assertStringContainsString('Synthetic fiscal worker failure',$failure->exception);
        self::assertNotEmpty($failure->failed_at);
        self::assertSame([self::UUID],$provider->ids('fiscal'));
    }

    public function test_reapplying_or_rolling_back_migration_preserves_failed_job_history(): void
    {
        $provider=$this->provider();
        $this->recordFailure($provider);
        $migration=require database_path('migrations/2026_10_25_000005_create_failed_jobs_table.php');

        $migration->up();
        $migration->down();

        self::assertTrue(Schema::hasTable('failed_jobs'));
        self::assertNotNull($provider->find(self::UUID));
        self::assertSame([self::UUID],$provider->ids('fiscal'));
    }

    public function test_failed_job_uuid_remains_unique(): void
    {
        $provider=$this->provider();
        $this->recordFailure($provider);

        $this->expectException(QueryException::class);
        $this->recordFailure($provider);
    }
}
