<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55XmlTaxConsistency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55AuthorizedXmlConsistencyTest extends TestCase
{
    private function xml(string $groups): string
    {
        return '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe>'.$groups.'</infNFe></NFe>';
    }

    public function test_authorized_documents_and_their_order_must_match_the_ini(): void
    {
        $ini="[autXML001]\nCNPJCPF=11222333000181\n[autXML002]\nCNPJCPF=52998224725\n";
        $service=new Nfe55XmlTaxConsistency();
        $service->assertMatches($ini,$this->xml('<autXML><CNPJ>11222333000181</CNPJ></autXML><autXML><CPF>52998224725</CPF></autXML>'));
        self::assertTrue(true);
        $this->expectException(RuntimeException::class);
        $service->assertMatches($ini,$this->xml('<autXML><CPF>52998224725</CPF></autXML><autXML><CNPJ>11222333000181</CNPJ></autXML>'));
    }

    public function test_omitted_added_or_duplicated_authorizations_are_rejected(): void
    {
        foreach([
            ["[autXML001]\nCNPJCPF=11222333000181\n",''],
            ['', '<autXML><CNPJ>11222333000181</CNPJ></autXML>'],
            ["[autXML001]\nCNPJCPF=11222333000181\n",'<autXML><CNPJ>11222333000181</CNPJ><CPF>52998224725</CPF></autXML>'],
        ] as [$ini,$groups]){
            try {(new Nfe55XmlTaxConsistency())->assertMatches($ini,$this->xml($groups));self::fail('Autorização divergente.');}
            catch(RuntimeException $error){self::assertStringContainsString('autXML',$error->getMessage());}
        }
    }
}
