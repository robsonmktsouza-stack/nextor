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
            $schemaCandidates = [
                'C:/laragon/acbr/dep/Schemas/NFe',
                'C:/laragon/acbr/Schemas/NFe',
                'C:/laragon/acbr/dep/Schemas',
                'C:/laragon/acbr/Schemas',
            ];
            $schemas = (string) env('ACBr_NFE_SCHEMAS_PATH', '');
            if ($schemas === '') {
                foreach ($schemaCandidates as $candidate) {
                    if (is_dir($candidate)) {
                        $schemas = $candidate;
                        break;
                    }
                }
            }
            if ($schemas === '' || !is_dir($schemas)) {
                throw new \RuntimeException('Schemas NFe não encontrados. Configure ACBr_NFE_SCHEMAS_PATH no .env com a pasta dos arquivos XSD.');
            }
            $service->setConfig('NFe', 'PathSchemas', $schemas);
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
