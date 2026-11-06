<?php

namespace App\Jobs;

use App\Services\Fiscal\Nfe55CancellationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessNfe55CancellationJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries=0;
    public int $maxExceptions=1;
    public int $timeout=180;

    public function __construct(public readonly int $draftId) {}

    public function handle(Nfe55CancellationService $service): void
    {
        if (!$service->process($this->draftId)) $this->release(10);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }
}
