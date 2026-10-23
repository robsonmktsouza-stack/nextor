<?php

namespace App\Console\Commands;

use App\Models\IncomingNfeManifestationAttempt;
use App\Services\Fiscal\IncomingNfeManifestationService;
use App\Services\Fiscal\IncomingNfeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconcilia eventos incertos via consulta SOMENTE leitura.
 * Uma consulta a cada 65 minutos por instalação, mesmo com várias notas.
 */
final class VerifyIncomingNfeManifestations extends Command
{
    protected $signature = 'fiscal:verify-incoming-manifestations';

    protected $description = 'Confere automaticamente manifestações pendentes sem retransmitir eventos.';

    public function handle(IncomingNfeSyncService $sync, IncomingNfeManifestationService $service): int
    {
        try {
            if (!$sync->isReady()) return self::SUCCESS;
            $cnpj=$sync->companyCnpj();
            $lock=Cache::lock('incoming-manifest-schedule:'.$cnpj,180);
            if (!$lock->get()) return self::SUCCESS;
            try {
                $last=IncomingNfeManifestationAttempt::query()
                    ->whereNotNull('verification_checked_at')->max('verification_checked_at');
                if ($last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subMinutes(65))) {
                    return self::SUCCESS;
                }
                $attempt=IncomingNfeManifestationAttempt::query()
                    ->whereIn('status',['pending','uncertain'])
                    ->where(function ($q) {
                        $q->whereNull('verification_checked_at')
                            ->orWhere('verification_checked_at','<=',now()->subMinutes(65));
                    })
                    ->whereHas('invoice')
                    ->with('invoice')
                    ->orderBy('verification_checked_at')
                    ->orderBy('id')
                    ->first();
                if (!$attempt || !$attempt->invoice) return self::SUCCESS;
                $result=$service->verify($attempt->invoice);
                $status=(string)($result['status']??'unconfirmed');
                $this->line('Conferência automática de NF-e #'.$attempt->invoice->id.': '.$status.'.');
                // Nunca tentar uma segunda nota imediatamente, nem enviar evento.
                return self::SUCCESS;
            } finally {
                $lock->release();
            }
        } catch (Throwable $error) {
            // Não expor configuração do certificado ou retorno bruto da DLL.
            Log::warning('Falha na conferência automática de manifestações',[
                'kind'=>get_class($error),
            ]);
            $this->warn('Conferência automática indisponível; nenhuma manifestação foi reenviada.');
            return self::FAILURE;
        }
    }
}
