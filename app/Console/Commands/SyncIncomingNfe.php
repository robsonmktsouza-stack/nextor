<?php

namespace App\Console\Commands;

use App\Services\Fiscal\IncomingNfeSyncService;
use Illuminate\Console\Command;

final class SyncIncomingNfe extends Command
{
    protected $signature='fiscal:sync-incoming-nfe';
    protected $description='Consulta periodicamente NF-e recebidas pela empresa na distribuição DF-e';

    public function handle(IncomingNfeSyncService $sync): int
    {
        $result=$sync->synchronize(true);
        if($result['status']==='disabled') {
            $this->components->info('Consulta automática desativada nesta empresa.');
            return self::SUCCESS;
        }
        if($result['status']==='error') {
            $this->components->warn('Consulta fiscal indisponível. O intervalo de segurança foi preservado.');
            return self::FAILURE;
        }
        $this->components->info('Consulta de notas recebidas: '.$result['status'].'.');
        return self::SUCCESS;
    }
}
