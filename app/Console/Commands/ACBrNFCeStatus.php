<?php

namespace App\Console\Commands;

use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Console\Command;
use Throwable;

final class ACBrNFCeStatus extends Command
{
    protected $signature = 'acbr:nfce-status';
    protected $description = 'Consulta o status NFC-e com a configuração ACBr local (não emite notas)';

    public function handle(): int
    {
        $service = new ACBrNFeService();
        try {
            $service->initialize();
            $service->setConfig('DFe', 'UF', 'BA');
            $service->setConfig('NFe', 'ModeloDF', '1');
            $service->setConfig('NFe', 'Ambiente', '1');
            $service->setConfig('NFe', 'VersaoDF', '3');
            $result = $service->statusServico();
            $this->line('Consulta SEFAZ-BA concluída. Retorno:');
            $this->line($result);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('Consulta não concluída: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $service->close();
        }
    }
}
