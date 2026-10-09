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
