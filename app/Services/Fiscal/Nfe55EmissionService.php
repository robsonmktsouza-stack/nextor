<?php

namespace App\Services\Fiscal;

use App\Jobs\ProcessNfe55Job;
use App\Models\AppSetting;
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
use DOMDocument;
use DOMElement;

final class Nfe55EmissionService
{
    public function __construct(
        private readonly Nfe55IniBuilder $builder,
        private readonly NFCeSefazResponseParser $responses,
        private readonly NFCeProtocolXmlService $protocols,
        private readonly FiscalDocumentSettings $settings,
    ) {}

    /** Reserves a document only after checking its immutable editor snapshot. */
    public function queue(NfeDraft $draft): NfeDraft
    {
        $company=CompanySetting::current();
        if(!$draft->validated_at)throw ValidationException::withMessages(['nfe'=>'Valide a NF-e antes de emitir.']);
        $this->assertReady($draft,$company);
        // A verificação nativa acontece automaticamente antes de reservar a
        // numeração. Nenhum botão de diagnóstico é necessário na interface.
        try {
            app(Nfe55PreflightService::class)->check($draft);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('NF-e: falha na pré-validação interna antes do envio', [
                'draft_id' => $draft->id,
                'exception_type' => get_class($exception),
                'reason' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw ValidationException::withMessages([
                'nfe' => 'Não foi possível conferir esta nota. Revise os dados da empresa, do cliente e a tributação dos produtos.',
            ]);
        }
        $updated=DB::transaction(function()use($draft){
            $locked=NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if($locked->status!=='draft'||$locked->document_number!==null){
                throw ValidationException::withMessages(['nfe'=>'Esta nota não pode ser transmitida novamente.']);
            }
            $env=$locked->environment;
            $numberKey=$env==='production'?'production_next_number':'next_number';
            $number=AppSetting::reserveInteger('nfe',$numberKey,1);
            $locked->update([
                'document_number'=>$number,'numeric_code'=>random_int(1,99999999),
                'status'=>'queued','validated_at'=>now(),'error_message'=>null,
            ]);
            return $locked->refresh();
        },3);
        try {
            ProcessNfe55Job::dispatch($updated->id)->onConnection('database')->onQueue('fiscal');
        } catch(Throwable $exception){
            // Reserved numbers must not be recycled after a dispatch failure.
            $updated->update(['status'=>'prepared','error_message'=>'Não foi possível iniciar o envio da nota.']);
            throw ValidationException::withMessages(['nfe'=>'Não foi possível iniciar o envio. Tente novamente ou solicite suporte.']);
        }
        return $updated;
    }

    /**
     * Reprepare a document only if the SEFAZ has definitely never been contacted.
     * A crashed worker can leave status=processing; after 15 minutes, recover only
     * when there is no transmission attempt. A locally signed XML can exist
     * because signing precedes contacting SEFAZ; reusing the same reserved
     * number/cNF is then safe, while foreign or authorized XML paths are not.
     * The same reserved number and cNF must be reused.
     */
    public function retryPreparation(NfeDraft $draft): NfeDraft
    {
        $lock=Cache::lock('nfe-55:'.$draft->id,300);
        if(!$lock->get()) {
            throw ValidationException::withMessages(['nfe'=>'Já existe uma operação fiscal em andamento.']);
        }
        try {
            $updated=DB::transaction(function()use($draft){
                $note=NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
                $stalled=$note->status==='processing'
                    && $note->updated_at!==null
                    && $note->updated_at->lt(now()->subMinutes(15));
                $expectedSignedPath='fiscal/nfe55/'.$note->id.'/signed.xml';
                $signedKey=(string)($note->access_key??'');
                $keyIsConsistent=$signedKey==='' || (
                    preg_match('/^\\d{44}$/',$signedKey)===1
                    &&substr($signedKey,20,2)==='55'
                    &&substr($signedKey,22,3)===sprintf('%03d',(int)$note->series)
                    &&substr($signedKey,25,9)===sprintf('%09d',(int)$note->document_number)
                    &&substr($signedKey,35,8)===sprintf('%08d',(int)$note->numeric_code)
                );
                if(!in_array($note->status,['prepared','processing'],true)
                    ||($note->status==='processing'&&!$stalled)
                    ||$note->transmission_attempted_at!==null
                    ||!$note->document_number||!$note->numeric_code
                    ||($note->xml_path!==null && $note->xml_path!==$expectedSignedPath)
                    ||!$keyIsConsistent){
                    throw ValidationException::withMessages(['nfe'=>'Não é possível repetir o envio desta nota. Consulte a situação na SEFAZ antes de prosseguir.']);
                }
                $note->update(['status'=>'queued','error_message'=>null]);
                return $note->refresh();
            },3);
            try {
                ProcessNfe55Job::dispatch($updated->id)->onConnection('database')->onQueue('fiscal');
            } catch(Throwable) {
                $updated->update(['status'=>'prepared','error_message'=>'Não foi possível iniciar uma nova tentativa de envio.']);
                throw ValidationException::withMessages(['nfe'=>'Não foi possível iniciar uma nova tentativa. Tente novamente.']);
            }
            return $updated;
        } finally {
            $lock->release();
        }
    }

    public function process(int $id): void
    {
        $lock=Cache::lock('nfe-55:'.$id,300);
        if(!$lock->get())return;
        $draft=null;$service=null;$runtimeIni=null;$password='';
        $attempted=false;
        try {
            $draft=DB::transaction(function()use($id) {
                $note=NfeDraft::query()->lockForUpdate()->findOrFail($id);
                if($note->status!=='queued')return null;
                // status is committed before any FFI call; a worker crash never schedules a duplicate.
                $note->update(['status'=>'processing']);
                return $note->refresh();
            });
            if(!$draft)return;
            $company=CompanySetting::current();
            $this->assertReady($draft,$company);
            $this->builder->build($draft,$company,(int)$draft->document_number,(int)$draft->numeric_code);
            $dir=storage_path('app/acbr-runtime');
            if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){
                throw new RuntimeException('Diretório temporário indisponível.');
            }
            $runtimeIni=$dir.'/nfe55-'.$id.'-'.bin2hex(random_bytes(8)).'.ini';
            $service=new ACBrNFeService(null,$runtimeIni);
            $password=(string)$company->certificate_password;
            $this->configure($service,$company,$draft,$password);
            $ini=$this->builder->build($draft,$company,(int)$draft->document_number,(int)$draft->numeric_code);
            $service->loadIni($ini);
            $service->sign();
            $service->validateXml();
            $service->validateBusinessRules();
            $service->verifySignature();
            $signedXml=$service->getXml();
            $signedKey=$this->verifySignedXml($signedXml,$draft,$company);
            $base='fiscal/nfe55/'.$id;
            $signedPath=$base.'/signed.xml';
            if(!Storage::disk('local')->put($signedPath,$signedXml)){
                throw new RuntimeException('Não foi possível armazenar o XML assinado.');
            }
            // Persist the access key before the external request, so uncertain
            // timeouts can be reconciled without generating a new invoice.
            $draft->update(['xml_path'=>$signedPath,'access_key'=>$signedKey]);
            $attempted=true;
            $draft->update(['transmission_attempted_at'=>now(),'status'=>'pending']);
            $raw=$service->send((int)$draft->document_number);
            Storage::disk('local')->put($base.'/sefaz-response.ini',$raw);
            $draft->update(['response_path'=>$base.'/sefaz-response.ini']);
            $result=$this->responses->parse($raw);
            if($this->authorizationMatches($result,$signedKey,(string)$draft->environment)){
                $this->persistAuthorization($draft,$signedXml,$result);
            }else{
                $code=(string)($result['cstat']??'');
                $definitive=$this->responses->definitiveCorrectionRejection($result)
                    && ($result['key']===null || hash_equals($signedKey,(string)$result['key']));
                $draft->update([
                    'status'=>$definitive?'rejected':'pending',
                    'error_message'=>mb_substr('Retorno da SEFAZ: '.$code.' - '.($result['reason']??''),0,900),
                    'processed_at'=>now(),
                ]);
            }
        }catch(Throwable $error){
            \Illuminate\Support\Facades\Log::error('NF-e: falha no processamento fiscal interno', [
                'draft_id' => $id,
                'attempted_send' => $attempted,
                'exception_type' => get_class($error),
                'reason' => mb_substr($error->getMessage(), 0, 1500),
            ]);
            if($draft){
                $draft->update([
                    'status'=>$attempted?'pending':'prepared',
                    'error_message'=>$attempted
                        ? 'Transmissão sem confirmação. Consulte a SEFAZ pela chave.'
                        : 'Não foi possível preparar a nota. Confira as informações e tente novamente.','processed_at'=>now(),
                ]);
            }
        }finally{
            try{$service?->close();}finally{
                $password='';
                if($runtimeIni && is_file($runtimeIni))@unlink($runtimeIni);
                $lock->release();
            }
        }
    }

    /** Read-only SEFAZ consultation. Never calls NFE_Enviar again. */
    public function consult(NfeDraft $draft): NfeDraft
    {
        if(!in_array($draft->status,['processing','pending','authorized','rejected'],true)
            || !preg_match('/^\d{44}$/',(string)$draft->access_key)
            || !$draft->xml_path ||!Storage::disk('local')->exists($draft->xml_path)){
            throw ValidationException::withMessages(['nfe'=>'Esta NF-e ainda não possui XML assinado para consulta.']);
        }
        $lock=Cache::lock('nfe-55:'.$draft->id,300);
        if(!$lock->get())throw ValidationException::withMessages(['nfe'=>'Já existe uma operação fiscal em andamento.']);
        $service=null;$ini=null;$password='';
        try{
            $draft->refresh();
            $company=CompanySetting::current();
            $dir=storage_path('app/acbr-runtime');
            if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Diretório de consulta indisponível.');
            $ini=$dir.'/nfe55-consult-'.$draft->id.'-'.bin2hex(random_bytes(8)).'.ini';
            $service=new ACBrNFeService(null,$ini);
            $password=(string)$company->certificate_password;
            $this->configure($service,$company,$draft,$password);
            $raw=$service->consultByKey((string)$draft->access_key);
            $base='fiscal/nfe55/'.$draft->id;
            Storage::disk('local')->put($base.'/consult-response.ini',$raw);
            $result=$this->responses->parseConsult($raw);
            if($this->authorizationMatches($result,(string)$draft->access_key,(string)$draft->environment)){
                $signedPath=$base.'/signed.xml';
                if(!Storage::disk('local')->exists($signedPath)){
                    throw new RuntimeException('XML assinado original não foi encontrado.');
                }
                $this->persistAuthorization($draft,Storage::disk('local')->get($signedPath),$result);
            }
            return $draft->refresh();
        }catch(Throwable $error){
            \Illuminate\Support\Facades\Log::warning('NF-e: falha na consulta fiscal', [
                'draft_id' => $draft->id,
                'exception_type' => get_class($error),
                'reason' => mb_substr($error->getMessage(), 0, 1500),
            ]);
            throw ValidationException::withMessages(['nfe'=>'Não foi possível consultar a nota neste momento. Confira sua conexão e tente novamente.']);
        }finally{
            try{$service?->close();}finally{
                $password='';
                if($ini&&is_file($ini))@unlink($ini);
                $lock->release();
            }
        }
    }

    /** Only the matching access key AND SEFAZ environment can confirm authorization. */
    public function authorizationMatches(array $response,string $key,string $environment): bool
    {
        return $this->responses->authorized($response)
            && preg_match('/^\\d{44}$/',$key)===1
            && hash_equals($key,(string)($response['key']??''))
            && ($environment==='homologation'
                ? (string)($response['environment']??'')==='2'
                : ($environment==='production' && (string)($response['environment']??'')==='1'));
    }

    private function persistAuthorization(NfeDraft $draft,string $signed,array $result): void
    {
        $attrs=[
            'status'=>'authorized','protocol'=>$result['protocol'],
            'authorized_at'=>now(),'processed_at'=>now(),'error_message'=>null,
        ];
        try{
            $content=$this->protocols->buildAuthorized($signed,$result);
            $path='fiscal/nfe55/'.$draft->id.'/authorized.xml';
            if(!Storage::disk('local')->put($path,$content)){
                throw new RuntimeException('Arquivo autorizado não gravado.');
            }
            $attrs['xml_path']=$path;
        }catch(Throwable){
            // Authorization is irreversible even when local nfeProc materialization fails.
            $attrs['error_message']='Nota autorizada, mas o documento para download ainda está indisponível.';
        }
        $draft->update($attrs);
    }

    public function verifySignedXml(string $xml,NfeDraft $draft,CompanySetting $company,?int $number=null,?int $numericCode=null): string
    {
        if(strlen($xml)>6291456 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new RuntimeException('XML assinado inválido.');
        $dom=new DOMDocument();
        if(!@$dom->loadXML($xml,LIBXML_NONET))throw new RuntimeException('XML assinado não pôde ser lido.');
        $root=$dom->documentElement;
        if($root?->localName!=='NFe')throw new RuntimeException('Documento assinado não é uma NF-e.');
        $info=$root->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe','infNFe')->item(0);
        $sig=$root->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#','Signature')->item(0);
        $key=$info instanceof DOMElement?substr($info->getAttribute('Id'),3):'';
        $get=static fn($tag)=>trim((string)$info?->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe',$tag)->item(0)?->textContent);
        $cnpj=preg_replace('/\D/','',(string)$company->document);
        $n=$number??(int)$draft->document_number;
        $code=$numericCode??(int)$draft->numeric_code;
        $emit=$info instanceof DOMElement
            ?$info->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe','emit')->item(0)
            :null;
        $xmlCnpj=$emit instanceof DOMElement
            ?trim((string)$emit->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe','CNPJ')->item(0)?->textContent)
            :'';
        if(!$sig||$root->namespaceURI!=='http://www.portalfiscal.inf.br/nfe'
            ||preg_match('/^\d{44}$/',$key)!==1
            ||!preg_match('/^\d{14}$/',$cnpj)
            ||$xmlCnpj!==$cnpj
            ||$get('mod')!=='55'
            ||$get('tpAmb')!==($draft->environment==='production'?'1':'2')
            ||$get('tpEmis')!=='1'
            ||(int)$get('nNF')!==$n
            ||(int)$get('serie')!==(int)$draft->series
            ||(int)$get('cNF')!==$code
            ||substr($key,6,14)!==$cnpj
            ||substr($key,20,2)!=='55'
            ||substr($key,22,3)!==sprintf('%03d',(int)$draft->series)
            ||substr($key,25,9)!==sprintf('%09d',$n)
            ||substr($key,34,1)!=='1'
            ||substr($key,35,8)!==sprintf('%08d',$code)){
            throw new RuntimeException('XML assinado não corresponde à NF-e reservada.');
        }
        return $key;
    }

    public function assertReady(NfeDraft $draft,CompanySetting $company): void
    {
        $issue=$this->settings->transmissionIssue('nfe',(string)$draft->environment);
        if($issue)throw ValidationException::withMessages(['nfe'=>$issue]);
        if(!InstanceIdentity::matches($company->document))throw ValidationException::withMessages(['nfe'=>'Empresa fiscal não corresponde à instalação.']);
        if($draft->environment==='production'
            &&(!is_numeric(AppSetting::value('nfe','production_next_number'))
                || !is_numeric(AppSetting::value('nfe','production_series')))){
            throw ValidationException::withMessages(['nfe'=>'Configure série e numeração de produção da NF-e.']);
        }
        $disk=Storage::disk('local');
        if(!$company->certificate_path || !$company->certificate_password
            ||(!$company->certificate_expires_at?->isFuture() && $company->certificate_expires_at!==null)
            || !$disk->exists($company->certificate_path)){
            throw ValidationException::withMessages(['nfe'=>'Certificado A1 indisponível ou vencido.']);
        }
        if(!extension_loaded('ffi') || !is_file((string)config('services.acbr_nfe.library_path',''))){
            throw ValidationException::withMessages(['nfe'=>'O serviço de emissão de NF-e está indisponível. Solicite suporte.']);
        }
        if($draft->environment!=='homologation'){
            // Production is not enabled until an end-to-end model 55 authorization
            // and the RTC fiscal matrix have been confirmed for this version.
            throw ValidationException::withMessages(['nfe'=>'Emissão NF-e em produção aguarda homologação fiscal.']);
        }
    }

    /** Shared model 55 ACBr configuration for emission and fiscal events. */
    public function configure(ACBrNFeService $service,CompanySetting $company,NfeDraft $draft,string $password): void
    {
        $schemas=(string)config('services.acbr_nfe.schemas_path','');
        if(!is_dir($schemas)){
            foreach(['C:/laragon/acbr/dep/Schemas/NFe','C:/laragon/acbr/Schemas/NFe'] as $path){
                if(is_dir($path)){$schemas=$path;break;}
            }
        }
        if(!is_dir($schemas))throw new RuntimeException('Schemas fiscais indisponíveis.');
        $service->initialize();
        foreach([
            ['DFe','UF',strtoupper((string)$company->state)],
            ['DFe','ArquivoPFX',Storage::disk('local')->path($company->certificate_path)],
            ['DFe','Senha',$password],
            ['DFe','SSLCryptLib','1'],['DFe','SSLHttpLib','3'],['DFe','SSLXmlSignLib','4'],
            ['NFe','PathSchemas',$schemas],
            ['NFe','FormaEmissao','0'],['NFe','ValidarDigest','1'],
            ['NFe','ModeloDF','0'],['NFe','Ambiente',$draft->environment==='production'?'0':'1'],
            ['NFe','VersaoDF','3'],
        ] as [$section,$key,$value])$service->setConfig($section,$key,$value);
    }

}
