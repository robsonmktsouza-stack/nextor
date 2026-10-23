<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Models\IncomingNfeManifestationAttempt;
use App\Services\Fiscal\ACBr\RecipientEventPreparationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IncomingNfeManifestationService
{
    public const EVENTS=[
        '210210'=>'Ciência da operação',
        '210200'=>'Confirmação da operação',
        '210220'=>'Desconhecimento da operação',
        '210240'=>'Operação não realizada',
    ];

    public function __construct(
        private readonly IncomingNfeSyncService $sync,
        private readonly IncomingNfeManifestationGateway $gateway,
        private readonly IncomingNfeManifestationEvidence $evidence,
    ) {}

    public function manifest(IncomingNfe $invoice, string $event, ?string $reason, ?int $userId): array
    {
        if (!isset(self::EVENTS[$event])) {
            throw ValidationException::withMessages(['event_type'=>'Manifestação inválida.']);
        }
        $reason=$event==='210240' ? trim((string)$reason) : null;
        if ($event==='210240' && (mb_strlen($reason)<15 || mb_strlen($reason)>255
            || preg_match('/[\r\n]/',$reason))) {
            throw ValidationException::withMessages(['reason'=>'Informe uma justificativa de 15 a 255 caracteres, em uma linha.']);
        }
        if (!$this->sync->isReady()) return ['status'=>'not_ready'];
        $cnpj=$this->sync->companyCnpj();
        if (!preg_match('/^\d{44}$/',$invoice->access_key) || substr($invoice->access_key,20,2)!=='55') {
            throw ValidationException::withMessages(['event_type'=>'Documento não é uma NF-e modelo 55.']);
        }
        $lock=Cache::lock('incoming-manifest:'.$cnpj.':'.$invoice->access_key,180);
        if (!$lock->get()) return ['status'=>'busy'];
        try {
            $attempt=DB::transaction(function () use ($invoice,$event,$reason,$userId) {
                $locked=IncomingNfe::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($locked->manifestation===$event) return 'already';
                // An interrupted/uncertain transmission must never be retried blindly.
                if ($locked->manifestationAttempts()->whereIn('status',['pending','uncertain'])->exists()) {
                    return 'review';
                }
                return $locked->manifestationAttempts()->create([
                    'event_code'=>$event,'status'=>'pending','reason'=>$reason,'user_id'=>$userId,
                ]);
            },3);
            if (is_string($attempt)) return ['status'=>$attempt];
            $code=null;
            $protocol=null;
            $resultStatus='uncertain';
            $responseMessage='Não foi possível comprovar o registro. Utilize Conferir na SEFAZ.';
            try {
                $raw=$this->gateway->send(CompanySetting::current(),$cnpj,
                    $invoice->access_key,$event,$reason);
                $parsed=$this->parseResponse($raw,$invoice->access_key,$event);
                $code=$parsed['code'];
                $protocol=$parsed['protocol'];
                $resultStatus=$parsed['status'];
                $responseMessage=$parsed['message'];
            } catch (Throwable $error) {
                if ($error instanceof RecipientEventPreparationException) {
                    $resultStatus='failed'; // Somente preparação local, sem transmissão.
                }
                $responseMessage=$this->safeError($error);
                // Never expose ACBr raw diagnostics: they may contain private certificate data.
                Log::warning('Manifestação NF-e: retorno inconclusivo',[
                    'kind'=>get_class($error),'invoice_id'=>$invoice->id,
                ]);
            }
            DB::transaction(function () use ($attempt,$invoice,$event,$resultStatus,$code,$protocol,$responseMessage): void {
                $pending=IncomingNfeManifestationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $pending->update([
                    'status'=>$resultStatus,'sefaz_status'=>$code,'protocol'=>$protocol,
                    'response_message'=>$responseMessage,
                ]);
                if ($resultStatus==='accepted') {
                    IncomingNfe::query()->whereKey($invoice->id)->update([
                        'manifestation'=>$event,'manifestation_protocol'=>$protocol,'manifested_at'=>now(),
                    ]);
                }
            },3);
            return ['status'=>$resultStatus,'code'=>$code,'message'=>$responseMessage];
        } finally {
            $lock->release();
        }
    }

    /** Reconsulta expressa de uma tentativa indeterminada: nenhum reenvio. */
    public function verify(IncomingNfe $invoice): array
    {
        if (!$this->sync->isReady()) return ['status'=>'not_ready'];
        $cnpj=$this->sync->companyCnpj();
        $lock=Cache::lock('incoming-manifest:'.$cnpj.':'.$invoice->access_key,180);
        if(!$lock->get()) return ['status'=>'busy'];
        try {
            $attempt=DB::transaction(function () use ($invoice) {
                $locked=IncomingNfe::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $pending=$locked->manifestationAttempts()
                    ->whereIn('status',['pending','uncertain'])
                    ->orderBy('id')->lockForUpdate()->first();
                if(!$pending) return null;
                if($pending->verification_checked_at
                    && $pending->verification_checked_at->gt(now()->subMinutes(65))) {
                    return 'cooldown';
                }
                // Reserve the interval before contacting the service.
                $pending->update(['verification_checked_at'=>now()]);
                return $pending;
            },3);
            if($attempt===null) return ['status'=>'no_pending'];
            if($attempt==='cooldown') return ['status'=>'cooldown'];
            $message='Consulta sem comprovante correspondente. Envio continua bloqueado.';
            try {
                $raw=$this->gateway->consult(CompanySetting::current(),$cnpj,$invoice->access_key);
                $proof=$this->evidence->consulted($raw,$invoice->access_key,$attempt->event_code,$cnpj);
                if($proof!==null) {
                    DB::transaction(function () use ($attempt,$invoice,$proof): void {
                        $locked=IncomingNfeManifestationAttempt::query()
                            ->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                        if(!in_array($locked->status,['pending','uncertain'],true)) return;
                        $locked->update([
                            'status'=>'accepted','sefaz_status'=>'135',
                            'protocol'=>$proof['protocol'],
                            'response_message'=>'Protocolo localizado na consulta de eventos.',
                        ]);
                        IncomingNfe::query()->whereKey($invoice->id)->update([
                            'manifestation'=>$locked->event_code,
                            'manifestation_protocol'=>$proof['protocol'],'manifested_at'=>now(),
                        ]);
                    },3);
                    return ['status'=>'accepted','protocol'=>$proof['protocol']];
                }
            } catch(Throwable $error) {
                $message=$this->safeError($error);
                Log::warning('Conferência NF-e não comprovada',[
                    'kind'=>get_class($error),'invoice_id'=>$invoice->id,
                ]);
            }
            IncomingNfeManifestationAttempt::query()
                ->whereKey($attempt->id)->whereIn('status',['pending','uncertain'])
                ->update(['status'=>'uncertain','response_message'=>$message]);
            return ['status'=>'unconfirmed','message'=>$message];
        } finally {
            $lock->release();
        }
    }

    /**
     * Erros da DLL podem conter caminhos e dados do INI. Mostrar somente a
     * etapa da API e o código numérico, nunca a mensagem bruta.
     */
    private function safeError(Throwable $error): string
    {
        $message=$error->getMessage();
        if (preg_match('/\\b596\\b/',$message)
            && preg_match('/prazo|rejei[çc][aã]o|10 dias/iu',$message)) {
            return 'A ACBr informou rejeição 596 (evento fora do prazo), mas não forneceu retorno individual verificável.';
        }
        if (preg_match('/ACBr (NFE_[A-Za-z]+) falhou \\((-?\\d+)\\)/',$message,$match)) {
            return 'Falha da ACBr em '.$match[1].' (código '.$match[2].').';
        }
        if(str_contains($message,'Certificado A1 indisponível')) {
            return 'Certificado A1 indisponível ou vencido.';
        }
        if(str_contains($message,'Schemas fiscais não configurados')) {
            return 'Schemas fiscais não configurados.';
        }
        if(str_contains($message,'Call to undefined C function')
            || str_contains($message,'symbol not found')) {
            return 'Biblioteca ACBrLib incompatível com o método solicitado.';
        }
        if(str_contains($message,'Timeout') || str_contains($message,'TimeOut')) {
            return 'A SEFAZ não respondeu dentro do prazo; confira antes de reenviar.';
        }
        return 'Retorno da ACBr inconclusivo. Confira o evento antes de reenviar.';
    }

    public function parseResponse(string $raw,string $key,string $event): array
    {
        return $this->evidence->sent($raw,$key,$event);
    }
}
