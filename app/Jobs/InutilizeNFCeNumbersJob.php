<?php

namespace App\Jobs;

use App\Services\Fiscal\NFCeInutilizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class InutilizeNFCeNumbersJob implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public int $timeout=120;
    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('fiscal');
    }
    public function handle(NFCeInutilizationService $service): void
    {
        $service->process($this->requestId);
    }
}
