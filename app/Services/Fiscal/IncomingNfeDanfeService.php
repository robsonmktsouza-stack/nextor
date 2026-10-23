<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * DANFE de NF-e de entrada modelo 55, a partir de XML nfeProc autorizado.
 * Não utiliza resumo da SEFAZ nem dados reconstruídos do cadastro.
 */
class IncomingNfeDanfeService
{
    public function __construct(private readonly IncomingNfeSyncService $sync) {}

    public function generate(IncomingNfe $invoice): string
    {
        $path='fiscal/incoming-nfe/'.$invoice->access_key.'.xml';
        $disk=Storage::disk('local');
        if ($invoice->status!=='xml_received' || $invoice->xml_path!==$path || !$disk->exists($path)) {
            throw new RuntimeException('XML completo da NF-e indisponível.');
        }
        $xml=$disk->get($path);
        if (strlen($xml)>6291456 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) {
            throw new RuntimeException('XML fiscal inválido ou grande demais.');
        }
        $this->checkAuthorizedXml($xml,$invoice->access_key,$this->sync->companyCnpj());
        $pdf=$this->render($xml,CompanySetting::current());
        if (!str_starts_with($pdf,'%PDF-') || strlen($pdf)>12582912) {
            throw new RuntimeException('ACBr não retornou um PDF válido.');
        }
        return $pdf;
    }

    /**
     * Não basta constar a nota no banco: o XML local deve conter um nfeProc
     * com protocolo de autorização e chave/CNPJ correspondentes.
     */
    private function checkAuthorizedXml(string $xml,string $key,string $cnpj): void
    {
        $prev=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument('1.0','UTF-8');
            if (!$dom->loadXML($xml,LIBXML_NONET)
                || $dom->documentElement?->namespaceURI!=='http://www.portalfiscal.inf.br/nfe'
                || $dom->documentElement?->localName!=='nfeProc') {
                throw new RuntimeException('XML nfeProc autorizado necessário para o DANFE.');
            }
            $xp=new DOMXPath($dom);
            $xp->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
            $info=$xp->query('/n:nfeProc/n:NFe/n:infNFe')->item(0);
            $protocol=$xp->query('/n:nfeProc/n:protNFe/n:infProt')->item(0);
            if (!$info instanceof DOMElement || !$protocol instanceof DOMElement) {
                throw new RuntimeException('Protocolo de autorização ausente no XML.');
            }
            $read=static fn(string $expr,DOMElement $node): string=>
                trim((string)$xp->evaluate('string('.$expr.')',$node));
            if (!hash_equals('NFe'.$key,$info->getAttribute('Id'))
                || $read('n:ide/n:mod',$info)!=='55'
                || $read('n:dest/n:CNPJ',$info)!==$cnpj
                || $read('n:chNFe',$protocol)!==$key
                || !in_array($read('n:cStat',$protocol),['100','150'],true)
                || $read('n:ide/n:tpAmb',$info)!=='1'
                || $read('n:tpAmb',$protocol)!=='1'
                || !preg_match('/^\d{15}$/',$read('n:nProt',$protocol))) {
                throw new RuntimeException('Dados ou autorização da NF-e incompatíveis.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * ACBrLibNFe: NFE_CarregarXML seguido por NFE_SalvarPDF.
     * Método protegido apenas para simulação isolada em testes.
     */
    protected function render(string $xml,CompanySetting $company): string
    {
        $dir=storage_path('app/acbr-runtime');
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Diretório fiscal indisponível.');
        }
        $schemas=(string)config('services.acbr_nfe.schemas_path','');
        if ($schemas==='') {
            foreach ([
                'C:/laragon/acbr/dep/Schemas/NFe','C:/laragon/acbr/Schemas/NFe',
                'C:/laragon/acbr/dep/Schemas','C:/laragon/acbr/Schemas',
            ] as $candidate) {
                if (is_dir($candidate)) { $schemas=$candidate; break; }
            }
        }
        if (!is_dir($schemas)) throw new RuntimeException('Schemas fiscais indisponíveis.');
        $ini=$dir.'/incoming-danfe-'.bin2hex(random_bytes(12)).'.ini';
        $acbr=new ACBrNFeService(null,$ini);
        try {
            $acbr->initialize();
            $acbr->setConfig('DFe','UF',strtoupper((string)$company->state));
            $acbr->setConfig('NFe','ModeloDF','0');
            $acbr->setConfig('NFe','PathSchemas',$schemas);
            $acbr->loadXml($xml);
            return $acbr->savePdf();
        } finally {
            try { $acbr->close(); }
            finally { if(is_file($ini)) @unlink($ini); }
        }
    }
}
