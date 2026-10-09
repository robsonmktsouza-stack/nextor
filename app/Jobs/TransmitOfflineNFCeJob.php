<?php

namespace App\Jobs;

use App\Services\Fiscal\NFCeOfflineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class TransmitOfflineNFCeJob implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public int $timeout=180;
    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('fiscal');
    }
    public function handle(NFCeOfflineService $service): void
    {
        $service->transmit($this->documentId);
    }
}
