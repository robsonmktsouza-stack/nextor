<?php

namespace App\Console\Commands;

use App\Fiscal\Contracts\FiscalEngineInterface;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Sefaz\Exceptions\SefazException;
use Illuminate\Console\Command;
use Throwable;

class FiscalSefazStatusCommand extends Command
{
    protected $signature = 'fiscal:sefaz-status {company : ID da empresa fiscal}';

    protected $description = 'Consulta o status real do autorizador NFC-e em homologação';

    public function handle(FiscalEngineInterface $engine): int
    {
        $company = FiscalCompany::query()->find((int) $this->argument('company'));

        if (!$company) {
            $this->error('Empresa fiscal não encontrada.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('NEXTOR FISCAL — STATUS SEFAZ');
        $this->newLine();
        $this->line('Empresa: '.$company->legal_name);
        $this->line('UF: '.$company->uf);
        $this->line('Ambiente: '.$company->environment->name);

        try {
            $result = $engine->statusService($company);
        } catch (SefazException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Falha inesperada na consulta de status SEFAZ.');

            return self::FAILURE;
        }

        $this->line('Autorizador: '.$result->authorizer);
        $this->line('Serviço: NfeStatusServico');
        $this->line('Endpoint: '.$result->endpointUrl);
        $this->newLine();
        $this->line('HTTP: '.$result->httpStatus);
        $this->line('cStat: '.$result->cStat);
        $this->line('xMotivo: '.$result->xMotivo);
        $this->line('Tempo: '.$result->responseTimeMs.' ms');
        $this->line('Tentativa: '.$result->attemptUuid);

        return self::SUCCESS;
    }
}
