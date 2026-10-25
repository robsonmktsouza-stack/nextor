<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Compares expected fiscal content with ACBr output; this does not validate schema or signatures. */
final class Nfe55XmlTaxConsistency
{
    private const ITEMS = [
        'ICMS'=>'n:imposto/n:ICMS', 'PIS'=>'n:imposto/n:PIS', 'COFINS'=>'n:imposto/n:COFINS',
        'IPI'=>'n:imposto/n:IPI', 'PISST'=>'n:imposto/n:PISST', 'COFINSST'=>'n:imposto/n:COFINSST',
        'II'=>'n:imposto/n:II', 'ICMSUFDEST'=>'n:imposto/n:ICMSUFDest',
        'IMPOSTODEVOL'=>'n:impostoDevol', 'IS'=>'n:imposto/n:IS', 'IBSCBS'=>'n:imposto/n:IBSCBS',
        'GIBSCBS'=>'n:imposto/n:IBSCBS//n:gIBSCBS', 'GIBSUF'=>'n:imposto/n:IBSCBS//n:gIBSUF',
        'GIBSMUN'=>'n:imposto/n:IBSCBS//n:gIBSMun', 'GCBS'=>'n:imposto/n:IBSCBS//n:gCBS',
        'GTRIBREGULAR'=>'n:imposto/n:IBSCBS//n:gTribRegular',
        'GTRANSFCRED'=>'n:imposto/n:IBSCBS//n:gTransfCred', 'GAJUSTECOMPET'=>'n:imposto/n:IBSCBS//n:gAjusteCompet',
    ];
    private const TOTALS = [
        'TOTAL'=>'n:total/n:ICMSTot', 'ISTOT'=>'n:total/n:ISTot', 'IBSCBSTOT'=>'n:total/n:IBSCBSTot',
        'GIBS'=>'n:total/n:IBSCBSTot/n:gIBS', 'GIBSUFTOT'=>'n:total/n:IBSCBSTot/n:gIBS/n:gIBSUF',
        'GIBSMUNTOT'=>'n:total/n:IBSCBSTot/n:gIBS/n:gIBSMun', 'GCBSTOT'=>'n:total/n:IBSCBSTot/n:gCBS',
    ];
    private const OPTIONAL_ZERO_TOTAL = ['vFCPUFDest','vICMSUFDest','vICMSUFRemet','qBCMono','vICMSMono',
        'qBCMonoReten','vICMSMonoReten','qBCMonoRet','vICMSMonoRet','vTotTrib','vNFTot'];

    public function assertMatches(string $ini, string $xml): void
    {
        if (trim($xml) === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('XML fiscal inválido: DTD e entidades não são permitidos.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new RuntimeException('XML fiscal inválido ou com DTD.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $invoices = $xpath->query('/n:NFe/n:infNFe | /n:nfeProc/n:NFe/n:infNFe');
            if ($invoices->length !== 1) throw new RuntimeException('XML fiscal sem uma única NF-e identificável.');
            $invoice = $invoices->item(0);
            foreach ($this->sections($ini) as $section=>$fields) {
                if (!$fields) continue;
                $name = strtoupper($section);
                $context = $invoice;
                if (isset(self::TOTALS[$name])) {
                    $path = self::TOTALS[$name];
                } elseif (preg_match('/^(.+?)(\d{3})$/D', $name, $match) && isset(self::ITEMS[$match[1]])) {
                    $items = $xpath->query('n:det[@nItem="'.(int)$match[2].'"]', $invoice);
                    if ($items->length !== 1) throw new RuntimeException('XML fiscal sem item correspondente a '.$section.'.');
                    $context = $items->item(0);
                    $path = self::ITEMS[$match[1]];
                } else continue;
                $groups = $xpath->query($path, $context);
                if ($groups->length !== 1) throw new RuntimeException('XML fiscal omitiu ou duplicou o grupo '.$section.'.');
                foreach ($fields as $field=>$expected) {
                    $nodes = $name === 'TOTAL' && $field === 'vNFTot'
                        ? $xpath->query('n:total/n:vNFTot', $invoice)
                        : $xpath->query('.//n:'.$field, $groups->item(0));
                    if ($nodes->length === 0 && $name === 'TOTAL' && in_array($field, self::OPTIONAL_ZERO_TOTAL, true)
                        && $this->value($field, $expected) === '0') continue;
                    if ($nodes->length !== 1 || !$nodes->item(0) instanceof DOMElement
                        || $this->value($field, $nodes->item(0)->textContent) !== $this->value($field, $expected)) {
                        throw new RuntimeException('XML fiscal divergente em '.$section.'.'.$field.'.');
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Free descriptions, quotes and semicolons remain ordinary values, never INI syntax. */
    private function sections(string $ini): array
    {
        $sections = [];
        $section = null;
        foreach (preg_split('/\r\n|\n|\r/', $ini) as $line) {
            $line = trim($line);
            if (preg_match('/^\[([A-Za-z][A-Za-z0-9]*)\]$/D', $line, $match)) {
                $section = $match[1];
                if (isset($sections[$section])) throw new RuntimeException('INI fiscal com seção duplicada: '.$section.'.');
                $sections[$section] = [];
            } elseif ($section !== null && preg_match('/^([A-Za-z][A-Za-z0-9]*)=(.*)$/D', $line, $match)) {
                if (array_key_exists($match[1], $sections[$section])) throw new RuntimeException('INI fiscal com campo duplicado.');
                if (trim($match[2]) !== '') $sections[$section][$match[1]] = trim($match[2]);
            }
        }
        return $sections;
    }

    private function value(string $field, string $value): string
    {
        $value = trim($value);
        if ($field === 'competApur' && preg_match('/^01\/(0[1-9]|1[0-2])\/(\d{4})$/D', $value, $match)) {
            return $match[2].'-'.$match[1];
        }
        if (!preg_match('/^(?:v|p|q|adRem)/', $field)) return $value;
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $value)) throw new RuntimeException('XML ou INI fiscal com decimal inválido em '.$field.'.');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }
}
