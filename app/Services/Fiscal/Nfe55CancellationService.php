<?php

namespace App\Services\Fiscal;

use App\Jobs\ProcessNfe55CancellationJob;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Model 55 cancellation with an immutable one-shot request.
 *
 * A worker is never allowed to retry a cancellation after attempting
 * NFE_Cancelar. Uncertain results require external SEFAZ reconciliation.
 * The original authorized XML is never replaced or deleted.
 */
final class Nfe55CancellationService
{
    public function __construct(
        private readonly Nfe55EmissionService $emission,
        private readonly NFCeFiscalEventResponse $responseParser,
    ) {}

    public function request(NfeDraft $draft,string $reason): NfeDraft
    {
        $reason=trim($reason);
        $company=CompanySetting::current();
        if(!InstanceIdentity::matches($company->document)){
            throw ValidationException::withMessages(['nfe'=>'CNPJ do emitente não corresponde à instalação.']);
        }
        $cnpj=preg_replace('/\D/','',(string)$company->document);
        $note=DB::transaction(function()use($draft,$reason,$cnpj){
            $note=NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if($note->environment!=='homologation'){
                throw ValidationException::withMessages(['nfe'=>'Cancelamento em produção depende de homologação e liberação fiscal.']);
            }
            if($note->status!=='authorized'
                ||!preg_match('/^\d{44}$/',(string)$note->access_key)
                ||substr((string)$note->access_key,6,14)!==$cnpj
                ||substr((string)$note->access_key,20,2)!=='55'
                ||!preg_match('/^\d{15}$/',(string)$note->protocol)
                ||!$note->authorized_at){
                throw ValidationException::withMessages(['nfe'=>'Somente NF-e modelo 55 autorizada pode ser cancelada.']);
            }
            if($note->cancellation_status || $note->cancelled_at){
                throw ValidationException::withMessages(['nfe'=>'Já existe uma solicitação de cancelamento para esta NF-e.']);
            }
            if(mb_strlen($reason)<15||mb_strlen($reason)>255){
                throw ValidationException::withMessages(['reason'=>'A justificativa precisa ter entre 15 e 255 caracteres.']);
            }
            $note->update([
                'cancellation_status'=>'pending',
                'cancellation_reason'=>$reason,
                'cancellation_requested_at'=>now(),
            ]);
            return $note->refresh();
        },3);

        try{
            ProcessNfe55CancellationJob::dispatch($note->id)->onConnection('database')->onQueue('fiscal');
        }catch(Throwable){
            // Dispatch may have succeeded before a connection failure.
            // Do not silently allow a second event.
            $note->update(['cancellation_status'=>'uncertain']);
            throw ValidationException::withMessages([
                'nfe'=>'Não foi possível confirmar o agendamento. Confira a fila fiscal antes de qualquer nova tentativa.',
            ]);
        }
        return $note;
    }

    public function process(int $id): void
    {
        // Reuse the exact emission/consultation lock to avoid concurrent FFI
        // operations on the same model 55 access key.
        $lock=Cache::lock('nfe-55:'.$id,300);
        if(!$lock->get())return;
        $service=null;$runtimeIni=null;$password='';
        try{
            $draft=DB::transaction(function()use($id){
                $note=NfeDraft::query()->lockForUpdate()->findOrFail($id);
                if($note->status!=='authorized'
                    ||$note->cancellation_status!=='pending'
                    ||$note->cancellation_attempted_at!==null)return null;
                // Commit claim before any possible outbound call.
                $note->update(['cancellation_status'=>'processing']);
                return $note->refresh();
            },3);
            if(!$draft)return;

            try{
                if($draft->environment!=='homologation')throw new RuntimeException('Ambiente não liberado.');
                $company=CompanySetting::current();
                if(!InstanceIdentity::matches($company->document))throw new RuntimeException('Instalação incorreta.');
                $cnpj=preg_replace('/\D/','',(string)$company->document);
                if(!preg_match('/^\d{14}$/',$cnpj)
                    ||substr((string)$draft->access_key,6,14)!==$cnpj
                    ||substr((string)$draft->access_key,20,2)!=='55'){
                    throw new RuntimeException('Emitente e chave fiscal não conferem.');
                }
                $reason=trim((string)$draft->cancellation_reason);
                if(mb_strlen($reason)<15||mb_strlen($reason)>255){
                    throw new RuntimeException('Justificativa inválida.');
                }
                $runtime=storage_path('app/acbr-runtime');
                if(!is_dir($runtime)&&!mkdir($runtime,0700,true)&&!is_dir($runtime)){
                    throw new RuntimeException('Diretório privado ACBr indisponível.');
                }
                $runtimeIni=$runtime.'/nfe55-cancel-'.$id.'-'.bin2hex(random_bytes(8)).'.ini';
                $service=new ACBrNFeService(null,$runtimeIni);
                $password=(string)$company->certificate_password;
                $this->emission->configure($service,$company,$draft,$password);
                // Persist the point of no return before the network call.
                $draft->update(['cancellation_attempted_at'=>now()]);
                $raw=$service->cancel((string)$draft->access_key,$reason,$cnpj,$id);
                $path='fiscal/nfe55/'.$id.'/cancel-response.ini';
                if(!Storage::disk('local')->put($path,$raw)){
                    throw new RuntimeException('Resposta recebida, mas não armazenada; situação indeterminada.');
                }
                $data=$this->responseParser->parse($raw,'cancelamento');
                $proof=$data['xml_proof']??null;
                $accepted=$this->responseParser->cancellationAccepted(
                    $data,(string)$draft->access_key,(string)$draft->environment
                );
                // Require a matching signed SEFAZ event response, not just cStat.
                if($accepted && is_array($proof)
                    && hash_equals((string)$draft->access_key,(string)($proof['key']??''))
                    && hash_equals((string)$data['protocol'],(string)($proof['protocol']??''))
                    && ($proof['environment']??'')==='2'){
                    $draft->update([
                        'status'=>'cancelled','cancellation_status'=>'authorized',
                        'cancelled_at'=>now(),'cancellation_protocol'=>$data['protocol'],
                        'cancellation_response_path'=>$path,'error_message'=>null,
                    ]);
                    return;
                }
                // 101/135/155 may represent an accepted operation with missing proof.
                $possible=in_array($data['cstat'],['101','135','155','128'],true);
                $draft->update([
                    'cancellation_status'=>$possible?'uncertain':'rejected',
                    'cancellation_response_path'=>$path,
                    'error_message'=>$possible
                        ? 'Cancelamento pendente de reconciliação pela SEFAZ.'
                        : 'Cancelamento rejeitado: '.mb_substr($data['reason'],0,500),
                ]);
            }catch(Throwable){
                $draft->update([
                    'cancellation_status'=>'uncertain',
                    'error_message'=>'Situação fiscal indeterminada. Consulte a SEFAZ antes de qualquer nova tentativa.',
                ]);
            }
        }finally{
            try{$service?->close();}finally{
                $password='';
                if($runtimeIni&&is_file($runtimeIni))@unlink($runtimeIni);
                $lock->release();
            }
        }
    }
}
