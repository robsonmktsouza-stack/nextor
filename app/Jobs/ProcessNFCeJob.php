<?php

namespace App\Jobs;

use App\Services\Fiscal\NFCeTransmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessNFCeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 180;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $fiscalDocumentJobId)
    {
        $this->onQueue('fiscal');
    }

    public function handle(NFCeTransmissionService $processor): void
    {
        $processor->process($this->fiscalDocumentJobId);
    }
}
