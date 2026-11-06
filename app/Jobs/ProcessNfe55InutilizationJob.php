<?php

namespace App\Jobs;

use App\Services\Fiscal\Nfe55InutilizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessNfe55InutilizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 180;

    public function __construct(public readonly int $inutilizationId) {}

    public function handle(Nfe55InutilizationService $service): void
    {
        if (!$service->process($this->inutilizationId)) $this->release(10);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }
}
