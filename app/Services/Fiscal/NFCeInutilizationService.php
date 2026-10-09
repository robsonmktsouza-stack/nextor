<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\NFCeInutilization;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class NFCeInutilizationService
{
    public function request(array $input,int $userId): NFCeInutilization
    {
        return DB::transaction(function() use($input,$userId) {
            // Coordinate the same number reservation setting used by sales.
            AppSetting::query()->where('group','nfce')->where('key','next_number')
                ->lockForUpdate()->first();
            $company=CompanySetting::current();
            $cnpj=preg_replace('/\D/','',(string)$company->document);
            $environment=app(FiscalDocumentSettings::class)->environment('nfce');
            $series=(int)$input['series'];
            $first=(int)$input['first_number'];
            $last=(int)$input['last_number'];
            $year=(int)$input['year'];
            $reason=trim((string)$input['reason']);
            if (preg_match('/^\d{14}$/',$cnpj)!==1 || strtoupper((string)$company->state)!=='BA'
                || $series!==(int)AppSetting::value('nfce','series',1)
                || $year<2020 || $year>(int)now()->year
                || $first<1 || $last<$first || $last-$first>999
                || $last >= (int)AppSetting::value('nfce','next_number',1)
                || mb_strlen($reason)<15 || mb_strlen($reason)>255) {
                throw ValidationException::withMessages([
                    'first_number'=>'Informe somente lacuna de numeração anterior à próxima NFC-e, com série e ano válidos.',
                ]);
            }
            if (!app(FiscalDocumentSettings::class)->enabled('nfce')) {
                throw ValidationException::withMessages(['first_number'=>'Ative a NFC-e antes de solicitar inutilização.']);
            }
            if (FiscalDocumentJob::query()->where('document_type','nfce')
                ->where('series',$series)->whereBetween('document_number',[$first,$last])->exists()) {
                throw ValidationException::withMessages([
                    'first_number'=>'A faixa inclui número já reservado ou utilizado no NEXTOR.',
                ]);
            }
            if (NFCeInutilization::query()->where('issuer_document',$cnpj)
                ->where('environment',$environment)->where('year',$year)->where('series',$series)
                ->where('first_number','<=',$last)->where('last_number','>=',$first)->exists()) {
                throw ValidationException::withMessages(['first_number'=>'Esta faixa já possui solicitação de inutilização.']);
            }

            return NFCeInutilization::query()->create([
                'issuer_document'=>$cnpj,'environment'=>$environment,
                'year'=>$year,'series'=>$series,
                'first_number'=>$first,'last_number'=>$last,
                'reason'=>$reason,'status'=>'pending','requested_by'=>$userId,
            ]);
        },3);
    }

    public function process(int $id): void
    {
        $lock=Cache::lock('nextor:nfce-inutil:'.$id,180);
        if (!$lock->get())return;
        try {
            $row=NFCeInutilization::query()->findOrFail($id);
            if ($row->status!=='pending')return;
            $claimed=NFCeInutilization::query()->whereKey($id)
                ->where('status','pending')->update(['status'=>'processing']);
            if (!$claimed)return;
            $row->refresh();
            $runtimePath=null;
            $service=null;
            try {
                $company=CompanySetting::current();
                if (preg_replace('/\D/','',(string)$company->document)!==$row->issuer_document) {
                    throw new RuntimeException('Emitente difere do pedido de inutilização.');
                }
                // Last guard against a number being assigned after the request.
                if (FiscalDocumentJob::query()->where('document_type','nfce')
                    ->where('series',$row->series)
                    ->whereBetween('document_number',[$row->first_number,$row->last_number])->exists()) {
                    throw new RuntimeException('A faixa foi utilizada após a solicitação; não transmitir.');
                }
                $directory=storage_path('app/acbr-runtime');
                if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)){
                    throw new RuntimeException('Diretório privado ACBr indisponível.');
                }
                $runtimePath=$directory.'/inut-'.$id.'-'.bin2hex(random_bytes(6)).'.ini';
                $service=new ACBrNFeService(null,$runtimePath);
                $context=new FiscalDocumentJob([
                    'document_type'=>'nfce','environment'=>$row->environment,
                ]);
                app(NFCeTransmissionService::class)->configure($service,$company,$context,
                    (string)$company->certificate_password,(string)AppSetting::value('nfce','csc_token',''));
                $response=$service->inutilize($row->issuer_document,$row->reason,$row->year,
                    $row->series,$row->first_number,$row->last_number);
                $path='fiscal/nfce/inutilization/'.$id.'/sefaz-response.ini';
                if (!Storage::disk('local')->put($path,$response)) {
                    throw new RuntimeException('Retorno SEFAZ não foi preservado.');
                }
                $data=app(NFCeFiscalEventResponse::class)->parse($response,'inutilizacao');
                if (!app(NFCeFiscalEventResponse::class)->inutilizationAccepted($data,$row->environment)) {
                    $row->update([
                        'status'=>'rejected','response_path'=>$path,'processed_at'=>now(),
                        'error_message'=>'SEFAZ '.$data['cstat'].' - '.$data['reason'],
                    ]);
                    return;
                }
                $row->update([
                    'status'=>'authorized','protocol'=>$data['protocol'],
                    'response_path'=>$path,'processed_at'=>now(),'error_message'=>null,
                ]);
            }catch(Throwable $e){
                $row->update([
                    'status'=>'uncertain','processed_at'=>now(),
                    'error_message'=>'Resultado indeterminado. Consulte a SEFAZ antes de repetir a operação.',
                ]);
                Log::error('NFC-e: inutilização exige reconciliação',[
                    'id'=>$id,'error'=>$e->getMessage(),
                ]);
            }finally{
                try{$service?->close();}finally{if($runtimePath && is_file($runtimePath))@unlink($runtimePath);}
            }
        }finally{$lock->release();}
    }
}
