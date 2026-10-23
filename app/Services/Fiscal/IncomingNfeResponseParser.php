<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class IncomingNfeResponseParser
{
    public function parse(string $response): array
    {
        if(strlen($response)>8388608)throw new RuntimeException('Retorno da distribuição excede o limite.');
        $groups=[];
        $section='';
        foreach(preg_split('/\r\n|\n|\r/',$response) as $line){
            $line=trim($line);
            if($line==='' || $line[0]===';' || $line[0]==='#')continue;
            if(preg_match('/^\[([a-zA-Z0-9_]+)\]$/',$line,$m)){
                $section=strtolower($m[1]);$groups[$section]??=[];continue;
            }
            if($section!=='' && preg_match('/^([a-zA-Z0-9_]+)\s*=(.*)$/s',$line,$m)){
                $groups[$section][strtolower($m[1])]=$m[2];
            }
        }
        $meta=$groups['distribuicaodfe'] ?? null;
        if(!$meta || !in_array((string)($meta['cstat']??''),['137','138','656'],true)){
            throw new RuntimeException('A distribuição retornou um status não reconhecido: '.mb_substr((string)($meta['xmotivo']??'Resposta não reconhecida'),0,180));
        }
        $status=(string)$meta['cstat'];
        $last=(string)($meta['ultnsu']??'');
        $max=(string)($meta['maxnsu']??'');
        // When blocked by 656, SEFAZ may omit cursor: do not advance it.
        if($status!=='656' && (!preg_match('/^\d{1,15}$/',$last)
            || !preg_match('/^\d{1,15}$/',$max))) {
            throw new RuntimeException('Distribuição sem NSU válido; posição de consulta preservada.');
        }
        $records=[];
        foreach($groups as $key=>$entry){
            if(!preg_match('/^(resdfe|resnfe|procnfe|nfe)\d{1,5}$/',$key))continue;
            $nsu=(string)($entry['nsu']??'');
            if(!preg_match('/^\d{1,15}$/',$nsu))continue;
            $xml=trim((string)($entry['xml']??''));
            if(strlen($xml)>4194304)$xml='';
            $records[]=[
                'nsu'=>str_pad($nsu,15,'0',STR_PAD_LEFT),
                'access_key'=>(string)($entry['chdfe']??$entry['chnfe']??''),
                'issuer_cnpj'=>(string)($entry['emicnpj']??$entry['cnpj']??''),
                'issuer_name'=>(string)($entry['emixnome']??$entry['xnome']??''),
                'issued_at'=>(string)($entry['dhemi']??$entry['demi']??''),
                'total'=>(string)($entry['vnf']??''),
                'xml'=>$xml,
            ];
        }
        return [
            'status'=>$status,
            'message'=>mb_substr((string)($meta['xmotivo']??''),0,450),
            'last_nsu'=>$status==='656' ? null : str_pad($last,15,'0',STR_PAD_LEFT),
            'max_nsu'=>$status==='656' ? null : str_pad($max,15,'0',STR_PAD_LEFT),
            'documents'=>$records,
        ];
    }

    public function metadataFromXml(string $xml,string $destinationCnpj,?string $reportedKey=null): ?array
    {
        if($xml==='' || strlen($xml)>4194304 || str_contains($xml,'<!DOCTYPE'))return null;
        $doc=new DOMDocument();
        $prior=libxml_use_internal_errors(true);
        try {
            if(!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))return null;
            $x=new DOMXPath($doc);
            $value=static fn(string $query): string=>trim((string)$x->evaluate("string({$query})"));
            $nfe=$x->query('//*[local-name()="infNFe"]')->item(0);
            if(!$nfe)return null; // resumo XML is not the complete authorized invoice
            $key=preg_replace('/\D/','',(string)$nfe->attributes?->getNamedItem('Id')?->nodeValue);
            // Id starts with NFe. Keep only final 44 digits.
            $key=substr($key,-44);
            $recipient=preg_replace('/\D/','',$value('//*[local-name()="dest"]/*[local-name()="CNPJ"]'));
            if(strlen($key)!==44 || $recipient!==$destinationCnpj
                || ($reportedKey!==null && $reportedKey!=='' && $reportedKey!==$key))return null;
            return [
                'access_key'=>$key,
                'issuer_cnpj'=>preg_replace('/\D/','',$value('//*[local-name()="emit"]/*[local-name()="CNPJ"]')),
                'issuer_name'=>$value('//*[local-name()="emit"]/*[local-name()="xNome"]'),
                'document_number'=>$value('//*[local-name()="ide"]/*[local-name()="nNF"]'),
                'series'=>$value('//*[local-name()="ide"]/*[local-name()="serie"]'),
                'issued_at'=>$value('//*[local-name()="ide"]/*[local-name()="dhEmi"]'),
                'total'=>$value('//*[local-name()="ICMSTot"]/*[local-name()="vNF"]'),
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }
}
