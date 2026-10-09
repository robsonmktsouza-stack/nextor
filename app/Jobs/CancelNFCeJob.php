<?php

namespace App\Jobs;

use App\Services\Fiscal\NFCeCancellationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CancelNFCeJob implements ShouldQueue
{
    use Queueable;

    public int $tries=1;
    public int $timeout=120;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('fiscal');
    }

    public function handle(NFCeCancellationService $service): void
    {
        $service->process($this->documentId);
    }
}
