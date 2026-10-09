<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Parse estrito do INI de retorno da ACBr.
 * Uma chamada à DLL com retorno zero NÃO é autorização fiscal.
 */
final class NFCeFiscalEventResponse
{
    public function parse(string $ini,string $section): array
    {
        $section=strtolower($section);
        $current='';
        $values=[];
        foreach (preg_split('/\r\n|\n|\r/',$ini) as $line) {
            $line=trim($line);
            if (preg_match('/^\[([^\]]+)\]$/',$line,$match)) {
                $current=strtolower(trim($match[1]));
                continue;
            }
            if ($current!==$section || !str_contains($line,'=')) continue;
            [$key,$value]=explode('=',$line,2);
            $values[strtolower(trim($key))]=trim($value);
        }
        if (!$values) {
            throw new RuntimeException('ACBr não retornou a seção fiscal esperada: '.$section);
        }

        $code=(string)($values['cstat']??'');
        if (!preg_match('/^\d{3}$/',$code)) {
            throw new RuntimeException('SEFAZ não retornou cStat individual verificável.');
        }

        // ACBr may expose the authorization protocol only inside the
        // official XML response. XML metadata is accepted only when the
        // embedded individual cStat, key and environment are consistent.
        $xml=trim((string)($values['xml']??''));
        if (str_starts_with($xml,'<') || str_starts_with($xml,'<?xml')) {
            $verified=$this->xmlProof($xml,$section);
            if ($verified !== null && $verified['cstat']===$code
                && (!isset($values['tpamb']) || $values['tpamb']===$verified['environment'])) {
                foreach (['nprot'=>'protocol','chdfe'=>'key','tpamb'=>'environment'] as $key=>$field) {
                    if (($values[$key]??'')==='' && ($verified[$field]??'')!=='') {
                        $values[$key]=$verified[$field];
                    }
                }
            }
        }

        return [
            'cstat'=>$code,
            'reason'=>(string)($values['xmotivo']??$values['msg']??''),
            'protocol'=>(string)($values['nprot']??''),
            'key'=>(string)($values['chdfe']??$values['chnfe']??''),
            'environment'=>(string)($values['tpamb']??''),
            'xml'=>(string)($values['xml']??''),
            'received_at'=>(string)($values['dhrecbto']??''),
        ];
    }

    private function xmlProof(string $xml,string $section): ?array
    {
        if(strlen($xml)>1000000)return null;
        $dom=new \DOMDocument('1.0','UTF-8');
        $previous=libxml_use_internal_errors(true);
        try{
            if(!$dom->loadXML($xml,LIBXML_NONET))return null;
        }finally{
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $ns='http://www.portalfiscal.inf.br/nfe';
        $xp=new \DOMXPath($dom);
        $xp->registerNamespace('n',$ns);
        $path=$section==='cancelamento'?'//n:retEvento/n:infEvento':'//n:retInutNFe/n:infInut';
        $info=$xp->query($path)->item(0);
        if(!$info instanceof \DOMElement)return null;
        $read=static fn(string $element)=>trim((string)$xp->evaluate('string(n:'.$element.')',$info));
        if($section==='cancelamento' && $read('tpEvento')!=='110111')return null;
        return [
            'cstat'=>$read('cStat'),
            'protocol'=>$read('nProt'),
            'key'=>$section==='cancelamento'?$read('chNFe'):'',
            'environment'=>$read('tpAmb'),
        ];
    }

    public function cancellationAccepted(array $data,string $key,string $environment): bool
    {
        if (!in_array($data['cstat']??'', ['101','135','155'],true)) return false;
        if (!preg_match('/^\d{15}$/',(string)($data['protocol']??''))) return false;
        if (!hash_equals($key,(string)($data['key']??''))) return false;
        return ($data['environment']??'') === ($environment==='production'?'1':'2');
    }

    public function inutilizationAccepted(array $data,string $environment): bool
    {
        return ($data['cstat']??'')==='102'
            && preg_match('/^\d{15}$/',(string)($data['protocol']??''))===1
            && ($data['environment']??'') === ($environment==='production'?'1':'2');
    }
}
