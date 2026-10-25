<?php

namespace App\Console\Commands;

use App\Models\NfeDraft;
use App\Services\Fiscal\Nfe55DanfeService;
use App\Services\Fiscal\Nfe55EmissionService;
use App\Services\Fiscal\Nfe55PreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Explicit local homologation verification; uses only the existing fiscal worker. */
final class Nfe55VerifyFlowCommand extends Command
{
    protected $signature = 'nfe:verify-flow {id : Identificador da NF-e}
        {--enqueue : Agenda emissão em homologação pela fila fiscal existente}
        {--wait=0 : Segundos para acompanhar o worker existente (0 a 300)}
        {--consult : Consulta a SEFAZ pela chave já existente}';

    protected $description = 'Verifica o fluxo real de uma NF-e de homologação e preserva evidências privadas';

    public function handle(Nfe55PreflightService $preflight, Nfe55EmissionService $emission, Nfe55DanfeService $danfe): int
    {
        $id = (string)$this->argument('id');
        $wait = (string)$this->option('wait');
        if (!ctype_digit($id) || (int)$id < 1 || !ctype_digit($wait) || (int)$wait > 300) {
            $this->error('Informe ID válido e --wait entre 0 e 300 segundos.');
            return self::FAILURE;
        }
        $draft = NfeDraft::query()->find((int)$id);
        if (!$draft || $draft->environment !== 'homologation') {
            $this->error('Comando exclusivo para NF-e existente em homologação. Produção recusada.');
            return self::FAILURE;
        }
        $summary = ['draft_id' => $draft->id, 'environment' => 'homologation', 'stages' => [], 'full_flow_verified' => false];
        $base = 'fiscal/nfe55/'.$draft->id;
        try {
            $this->line('NF-e '.$draft->id.' | Homologação | Estado: '.$draft->status);
            $reserved = $draft->document_number !== null || $draft->access_key !== null
                || $draft->transmission_attempted_at !== null || $draft->protocol !== null;
            if ($draft->status === 'draft' && !$reserved && (!$this->option('consult') || $this->option('enqueue'))) {
                if ($this->option('enqueue')) {
                    // queue checks validation, native preflight, fingerprint and number reservation.
                    $draft = $emission->queue($draft);
                    $summary['stages'][] = 'queued_existing_fiscal_worker';
                    $this->info('Emissão agendada na fila fiscal existente. Nenhum worker foi iniciado.');
                } else {
                    $preflight->check($draft);
                    $summary['stages'][] = 'native_xml_signature_preflight';
                    $this->info('Pré-validação nativa de XML e assinatura concluída; nenhuma emissão agendada.');
                }
            } elseif ($this->option('enqueue')) {
                $this->line('Documento já reservado ou fora de rascunho: nenhuma nova emissão foi agendada.');
            }

            $deadline = microtime(true) + (int)$wait;
            while (in_array($draft->status, ['queued', 'processing', 'pending'], true) && microtime(true) < $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) break;
                usleep((int)(min(2, $remaining) * 1000000));
                $draft->refresh();
                if ($draft->environment !== 'homologation') throw new RuntimeException('Ambiente alterado durante acompanhamento.');
            }
            if ($this->option('consult')) {
                if (!in_array($draft->status, ['processing', 'pending', 'authorized', 'rejected', 'cancelled'], true)
                    || !preg_match('/^\d{44}$/', (string)$draft->access_key)) {
                    throw ValidationException::withMessages(['nfe' => 'Consulta exige estado permitido e chave fiscal existente; nenhuma transmissão foi feita.']);
                }
                $draft = $emission->consult($draft);
                $summary['stages'][] = 'sefaz_consultation_completed';
                $this->info('Consulta SEFAZ concluída pelo serviço fiscal existente.');
            }

            $code = self::SUCCESS;
            if ($draft->status === 'authorized') {
                if (!$draft->authorized_at || !preg_match('/^\d{44}$/', (string)$draft->access_key)
                    || !preg_match('/^\d{15}$/', (string)$draft->protocol)
                    || $draft->xml_path !== $base.'/authorized.xml') {
                    throw new RuntimeException('Evidências locais de autorização incompletas.');
                }
                // The existing service checks nfeProc, DTD/entities, issuer, model,
                // environment, access key, protocol and SEFAZ authorization status.
                $pdf = $danfe->generate($draft);
                if (!Storage::disk('local')->put($base.'/verified-danfe.pdf', $pdf)) {
                    throw new RuntimeException('Não foi possível preservar DANFE privado.');
                }
                $summary['stages'][] = 'authorized_xml_protocol_verified';
                $summary['stages'][] = 'danfe_generated';
                $summary['danfe_sha256'] = hash('sha256', $pdf);
                $summary['full_flow_verified'] = true;
                $this->info('Autorização registrada, XML/protocolo conferidos e DANFE gerado.');
                $this->line('PDF privado: '.$base.'/verified-danfe.pdf');
            } elseif (in_array($draft->status, ['rejected', 'prepared', 'cancelled'], true)) {
                $this->warn('Estado final: '.$draft->status.'. Fluxo de autorização não concluído.');
                if ($draft->status === 'prepared') $this->line('Confira o erro no ERP e use a ação existente de reagendamento manual; este comando não repete o envio.');
                $code = self::FAILURE;
            } elseif ($draft->status !== 'draft' || $reserved) {
                $this->line('Estado atual: '.$draft->status.'. Autorização ainda não comprovada; acompanhe o worker fiscal existente.');
                $code = 2;
            }
            $this->saveSummary($draft, $base, $summary);
            return $code;
        } catch (Throwable $exception) {
            $summary['failure_type'] = get_class($exception);
            Log::warning('NF-e: verificação local do fluxo interrompida', ['draft_id' => $draft->id, 'exception_type' => get_class($exception)]);
            if ($exception instanceof ValidationException) {
                foreach ($exception->errors() as $messages) foreach ($messages as $message) $this->error($message);
            } else {
                $this->error('Verificação interrompida. Confira o serviço fiscal e as evidências privadas; autorização não comprovada por este comando.');
            }
            try { $this->saveSummary($draft, $base, $summary); } catch (Throwable) {
                $this->error('Não foi possível preservar o resumo privado da verificação.');
            }
            return self::FAILURE;
        }
    }

    private function saveSummary(NfeDraft $draft, string $base, array $summary): void
    {
        $summary['status'] = $draft->fresh()->status;
        $summary['verified_at'] = now()->toIso8601String();
        if (!Storage::disk('local')->put($base.'/verification.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Resumo privado indisponível.');
        }
        $this->line('Resumo privado: '.$base.'/verification.json');
    }
}
