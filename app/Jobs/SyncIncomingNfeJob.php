<?php

namespace App\Jobs;

use App\Services\Fiscal\IncomingNfeSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncIncomingNfeJob implements ShouldQueue
{
    use Queueable;

    public int $tries=1;
    public int $timeout=180;
    public bool $failOnTimeout=true;

    public function __construct()
    {
        $this->onQueue('fiscal');
    }

    public function handle(IncomingNfeSyncService $service): void
    {
        try {
            $service->synchronize();
        } catch(Throwable $error){
            Log::warning('Não foi possível consultar NF-e de compras',[
                'reason'=>mb_substr($error->getMessage(),0,300),
            ]);
        }
    }
}
