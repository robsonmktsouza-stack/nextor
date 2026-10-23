<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Services\Fiscal\ACBr\RecipientEventPreparationException;
use App\Services\Fiscal\IncomingNfeManifestationGateway;
use App\Services\Fiscal\IncomingNfeSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Preflight sem transmissão: valida a mesma DLL, os mesmos schemas, certificado
 * e INI utilizados no envio. Não altera manifestações nem acessa o WebService.
 */
final class DiagnoseRecipientManifestation extends Command
{
    protected $signature='fiscal:manifestation-doctor
        {invoice : ID da nota em Compras}
        {--event=210210 : Evento de preflight, sem transmissão (210210, 210200, 210220)}';

    protected $description='Confere carregamento local do evento na ACBrLib sem transmitir à SEFAZ.';

    public function handle(IncomingNfeSyncService $sync, IncomingNfeManifestationGateway $gateway): int
    {
        $id=filter_var($this->argument('invoice'),FILTER_VALIDATE_INT);
        if (!$id || $id<1) {
            $this->error('Informe o ID numérico de uma nota recebida.');
            return self::FAILURE;
        }
        $code=(string)$this->option('event');
        if (!in_array($code,['210210','210200','210220'],true)) {
            $this->error('Para o preflight, use 210210, 210200 ou 210220.');
            return self::FAILURE;
        }
        $invoice=IncomingNfe::query()->find($id);
        if (!$invoice || !preg_match('/^\d{44}$/',$invoice->access_key)) {
            $this->error('Nota de entrada não encontrada ou com chave inválida.');
            return self::FAILURE;
        }
        $this->line('Diagnóstico local de manifestação NF-e (sem envio à SEFAZ).');
        try {
            $cnpj=$sync->companyCnpj();
            if (!$sync->isReady()) {
                $this->error('Certificado A1 indisponível em Configurações → Fiscal.');
                return self::FAILURE;
            }
            $gateway->diagnose(CompanySetting::current(),$cnpj,$invoice->access_key,$code);
        } catch (Throwable $error) {
            $message=$error->getMessage();
            if (preg_match('/NFE_CarregarEventoINI falhou \((-?\d+)\)/',$message,$matches)) {
                $this->error('A ACBrLib não carregou o INI do evento (código '.$matches[1].').');
                // Só atribuir erro ao formato de data quando a DLL trouxer
                // uma mensagem explícita. Termos genéricos não comprovam a causa.
                if (preg_match('/not a valid date format|invalid date(?:time)?|formato (?:de )?data|data(?:\/hora)? inv[aá]lida/iu',$message)) {
                    $this->warn('Detalhe da ACBr: formato de data/hora inválido.');
                }
            } elseif (preg_match('/NFE_([A-Za-z]+) falhou \((-?\d+)\)/',$message,$matches)) {
                $this->error('Falha da ACBrLib no método NFE_'.$matches[1].' (código '.$matches[2].').');
            } elseif (str_contains($message,'Certificado A1 indisponível')) {
                $this->error('Certificado A1 indisponível.');
            } elseif (str_contains($message,'Schemas fiscais')) {
                $this->error('Schemas fiscais não configurados.');
            } else {
                $this->error('A ACBrLib não concluiu a preparação local do evento.');
            }
            // Nunca imprimir retorno nativo, tokens, senha, XML ou arquivo PFX.
            return self::FAILURE;
        }
        $this->info('INI do evento carregado com sucesso pela ACBrLib.');
        $this->line('Nenhum evento enviado à SEFAZ. Nenhuma situação fiscal alterada.');
        return self::SUCCESS;
    }
}
