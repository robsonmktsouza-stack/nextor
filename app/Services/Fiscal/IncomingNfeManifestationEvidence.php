<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Individual-event parsing; never promote a lot status or a document-authorization
 * protocol into proof of the recipient's manifestation.
 */
final class IncomingNfeManifestationEvidence
{
    public function sent(string $raw, string $key, string $event): array
    {
        $sections=$this->sections($raw);
        $fields=$sections['evento001']??[];
        $code=(string)($fields['cstat']??'');
        if (preg_match('/^\d{3}$/',$code)) {
            $protocol=(string)($fields['nprot']??'');
            $sameKey=hash_equals($key,(string)($fields['chdfe']??$fields['chnfe']??''));
            $sameEvent=hash_equals($event,(string)($fields['tpevento']??''));
            $sameEnvironment=(string)($fields['tpamb']??'')==='1';
            $sameSequence=(!isset($fields['nseqevento']) || (string)$fields['nseqevento']==='1');
            if ($code==='135' && $sameKey && $sameEvent && $sameEnvironment
                && $sameSequence && preg_match('/^\d{15}$/',$protocol)) {
                return ['status'=>'accepted','code'=>$code,'protocol'=>$protocol,
                    'message'=>'Evento registrado e vinculado à NF-e.'];
            }
            if (!in_array($code,['135','136','573'],true)) {
                return ['status'=>'rejected','code'=>$code,'protocol'=>null,
                    'message'=>$this->rejectionMessage($code, (string)($fields['xmotivo']??''))];
            }
            return ['status'=>'uncertain','code'=>$code,'protocol'=>null,
                'message'=>'A SEFAZ não forneceu confirmação individual suficiente (cStat '.$code.').'];
        }

        return ['status'=>'uncertain','code'=>null,'protocol'=>null,
            'message'=>'ACBr não apresentou resultado individual verificável.'];
    }

    /**
     * Exibe somente o motivo individual da SEFAZ, nunca dumps da ACBrLib ou
     * arquivos privados. Não assumir que o status do lote autoriza o evento.
     */
    private function rejectionMessage(string $code, string $reason): string
    {
        if ($code==='596') {
            return 'Rejeição 596: Evento apresentado após o prazo permitido para a manifestação.';
        }
        if (!mb_check_encoding($reason,'UTF-8')) {
            return 'Rejeição '.$code.' informada pela SEFAZ.';
        }
        $reason=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags($reason)) ?? '');
        if ($reason!=='' && !preg_match('/senha|password|pfx|\.pem|\.ini|\\\\|\/[A-Za-z0-9_-]+\/|BEGIN |https?:\/\//iu',$reason)) {
            $reason=trim(preg_replace('/[^\p{L}\p{N}\s.,:;()\[\]\/\-]/u','',$reason) ?? '');
            if ($reason!=='') {
                return 'Rejeição '.$code.': '.mb_substr($reason,0,170);
            }
        }
        return 'Rejeição '.$code.' informada pela SEFAZ.';
    }

    /**
     * Reconsulta de leitura: somente XML de procEventoNFe com evento e retorno
     * vinculados à mesma chave, mesmo evento, CNPJ e ambiente comprova recepção.
     * Um [CONSULTA] cStat=100 confirma a NF-e, NÃO uma manifestação.
     */
    public function consulted(string $raw, string $key, string $event, string $cnpj): ?array
    {
        if (strlen($raw)>8388608) return null;
        $sections=$this->sections($raw);
        $candidates=[];
        foreach ($sections as $fields) {
            foreach (['xml','xmlevento','procevento'] as $field) {
                $xml=(string)($fields[$field]??'');
                if (str_contains($xml,'procEventoNFe')) $candidates[]=$xml;
            }
        }
        if (str_contains($raw,'<procEventoNFe')) {
            if (preg_match_all('~<procEventoNFe\b[^>]*>.*?</procEventoNFe>~si',$raw,$m)) {
                foreach ($m[0] as $xml) $candidates[]=$xml;
            }
        }
        foreach ($candidates as $xml) {
            $proof=$this->xmlProof($xml,$key,$event,$cnpj);
            if ($proof!==null) return $proof;
        }
        return null;
    }

    private function xmlProof(string $xml,string $key,string $event,string $cnpj): ?array
    {
        if(strlen($xml)>1048576)return null;
        $dom=new DOMDocument('1.0','UTF-8');
        $old=libxml_use_internal_errors(true);
        try {
            if(!$dom->loadXML($xml,LIBXML_NONET))return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $xp=new DOMXPath($dom);
        $xp->registerNamespace('n','http://www.portalfiscal.inf.br/nfe');
        foreach ($xp->query('//n:procEventoNFe') as $node) {
            if(!$node instanceof DOMElement)continue;
            $get=static fn(string $path): string => trim((string)$xp->evaluate('string('.$path.')',$node));
            $code=$get('n:retEvento/n:infEvento/n:cStat');
            $protocol=$get('n:retEvento/n:infEvento/n:nProt');
            if($code!=='135' || !preg_match('/^\d{15}$/',$protocol))continue;
            if($get('n:retEvento/n:infEvento/n:chNFe')!==$key
                || $get('n:retEvento/n:infEvento/n:tpEvento')!==$event
                || $get('n:retEvento/n:infEvento/n:tpAmb')!=='1'
                || $get('n:retEvento/n:infEvento/n:nSeqEvento')!=='1'
                || $get('n:evento/n:infEvento/n:chNFe')!==$key
                || $get('n:evento/n:infEvento/n:tpEvento')!==$event
                || $get('n:evento/n:infEvento/n:tpAmb')!=='1'
                || $get('n:evento/n:infEvento/n:CNPJ')!==$cnpj
                || $get('n:evento/n:infEvento/n:nSeqEvento')!=='1')continue;
            return ['status'=>'accepted','code'=>'135','protocol'=>$protocol,
                'message'=>'Evento confirmado na consulta fiscal.'];
        }
        return null;
    }

    private function sections(string $raw): array
    {
        $sections=[];
        $section='';
        foreach(preg_split('/\r\n|\r|\n/',$raw) as $line) {
            $line=trim($line);
            if(preg_match('/^\[([^\]]+)\]$/',$line,$m)) {
                $section=strtolower(trim($m[1]));
                continue;
            }
            if($section==='' || !str_contains($line,'='))continue;
            [$key,$value]=explode('=',$line,2);
            $sections[$section][strtolower(trim($key))]=trim($value);
        }
        return $sections;
    }
}
