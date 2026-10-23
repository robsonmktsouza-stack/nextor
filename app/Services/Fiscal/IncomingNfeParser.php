<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Handles only NF-e model 55 summaries and full signed/authorized XML.
 * No entity resolution, remote references or filesystem access.
 */
final class IncomingNfeParser
{
    public function details(string $xml,string $companyCnpj): array
    {
        if(strlen($xml)>6291456 || !str_contains($xml,'<')) {
            throw new InvalidArgumentException('O XML da nota é inválido ou excede o tamanho permitido.');
        }
        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument('1.0','UTF-8');
            if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) {
                throw new InvalidArgumentException('O arquivo não contém um XML de NF-e válido.');
            }
            $xpath=new DOMXPath($dom);
            $inf=$xpath->query('//*[local-name()="infNFe"]')->item(0);
            if(!$inf instanceof DOMElement) {
                throw new InvalidArgumentException('O XML não contém uma NF-e completa.');
            }
            $key=preg_replace('/^NFe/','',(string)$inf->getAttribute('Id'));
            if(!preg_match('/^\d{44}$/',$key)) {
                throw new InvalidArgumentException('A chave de acesso da NF-e é inválida.');
            }
            $text=static fn(string $expr,?DOMElement $context=null): string =>
                trim((string)($xpath->evaluate('string('.$expr.')',$context?:$inf) ?: ''));
            if($text('./*[local-name()="ide"]/*[local-name()="mod"]')!=='55') {
                throw new InvalidArgumentException('Somente documentos NF-e modelo 55 são aceitos em Compras.');
            }
            $cnpj=preg_replace('/\D/','',$text('./*[local-name()="dest"]/*[local-name()="CNPJ"]'));
            if(!preg_match('/^\d{14}$/',$cnpj) || !hash_equals($companyCnpj,$cnpj)) {
                throw new InvalidArgumentException('Este XML não pertence ao CNPJ desta empresa.');
            }
            $supplier=preg_replace('/\D/','',$text('./*[local-name()="emit"]/*[local-name()="CNPJ"]'));
            $lines=[];
            foreach($xpath->query('./*[local-name()="det"]',$inf) as $det) {
                $prod=$xpath->query('./*[local-name()="prod"]',$det)->item(0);
                if(!$prod instanceof DOMElement) continue;
                $get=static fn($name)=>trim((string)$xpath->evaluate('string(./*[local-name()="'.$name.'"])',$prod));
                $lines[]=[
                    'number'=>(int)$det->getAttribute('nItem'),
                    'code'=>mb_substr($get('cProd'),0,80),
                    'ean'=>mb_substr($get('cEAN'),0,32),
                    'description'=>mb_substr($get('xProd'),0,190),
                    'ncm'=>mb_substr($get('NCM'),0,10),
                    'cfop'=>mb_substr($get('CFOP'),0,4),
                    'unit'=>mb_substr($get('uCom'),0,12),
                    'quantity'=>$get('qCom'),
                    'unit_price'=>$get('vUnCom'),
                    'total'=>$get('vProd'),
                ];
            }
            $issued=$text('./*[local-name()="ide"]/*[local-name()="dhEmi"]');
            if($issued==='') $issued=$text('./*[local-name()="ide"]/*[local-name()="dEmi"]');
            return [
                'access_key'=>$key,'supplier_cnpj'=>$supplier,
                'supplier_name'=>mb_substr($text('./*[local-name()="emit"]/*[local-name()="xNome"]'),0,190),
                'issued_at'=>$issued!=='' ? $issued : null,
                'total'=>$text('./*[local-name()="total"]/*[local-name()="ICMSTot"]/*[local-name()="vNF"]'),
                'item_count'=>count($lines),'items'=>$lines,
                'summary'=>[
                    'number'=>$text('./*[local-name()="ide"]/*[local-name()="nNF"]'),
                    'series'=>$text('./*[local-name()="ide"]/*[local-name()="serie"]'),
                    'recipient_cnpj'=>$cnpj,
                ],
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * ACBrLib returns an INI-style envelope: [DistribuicaoDFe],
     * [ResNFe001], [NFe001], [ProEve001], etc.
     * Only NFe summaries/full documents are imported. Events are not purchases.
     */
    public function envelope(string $raw): array
    {
        if(strlen($raw)>8388608) {
            throw new InvalidArgumentException('Resposta fiscal excedeu o limite permitido.');
        }
        $sections=[];
        $current=null;
        foreach(preg_split('/\r\n|\n|\r/',$raw) as $line) {
            $line=trim($line);
            if(preg_match('/^\[([A-Za-z0-9_]+)\]$/',$line,$match)) {
                $current=$match[1];
                $sections[$current]=[];
            } elseif($current!==null && str_contains($line,'=')) {
                [$key,$value]=explode('=',$line,2);
                $sections[$current][trim($key)]=trim($value);
            }
        }
        $header=$sections['DistribuicaoDFe']??$sections['DISTRIBUICAODFE']??[];
        if(!isset($header['cStat'])) {
            throw new InvalidArgumentException('O retorno da distribuição DF-e não contém situação válida.');
        }
        $status=(string)$header['cStat'];
        $last=(string)($header['ultNSU']??'');
        $maximum=(string)($header['maxNSU']??'');
        if(!preg_match('/^\d{1,15}$/',$last) || !preg_match('/^\d{1,15}$/',$maximum)) {
            throw new InvalidArgumentException('O retorno da SEFAZ não contém um NSU válido.');
        }
        $docs=[];
        foreach($sections as $name=>$values) {
            if(preg_match('/^(ResNFe|NFe)\d+$/i',$name)!==1) continue;
            $key=(string)($values['chNFe']??$values['chDFe']??'');
            $xml=$values['XML']??'';
            if(str_starts_with($xml,'<')) {
                $docs[]=['nsu'=>$values['NSU']??null,'access_key'=>$key,'xml'=>$xml,'values'=>$values];
            } else {
                $docs[]=['nsu'=>$values['NSU']??null,'access_key'=>$key,'xml'=>null,'values'=>$values];
            }
        }
        return [
            'status'=>$status,
            'reason'=>mb_substr((string)($header['xMotivo']??''),0,255),
            'last_nsu'=>str_pad($last,15,'0',STR_PAD_LEFT),
            'max_nsu'=>str_pad($maximum,15,'0',STR_PAD_LEFT),
            'documents'=>$docs,
        ];
    }
}
