<?php

namespace App\Console\Commands;

use App\Services\License\CentralLicenseService;
use Illuminate\Console\Command;

final class CentralLicenseSync extends Command
{
    protected $signature = 'lumeron:license-sync';
    protected $description = 'Consulta a licença comercial assinada da instalação sem enviar dados fiscais.';

    public function handle(CentralLicenseService $service): int
    {
        $state = $service->refresh();
        $this->line('Licença da instalação: '.($state['status'] ?? 'indefinida')
            .' / origem: '.($state['source'] ?? 'desativada'));
        return in_array($state['status'] ?? null, ['active', 'suspended', 'expired', 'disabled'], true)
            ? self::SUCCESS : self::FAILURE;
    }
}
