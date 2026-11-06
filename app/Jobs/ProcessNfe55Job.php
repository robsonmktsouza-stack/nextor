<?php

namespace App\Jobs;

use App\Services\Fiscal\Nfe55EmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessNfe55Job implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    // Lock contention may release this job; external work is still claimed once
    // by the service before any native call and never replayed on exceptions.
    public int $tries=0;
    public int $maxExceptions=1;
    public int $timeout=180;

    public function __construct(public readonly int $draftId) {}

    public function handle(Nfe55EmissionService $service): void
    {
        if (!$service->process($this->draftId)) $this->release(10);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }
}
