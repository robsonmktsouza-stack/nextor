<?php
namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Builds DANFE only from the original authorized nfeProc; never from draft data. */
class Nfe55DanfeService
{
    public function generate(NfeDraft $draft): string
    {
        $path='fiscal/nfe55/'.$draft->id.'/authorized.xml';
        if($draft->status!=='authorized'||$draft->xml_path!==$path
            ||!Storage::disk('local')->exists($path))throw new RuntimeException('NF-e sem XML autorizado para impressão.');
        $xml=Storage::disk('local')->get($path);
        if(strlen($xml)>6291456||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)){
            throw new RuntimeException('XML da NF-e inválido.');
        }
        $doc=new DOMDocument();
        if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->documentElement?->localName!=='nfeProc'){
            throw new RuntimeException('Documento autorizado ausente.');
        }
        $xp=new DOMXPath($doc);
        $xp->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
        $info=$xp->query('/n:nfeProc/n:NFe/n:infNFe')->item(0);
        $prot=$xp->query('/n:nfeProc/n:protNFe/n:infProt')->item(0);
        $v=static fn(string $expr,DOMElement $context)=>trim((string)$xp->evaluate('string('.$expr.')',$context));
        if(!$info instanceof DOMElement||!$prot instanceof DOMElement
            ||!in_array($draft->environment,['homologation','production'],true)
            ||!hash_equals('NFe'.$draft->access_key,$info->getAttribute('Id'))
            ||$v('n:ide/n:mod',$info)!=='55'
            ||$v('n:ide/n:tpAmb',$info)!==($draft->environment==='production'?'1':'2')
            ||$v('n:tpAmb',$prot)!==($draft->environment==='production'?'1':'2')
            ||$v('n:chNFe',$prot)!==$draft->access_key
            ||$v('n:nProt',$prot)!==$draft->protocol
            ||!in_array($v('n:cStat',$prot),['100','150'],true)) {
            throw new RuntimeException('NF-e não possui protocolo válido para este documento.');
        }
        $cnpj=preg_replace('/\D/','',(string)CompanySetting::current()->document);
        if($v('n:emit/n:CNPJ',$info)!==$cnpj)throw new RuntimeException('Emitente do XML não coincide com a empresa.');
        $pdf=$this->render($xml,CompanySetting::current());
        if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)>12582912)throw new RuntimeException('PDF fiscal inválido.');
        return $pdf;
    }

    protected function render(string $xml,CompanySetting $company): string
    {
        $dir=storage_path('app/acbr-runtime');
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){
            throw new RuntimeException('Diretório fiscal indisponível.');
        }
        $schemas=(string)config('services.acbr_nfe.schemas_path','');
        if(!is_dir($schemas)){
            foreach(['C:/laragon/acbr/dep/Schemas/NFe','C:/laragon/acbr/Schemas/NFe'] as $candidate){
                if(is_dir($candidate)){$schemas=$candidate;break;}
            }
        }
        if(!is_dir($schemas))throw new RuntimeException('Schemas fiscais indisponíveis.');
        $ini=$dir.'/danfe55-'.bin2hex(random_bytes(10)).'.ini';
        $service=new ACBrNFeService(null,$ini);
        try{
            $service->initialize();
            $service->setConfig('DFe','UF',strtoupper((string)$company->state));
            $service->setConfig('NFe','ModeloDF','0');
            $service->setConfig('NFe','PathSchemas',$schemas);
            // Nunca herdar configuração DANFE de NFC-e no INI base.
            $service->setConfig('DANFE','TipoDANFE','1');
            $service->setConfig('DANFE','MostraPreview','0');
            $service->setConfig('DANFE','MostraStatus','0');
            $service->loadXml($xml);
            return $service->savePdf();
        }finally{
            try{$service->close();}finally{if(is_file($ini))@unlink($ini);}
        }
    }
}
