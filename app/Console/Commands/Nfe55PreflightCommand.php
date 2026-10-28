<?php

namespace App\Console\Commands;

use App\Models\NfeDraft;
use App\Services\Fiscal\Nfe55PreflightService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ferramenta de desenvolvimento/suporte: verifica XML e assinatura no mesmo
 * PHP CLI do worker fiscal. Nunca transmite, reserva numero ou imprime XML.
 */
final class Nfe55PreflightCommand extends Command
{
    protected $signature = 'nfe:preflight {id : Identificador do rascunho de NF-e}';
    protected $description = 'Confere XML e assinatura da NF-e no ambiente configurado, sem transmitir a SEFAZ';

    public function handle(Nfe55PreflightService $service): int
    {
        $id=(string)$this->argument('id');
        if(!ctype_digit($id) || (int)$id < 1) {
            $this->error('Informe o numero valido do rascunho.');
            return self::FAILURE;
        }

        $draft=NfeDraft::query()->find((int)$id);
        if(!$draft) {
            $this->error('A NF-e informada nao foi encontrada.');
            return self::FAILURE;
        }
        $this->line('Conferindo NF-e em '.$draft->environment.'. Nenhuma nota sera transmitida.');
        try {
            $result=$service->check($draft);
            $this->info('Verificacao concluida: XML validado e assinatura conferida.');
            $this->line('Modelo: '.$result['model'].' | Numero de teste: '.$result['tested_number']);
            $this->line('Numero fiscal nao reservado. Documento original preservado.');
            return self::SUCCESS;
        } catch(ValidationException $exception) {
            foreach($exception->errors() as $messages) {
                foreach($messages as $message) $this->error($message);
            }
            return self::FAILURE;
        } catch(Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('NF-e: conferência local interrompida',[
                'draft_id'=>$draft->id,
                'type'=>get_class($exception),
                'reason'=>mb_substr($exception->getMessage(),0,1000),
            ]);
            $this->error('Nao foi possivel concluir a verificacao local.');
            $this->line('Consulte o log interno do Lumeron para detalhes do diagnostico.');
            return self::FAILURE;
        }
    }
}
