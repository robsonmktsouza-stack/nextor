<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class NFCeCancellationService
{
    public function request(FiscalDocumentJob $job,string $reason): FiscalDocumentJob
    {
        $reason=trim($reason);
        return DB::transaction(function () use($job,$reason) {
            $document=FiscalDocumentJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($document->document_type!=='nfce'||$document->status!=='authorized'
                || preg_match('/^\d{44}$/',(string)$document->access_key)!==1
                || preg_match('/^\d{15}$/',(string)$document->protocol)!==1) {
                throw ValidationException::withMessages(['reason'=>'Somente NFC-e autorizada, com chave e protocolo válidos, pode ser cancelada.']);
            }
            if ($document->cancellation_status||$document->cancelled_at) {
                throw ValidationException::withMessages(['reason'=>'Já existe solicitação de cancelamento. Consulte o resultado antes de agir.']);
            }
            if (mb_strlen($reason)<15||mb_strlen($reason)>255) {
                throw ValidationException::withMessages(['reason'=>'Justificativa deve ter entre 15 e 255 caracteres.']);
            }
            // RICMS BA art. 107-H: cancelamento normal <= 30 minutos,
            // desde que a mercadoria não tenha circulado.
            if (!$document->authorized_at || $document->authorized_at->isFuture()
                || $document->authorized_at->copy()->addMinutes(30)->isPast()) {
                throw ValidationException::withMessages(['reason'=>'Prazo de 30 minutos para cancelamento comum na Bahia encerrado. Verifique a regularização cabível.']);
            }
            $document->update([
                'cancellation_status'=>'pending',
                'cancellation_reason'=>$reason,
                'cancellation_requested_at'=>now(),
            ]);
            return $document->refresh();
        },3);
    }

    public function process(int $id): void
    {
        $lock=Cache::lock('nextor:nfce-cancel:'.$id,180);
        if (!$lock->get())return;
        try {
            $job=FiscalDocumentJob::query()->findOrFail($id);
            if ($job->document_type!=='nfce' || $job->status!=='authorized'
                || $job->cancellation_status!=='pending')return;

            // Claim once BEFORE the network call. Worker restart/timeout must
            // never silently replay an event whose outcome is uncertain.
            $claimed=FiscalDocumentJob::query()->whereKey($id)
                ->where('cancellation_status','pending')->update(['cancellation_status'=>'processing']);
            if (!$claimed)return;
            $job->refresh();
            $path='fiscal/nfce/'.$id.'/cancel-response.ini';
            $iniPath=null;
            $service=null;
            try {
                $company=CompanySetting::current();
                $cnpj=preg_replace('/\D/','',(string)$company->document);
                if (substr((string)$job->access_key,6,14)!==$cnpj) {
                    throw new RuntimeException('CNPJ do emitente difere da chave fiscal autorizada.');
                }
                $runtime=storage_path('app/acbr-runtime');
                if (!is_dir($runtime) && !mkdir($runtime,0700,true) && !is_dir($runtime)) {
                    throw new RuntimeException('Diretório privado ACBr indisponível.');
                }
                $iniPath=$runtime.'/cancel-'.$id.'-'.bin2hex(random_bytes(6)).'.ini';
                $service=new ACBrNFeService(null,$iniPath);
                app(NFCeTransmissionService::class)->configure($service,$company,$job,
                    (string)$company->certificate_password,(string)\App\Models\AppSetting::value('nfce','csc_token',''));
                $response=$service->cancel((string)$job->access_key,(string)$job->cancellation_reason,$cnpj,$id);
                if (!Storage::disk('local')->put($path,$response)) {
                    throw new RuntimeException('Retorno de cancelamento não pôde ser preservado; situação indeterminada.');
                }
                $result=app(NFCeFiscalEventResponse::class)->parse($response,'cancelamento');
                if (!app(NFCeFiscalEventResponse::class)->cancellationAccepted($result,(string)$job->access_key,(string)$job->environment)) {
                    $job->update([
                        'cancellation_status'=>'rejected',
                        'error_message'=>'Cancelamento SEFAZ: '.$result['cstat'].' - '.$result['reason'],
                    ]);
                    return;
                }
                $job->update([
                    'status'=>'cancelled',
                    'cancellation_status'=>'authorized',
                    'cancelled_at'=>now(),
                    'error_message'=>null,
                ]);
                // XML de evento e retorno ficam arquivados em storage privado.
                // O XML original autorizado nunca é alterado/apagado.
            } catch(Throwable $e) {
                $job->update([
                    'cancellation_status'=>'uncertain',
                    'error_message'=>'Cancelamento com resultado indeterminado: consulte a SEFAZ antes de tentar novamente.',
                ]);
                Log::error('NFC-e: cancelamento requer reconciliação',[
                    'document_id'=>$id,'reason'=>str_replace((string)($company->certificate_password??''),'[protegido]',$e->getMessage()),
                ]);
            } finally {
                try {$service?->close();} finally {if($iniPath && is_file($iniPath))@unlink($iniPath);}
            }
        }finally{$lock->release();}
    }
}
