<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55XmlTaxConsistency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55XmlTaxConsistencyTest extends TestCase
{
    private function xml(string $tax, string $total = ''): string
    {
        return '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe><det nItem="1"><imposto>'.$tax
            .'</imposto></det><total>'.$total.'</total></infNFe></NFe>';
    }

    public function test_expected_item_groups_and_totals_match_using_exact_decimal_strings(): void
    {
        $ini = "[Produto001]\nxProd=Mercadoria com aspas \" ; livre\n[ICMS001]\norig=0\nCST=00\nmodBC=3\nvBC=100.00\nvICMS=18.00\n"
            ."[PISST001]\nvBC=100.00\npPIS=0.0000\nvPIS=0.00\nindSomaPISST=0\n"
            ."[IBSCBS001]\nCST=000\ncClassTrib=000001\n[gIBSCBS001]\nvBC=100.00\nvIBS=0.10\n"
            ."[gIBSUF001]\npIBSUF=0.1000\nvIBSUF=0.10\n[gIBSMun001]\npIBSMun=0.0000\nvIBSMun=0.00\n[gCBS001]\npCBS=0.9000\nvCBS=0.90\n"
            ."[IS001]\nCSTIS=123\ncClassTribIS=654321\nvBCIS=100.00\npIS=5.0000\nvIS=5.00\n"
            ."[Total]\nvNF=105.00\nvFCPUFDest=0.00\nvNFTot=106.00\n[ISTot]\nvIS=5.00\n"
            ."[IBSCBSTot]\nvBCIBSCBS=100.00\n[gIBS]\nvIBS=0.10\n[gIBSUFTot]\nvIBSUF=0.10\n[gIBSMunTot]\nvIBSMun=0.00\n[gCBSTot]\nvCBS=0.90\n";
        $tax = '<ICMS><ICMS00><orig>0</orig><CST>00</CST><modBC>3</modBC><vBC>100.00</vBC><vICMS>18.00</vICMS></ICMS00></ICMS>'
            .'<PISST><vBC>100</vBC><pPIS>0.00</pPIS><vPIS>0.00</vPIS><indSomaPISST>0</indSomaPISST></PISST>'
            .'<IBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib><gIBSCBS><vBC>100.00</vBC>'
            .'<gIBSUF><pIBSUF>0.10</pIBSUF><vIBSUF>0.10</vIBSUF></gIBSUF><gIBSMun><pIBSMun>0</pIBSMun><vIBSMun>0</vIBSMun></gIBSMun>'
            .'<vIBS>0.10</vIBS><gCBS><pCBS>0.90</pCBS><vCBS>0.90</vCBS></gCBS></gIBSCBS></IBSCBS>'
            .'<IS><CSTIS>123</CSTIS><cClassTribIS>654321</cClassTribIS><vBCIS>100</vBCIS><pIS>5.00</pIS><vIS>5</vIS></IS>';
        $total = '<ICMSTot><vNF>105.00</vNF></ICMSTot><vNFTot>106.00</vNFTot><ISTot><vIS>5.00</vIS></ISTot>'
            .'<IBSCBSTot><vBCIBSCBS>100.00</vBCIBSCBS><gIBS><gIBSUF><vIBSUF>0.10</vIBSUF></gIBSUF><gIBSMun><vIBSMun>0.00</vIBSMun></gIBSMun><vIBS>0.10</vIBS></gIBS><gCBS><vCBS>0.90</vCBS></gCBS></IBSCBSTot>';
        (new Nfe55XmlTaxConsistency())->assertMatches($ini, $this->xml($tax, $total));
        self::assertTrue(true);
    }

    public function test_zero_tax_classification_and_selective_tax_groups_must_not_be_omitted(): void
    {
        foreach (["[IBSCBS001]\nCST=410\ncClassTrib=410004\n", "[IS001]\nCSTIS=000\ncClassTribIS=000001\nvIS=0.00\n",
            "[PISST001]\nvPIS=0.00\n"] as $ini) $this->assertRejected($ini, $this->xml(''));
        $wrongItemXml = str_replace('</infNFe>',
            '<det nItem="2"><imposto><IBSCBS><CST>410</CST><cClassTrib>410004</cClassTrib></IBSCBS></imposto></det></infNFe>', $this->xml(''));
        $this->assertRejected("[IBSCBS001]\nCST=410\ncClassTrib=410004\n", $wrongItemXml);
    }

    public function test_missing_deferral_and_wrong_tax_or_code_values_are_blocked(): void
    {
        $ini = "[IBSCBS001]\nCST=510\ncClassTrib=510001\n[gIBSUF001]\npIBSUF=0.1000\npDif=50.0000\nvDif=0.05\nvIBSUF=0.05\n";
        $tax = '<IBSCBS><CST>510</CST><cClassTrib>510001</cClassTrib><gIBSCBS><gIBSUF><pIBSUF>0.10</pIBSUF><gDif><pDif>50.00</pDif><vDif>0.05</vDif></gDif><vIBSUF>0.05</vIBSUF></gIBSUF></gIBSCBS></IBSCBS>';
        $this->assertRejected($ini, $this->xml(str_replace('<vDif>0.05</vDif>', '', $tax)));
        $this->assertRejected($ini, $this->xml(str_replace('<vIBSUF>0.05</vIBSUF>', '<vIBSUF>0.04</vIBSUF>', $tax)));
        $this->assertRejected("[ICMS001]\nCST=00\n", $this->xml('<ICMS><ICMS00><CST>0</CST></ICMS00></ICMS>'));
    }

    public function test_large_decimals_remain_distinct_and_zero_required_fields_cannot_disappear(): void
    {
        $this->assertRejected("[Total]\nvNF=9999999999999.99\n", $this->xml('', '<ICMSTot><vNF>9999999999999.98</vNF></ICMSTot>'));
        $this->assertRejected("[Total]\nvNF=0.00\n", $this->xml('', '<ICMSTot/>'));
        $this->assertRejected("[IS001]\nCSTIS=000\ncClassTribIS=000001\npIS=0.0000\n", $this->xml('<IS><CSTIS>000</CSTIS><cClassTribIS>000001</cClassTribIS></IS>'));
    }

    public function test_dtd_and_entities_are_rejected_without_loading_external_resources(): void
    {
        foreach (['<!DOCTYPE NFe SYSTEM "https://example.test/forbidden.dtd">',
            '<!DOCTYPE NFe [<!ENTITY fiscal "injected">]>'] as $declaration) {
            $this->assertRejected("[Total]\nvNF=0.00\n", $declaration.$this->xml('', '<ICMSTot><vNF>0</vNF></ICMSTot>'));
        }
    }

    private function assertRejected(string $ini, string $xml): void
    {
        try {
            (new Nfe55XmlTaxConsistency())->assertMatches($ini, $xml);
            self::fail('XML fiscal divergente aceito.');
        } catch (RuntimeException $error) { self::assertStringContainsString('XML fiscal', $error->getMessage()); }
    }
}
