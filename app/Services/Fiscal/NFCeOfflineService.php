<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Contingência (MOC Anexo IV, tpEmis=9):
 * 1. Gera e preserva o XML ASSINADO + sua chave, sem contatar a SEFAZ.
 * 2. Gera o DANFE claramente marcado como pendente de autorização.
 * 3. Transmite posteriormente o MESMO XML e chave, sem nova assinatura.
 */
final class NFCeOfflineService
{
    public function prepare(int $id): void
    {
        $lock=Cache::lock('nextor:nfce:'.$id,240);
        if(!$lock->get())return;
        try{
            $job=DB::transaction(function()use($id){
                $job=FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
                if($job->document_type!=='nfce'||$job->emission_mode!=='offline'
                    ||$job->status!=='prepared'||$job->access_key||$job->xml_path)return null;
                $errors=app(NFCePreflightService::class)->validate($job);
                if($errors){$job->update(['error_message'=>implode(' | ',$errors)]);return null;}
                $job->update(['status'=>'processing','error_message'=>null]);
                return $job;
            },3);
            if(!$job)return;

            $service=null;$iniPath=null;$signedPersisted=false;
            try{
                $company=CompanySetting::current();
                $dir=storage_path('app/acbr-runtime');
                if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Diretório ACBr indisponível.');
                $iniPath=$dir.'/offline-'.$id.'-'.bin2hex(random_bytes(6)).'.ini';
                $service=new ACBrNFeService(null,$iniPath);
                app(NFCeTransmissionService::class)->configure(
                    $service,$company,$job,(string)$company->certificate_password,
                    (string)AppSetting::value('nfce','csc_token','')
                );
                $service->loadIni(app(NFCeIniBuilder::class)->build($job,$company));
                $service->sign();
                $service->validateXml();
                $xml=$service->getXml();
                $key=app(NFCeProtocolXmlService::class)->verifySigned($xml,$job);
                // Persist before anything that could fail (especially PDF).
                $signedPath='fiscal/nfce/'.$id.'/signed.xml';
                if(!Storage::disk('local')->put($signedPath,$xml))throw new RuntimeException('XML offline assinado não foi preservado.');
                $signedPersisted=true;
                $job->update([
                    'status'=>'offline_print_pending',
                    'access_key'=>$key,'xml_path'=>$signedPath,'processed_at'=>now(),
                ]);

                // No offline receipt may be claimed as printable unless the
                // actual PDF can be generated with the official QR Code.
                $pdf=app(NFCePdfService::class);
                $pdf->generate($job->fresh(),$xml);
                $job->update(['status'=>'offline_signed','error_message'=>null]);
            }catch(Throwable $e){
                $job->update([
                    'status'=>$signedPersisted?'offline_print_pending':'prepared',
                    'error_message'=>$signedPersisted
                        ?'XML em contingência preservado. DANFE ainda não ficou pronto; não recrie esta nota.'
                        :'Falha ao preparar contingência: '.$e->getMessage(),
                ]);
                Log::error('Falha na preparação da NFC-e offline',[
                    'document_id'=>$id,'message'=>$e->getMessage(),
                ]);
            }finally{
                try{$service?->close();}finally{if($iniPath && is_file($iniPath))@unlink($iniPath);}
            }
        }finally{$lock->release();}
    }

    public function transmit(int $id): void
    {
        $lock=Cache::lock('nextor:nfce:'.$id,300);
        if(!$lock->get())return;
        try{
            $job=DB::transaction(function()use($id){
                $job=FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
                if($job->document_type!=='nfce'||$job->emission_mode!=='offline'
                    ||$job->status!=='offline_signed')return null;
                $job->update(['status'=>'offline_sending']);
                return $job;
            },3);
            if(!$job)return;
            $attempted=false;$service=null;$iniPath=null;
            try{
                $path='fiscal/nfce/'.$id.'/signed.xml';
                if(!Storage::disk('local')->exists($path))throw new RuntimeException('XML assinado offline indisponível.');
                $xml=Storage::disk('local')->get($path);
                $key=app(NFCeProtocolXmlService::class)->verifySigned($xml,$job);
                if(!hash_equals((string)$job->access_key,$key))throw new RuntimeException('Chave offline não corresponde ao XML original.');

                $company=CompanySetting::current();
                $dir=storage_path('app/acbr-runtime');
                if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Diretório ACBr indisponível.');
                $iniPath=$dir.'/send-offline-'.$id.'-'.bin2hex(random_bytes(6)).'.ini';
                $service=new ACBrNFeService(null,$iniPath);
                app(NFCeTransmissionService::class)->configure(
                    $service,$company,$job,(string)$company->certificate_password,
                    (string)AppSetting::value('nfce','csc_token','')
                );
                $service->loadXml($xml);
                $service->validateXml();
                // This is the same signed XML, same cNF, same tpEmis=9.
                $attempted=true;
                $response=$service->send((int)$job->document_number);
                $responsePath='fiscal/nfce/'.$id.'/offline-sefaz-response.ini';
                if(!Storage::disk('local')->put($responsePath,$response))throw new RuntimeException('Não foi possível arquivar resposta SEFAZ.');
                $job->update(['response_path'=>$responsePath]);
                $parsed=app(NFCeSefazResponseParser::class)->parse($response);
                if(!app(NFCeSefazResponseParser::class)->authorized($parsed)){
                    $job->update([
                        'status'=>'pending',
                        'error_message'=>'Retorno SEFAZ '.($parsed['cstat']??'indefinido').': '.($parsed['reason']??'').'. Consulte pela chave antes de reenviar.',
                    ]);
                    return;
                }
                if(!hash_equals($key,(string)$parsed['key']))throw new RuntimeException('Chave da autorização difere do XML offline original.');
                $authorized=app(NFCeProtocolXmlService::class)->buildAuthorized($xml,$parsed);
                $authPath='fiscal/nfce/'.$id.'/authorized.xml';
                if(!Storage::disk('local')->put($authPath,$authorized))throw new RuntimeException('Protocolo autorizado não foi preservado.');
                $job->update([
                    'status'=>'authorized','xml_path'=>$authPath,
                    'protocol'=>$parsed['protocol'],
                    'authorized_at'=>now(),'processed_at'=>now(),
                    'error_message'=>null,
                ]);
            }catch(Throwable $e){
                $job->update([
                    'status'=>$attempted?'pending':'offline_signed',
                    'error_message'=>$attempted
                        ?'Retorno da transmissão offline indeterminado. Consulte pela chave; não reenvie automaticamente.'
                        :'Falha antes da transmissão; o XML assinado está preservado.',
                ]);
                Log::error('NFC-e offline necessita conciliação',[
                    'id'=>$id,'transmission_attempted'=>$attempted,'reason'=>$e->getMessage(),
                ]);
            }finally{
                try{$service?->close();}finally{if($iniPath&&is_file($iniPath))@unlink($iniPath);}
            }
        }finally{$lock->release();}
    }
}
