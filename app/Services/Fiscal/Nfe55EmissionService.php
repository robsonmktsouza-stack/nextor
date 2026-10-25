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
use DOMXPath;

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
        // Route-bound instances can be stale or have stale loaded items.
        $draft=NfeDraft::query()->with(['items','operationNature'])->findOrFail($draft->id);
        $company=CompanySetting::current();
        if(!$draft->validated_at)throw ValidationException::withMessages(['nfe'=>'Valide a NF-e antes de emitir.']);
        $checkedFingerprint=app(Nfe55DocumentSnapshot::class)->fingerprint($draft,$company);
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
            ]);
            throw ValidationException::withMessages([
                'nfe' => 'Não foi possível conferir esta nota. Revise os dados da empresa, do cliente e a tributação dos produtos.',
            ]);
        }
        $updated=app(Nfe55NumberReservationService::class)->reserve($draft->id,$checkedFingerprint);
        try {
            ProcessNfe55Job::dispatch($updated->id)->onConnection('database')->onQueue('fiscal');
        } catch(Throwable $exception){
            // Reserved numbers must not be recycled after a dispatch failure.
            NfeDraft::query()->whereKey($updated->id)->where('status','queued')
                ->whereNull('transmission_attempted_at')
                ->update(['status'=>'prepared','error_message'=>'Não foi possível iniciar o envio da nota.']);
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
                $stalled=in_array($note->status,['processing','queued'],true)
                    && $note->updated_at!==null
                    && $note->updated_at->lt(now()->subMinutes(15));
                $expectedSignedPath='fiscal/nfe55/'.$note->id.'/signed.xml';
                $signedKey=(string)($note->access_key??'');
                $keyIsConsistent=$signedKey==='' || (
                    preg_match('/^\\d{44}$/',$signedKey)===1
                    &&substr($signedKey,6,14)===preg_replace('/\D/','',(string)CompanySetting::current()->document)
                    &&substr($signedKey,20,2)==='55'
                    &&substr($signedKey,22,3)===sprintf('%03d',(int)$note->series)
                    &&substr($signedKey,25,9)===sprintf('%09d',(int)$note->document_number)
                    &&substr($signedKey,34,1)==='1'
                    &&substr($signedKey,35,8)===sprintf('%08d',(int)$note->numeric_code)
                );
                if(!in_array($note->status,['prepared','processing','queued'],true)
                    ||(in_array($note->status,['processing','queued'],true)&&!$stalled)
                    ||$note->transmission_attempted_at!==null
                    ||$note->protocol!==null||$note->authorized_at!==null||$note->cancelled_at!==null
                    ||$note->cancellation_status!==null
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
                NfeDraft::query()->whereKey($updated->id)->where('status','queued')
                    ->whereNull('transmission_attempted_at')
                    ->update(['status'=>'prepared','error_message'=>'Não foi possível iniciar uma nova tentativa de envio.']);
                throw ValidationException::withMessages(['nfe'=>'Não foi possível iniciar uma nova tentativa. Tente novamente.']);
            }
            return $updated;
        } finally {
            $lock->release();
        }
    }

    /** False means lock contention: the queue must release, never consume, the job. */
    public function process(int $id): bool
    {
        $lock=Cache::lock('nfe-55:'.$id,300);
        if(!$lock->get())return false;
        $draft=null;$service=null;$runtimeIni=null;$password='';
        $attempted=false;
        try {
            $draft=DB::transaction(function()use($id) {
                $note=NfeDraft::query()->lockForUpdate()->findOrFail($id);
                if($note->status!=='queued')return null;
                if($note->transmission_attempted_at!==null || $note->protocol!==null || $note->authorized_at!==null){
                    $note->update([
                        'status'=>'pending',
                        'error_message'=>'Esta NF-e já possui tentativa fiscal registrada. Consulte a SEFAZ antes de prosseguir.',
                    ]);
                    return null;
                }
                // status is committed before any FFI call; a worker crash never schedules a duplicate.
                $note->update(['status'=>'processing']);
                return $note->refresh();
            });
            if(!$draft)return true;
            $company=CompanySetting::current();
            $draft->load(['items','operationNature']);
            $fingerprint=(string)data_get($draft->custom_fields,'_reserved_fiscal_fingerprint','');
            if(preg_match('/^[a-f0-9]{64}$/',$fingerprint)!==1
                || !hash_equals($fingerprint,app(Nfe55DocumentSnapshot::class)->materialFingerprint($draft,$company))){
                throw new RuntimeException('Os dados fiscais reservados foram alterados ou não possuem conferência rastreável.');
            }
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
            app(Nfe55XmlTaxConsistency::class)->assertMatches($ini,$signedXml);
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
                $this->persistAuthorization($draft,$signedXml,$result,$company);
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
        return true;
    }

    /** Read-only SEFAZ consultation. Never calls NFE_Enviar again. */
    public function consult(NfeDraft $draft): NfeDraft
    {
        if(!in_array($draft->status,['processing','pending','authorized','rejected','cancelled'],true)
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
            $raw=$service->consultByKeyWithEvents((string)$draft->access_key);
            $base='fiscal/nfe55/'.$draft->id;
            if(!Storage::disk('local')->put($base.'/consult-response.ini',$raw)){
                throw new RuntimeException('Não foi possível preservar a resposta da consulta.');
            }
            if(app(Nfe55CancellationReconciliationService::class)->reconcile($draft,$base.'/consult-response.ini')){
                return $draft->refresh();
            }
            $result=$this->responses->parseConsult($raw);
            if($draft->status!=='cancelled'
                && $this->authorizationMatches($result,(string)$draft->access_key,(string)$draft->environment)){
                $signedPath=$base.'/signed.xml';
                if(!Storage::disk('local')->exists($signedPath)){
                    throw new RuntimeException('XML assinado original não foi encontrado.');
                }
                $this->persistAuthorization($draft,Storage::disk('local')->get($signedPath),$result,$company);
            }
            return $draft->refresh();
        }catch(Throwable $error){
            \Illuminate\Support\Facades\Log::warning('NF-e: falha na consulta fiscal', [
                'draft_id' => $draft->id,
                'exception_type' => get_class($error),
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

    private function persistAuthorization(NfeDraft $draft,string $signed,array $result,?CompanySetting $company=null): void
    {
        $company??=CompanySetting::current();
        $receivedAt=null;
        try{
            $receivedAt=$this->normalizeReceivedAt((string)($result['received_at']??''),(string)$company->timezone);
        }catch(Throwable){
            // Preserve the authorization evidence; never invent its timestamp.
        }
        $authorizedAt=$draft->authorized_at;
        if($authorizedAt===null && $receivedAt!==null){
            $authorizedAt=\Illuminate\Support\Carbon::parse($receivedAt)
                ->setTimezone((string)config('app.timezone','UTC'));
        }
        $attrs=[
            'status'=>'authorized','protocol'=>$result['protocol'],
            'authorized_at'=>$authorizedAt,'processed_at'=>now(),'error_message'=>null,
        ];
        try{
            if($receivedAt===null)throw new RuntimeException('Protocolo sem data de autorização verificável.');
            if(!$this->protocolDigestMatches($signed,(string)$draft->access_key,(string)($result['digest']??''))){
                throw new RuntimeException('Digest do protocolo não corresponde ao XML assinado original.');
            }
            $result['received_at']=$receivedAt;
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

    /** Preserve explicit offsets; legacy ACBr text uses the configured installation timezone. */
    public function normalizeReceivedAt(string $input,string $timezone): string
    {
        $input=trim($input);
        if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',$input)){
            $value=str_ends_with($input,'Z')?substr($input,0,-1).'+00:00':$input;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$value);
            if($date && abs($date->getOffset())<=50400 && $date->format('Y-m-d\TH:i:sP')===$value)return $value;
        }elseif(preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/',$input) && $timezone!==''){
            $date=\DateTimeImmutable::createFromFormat('!d/m/Y H:i:s',$input,new \DateTimeZone($timezone));
            if($date && $date->format('d/m/Y H:i:s')===$input)return $date->format('Y-m-d\TH:i:sP');
        }
        throw new RuntimeException('Data de autorização SEFAZ inválida ou fuso horário não configurado.');
    }

    /** A matching key alone cannot attach a protocol to different XML content. */
    public function protocolDigestMatches(string $signed,string $key,string $digest): bool
    {
        if(strlen($signed)>6291456 || preg_match('/<!DOCTYPE|<!ENTITY/i',$signed)
            || preg_match('/^\d{44}$/',$key)!==1 || $digest==='' || base64_decode($digest,true)===false)return false;
        $dom=new DOMDocument();
        if(!@$dom->loadXML($signed,LIBXML_NONET))return false;
        $xpath=new DOMXPath($dom);
        $xpath->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
        $xpath->registerNamespace('s','http://www.w3.org/2000/09/xmldsig#');
        $nodes=$xpath->query('/n:NFe/s:Signature/s:SignedInfo/s:Reference[@URI="#NFe'.$key.'"]/s:DigestValue');
        if($nodes->length!==1)return false;
        return hash_equals($digest,trim((string)$nodes->item(0)->textContent));
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
        $draft->loadMissing('items');
        $details=$info instanceof DOMElement?$info->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe','det'):null;
        $expectedPurpose=match($draft->purpose){'normal'=>'1','complementary'=>'2','adjustment'=>'3','return'=>'4',default=>''};
        if(!$sig||$root->namespaceURI!=='http://www.portalfiscal.inf.br/nfe'
            ||preg_match('/^\d{44}$/',$key)!==1
            ||!preg_match('/^\d{14}$/',$cnpj)
            ||$xmlCnpj!==$cnpj
            ||$get('mod')!=='55'
            ||$get('tpAmb')!==($draft->environment==='production'?'1':'2')
            ||$get('tpEmis')!=='1'
            ||$get('cUF')!==substr((string)$company->city_ibge_code,0,2)
            ||substr($key,0,2)!==$get('cUF')
            ||$draft->issue_date===null||substr($key,2,4)!==$draft->issue_date->format('ym')
            ||substr($get('dhEmi'),0,19)!==$draft->issue_date->format('Y-m-d').'T'.substr((string)$draft->issue_time,0,5).':00'
            ||$info?->getAttribute('Id')!=='NFe'.$key
            ||$get('tpNF')!==($draft->operation_type==='outbound'?'1':'0')
            ||$get('idDest')!==match($draft->destination){'internal'=>'1','interstate'=>'2','foreign'=>'3',default=>''}
            ||$expectedPurpose===''||$get('finNFe')!==$expectedPurpose
            ||$details?->length!==$draft->items->count()
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
        $expectedTotal=data_get($draft->totals,'total');
        if($expectedTotal===null || NfeTaxMath::scaled($draft->issue_date?->toDateString()>='2027-01-01'?$get('vNFTot'):$get('vNF'),2,'Total XML')!==NfeTaxMath::scaled($expectedTotal,2,'Total reservado')){
            throw new RuntimeException('Total do XML assinado não corresponde à NF-e reservada.');
        }
        return $key;
    }

    public function assertReady(NfeDraft $draft,CompanySetting $company): void
    {
        $issue=$this->settings->transmissionIssue('nfe',(string)$draft->environment);
        if($issue)throw ValidationException::withMessages(['nfe'=>$issue]);
        $numbering=$this->settings->nfeNumbering((string)$draft->environment);
        if((int)$draft->series!==$numbering['series']){
            throw ValidationException::withMessages(['nfe'=>'A série do rascunho difere da série configurada neste ambiente. Revalide a nota.']);
        }
        $this->assertRuntimeReady($draft,$company);
    }

    /** Fiscal regularization uses the document environment even when new issuance is disabled. */
    public function assertRuntimeReady(NfeDraft $draft,CompanySetting $company): void
    {
        if(!in_array($draft->environment,['homologation','production'],true))throw ValidationException::withMessages(['nfe'=>'Ambiente fiscal inválido.']);
        if(!InstanceIdentity::matches($company->document))throw ValidationException::withMessages(['nfe'=>'Empresa fiscal não corresponde à instalação.']);
        $disk=Storage::disk('local');
        if(!$company->certificate_path || !$company->certificate_password
            ||(!$company->certificate_expires_at?->isFuture() && $company->certificate_expires_at!==null)
            || !$disk->exists($company->certificate_path)){
            throw ValidationException::withMessages(['nfe'=>'Certificado A1 indisponível ou vencido.']);
        }
        if(!extension_loaded('ffi') || !is_file((string)config('services.acbr_nfe.library_path',''))){
            throw ValidationException::withMessages(['nfe'=>'O serviço de emissão de NF-e está indisponível. Solicite suporte.']);
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
