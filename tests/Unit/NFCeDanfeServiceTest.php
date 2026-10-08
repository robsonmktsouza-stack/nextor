<?php

namespace Tests\Unit;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeDanfeService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NFCeDanfeServiceTest extends TestCase
{
    private function job(array $attributes = []): FiscalDocumentJob
    {
        return new FiscalDocumentJob(array_replace([
            'document_type' => 'nfce',
            'status' => 'authorized',
            'environment' => 'homologation',
            'series' => 1,
            'document_number' => 1,
            'access_key' => str_repeat('1', 44),
            'protocol' => str_repeat('2', 15),
        ], $attributes));
    }

    private function xml(array $overrides = []): string
    {
        $key = str_repeat('1', 44);
        $protocol = str_repeat('2', 15);
        $status = $overrides['cstat'] ?? '100';
        $qr = $overrides['qr'] ?? "http://hnfe.sefaz.ba.gov.br/servicos/nfce/qrcode.aspx?p={$key}|2|2|1|HASH";
        $keyValue = $overrides['key'] ?? $key;

        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.
            '<NFe><infNFe Id="NFe'.$key.'" versao="4.00">'.
            '<ide><mod>65</mod><serie>1</serie><nNF>1</nNF><tpAmb>2</tpAmb>'.
            '<dhEmi>2026-10-08T13:55:35-03:00</dhEmi></ide>'.
            '<emit><CNPJ>39323356000100</CNPJ><xNome>EMPRESA TESTE LTDA</xNome>'.
            '<enderEmit><xLgr>RUA TESTE</xLgr><nro>100</nro><xBairro>CENTRO</xBairro>'.
            '<xMun>Rio do Antonio</xMun><UF>BA</UF><CEP>46220000</CEP></enderEmit></emit>'.
            '<det nItem="1"><prod><cProd>SKU-1</cProd><xProd>PRODUTO DE HOMOLOGACAO</xProd>'.
            '<qCom>1.0000</qCom><uCom>UN</uCom><vUnCom>12.9000000000</vUnCom><vProd>12.90</vProd></prod></det>'.
            '<total><ICMSTot><vProd>12.90</vProd><vNF>12.90</vNF></ICMSTot></total>'.
            '<pag><detPag><tPag>01</tPag><vPag>12.90</vPag></detPag></pag>'.
            '</infNFe><infNFeSupl><qrCode>'.$qr.'</qrCode>'.
            '<urlChave>http://hinternet.sefaz.ba.gov.br/nfce/consulta</urlChave>'.
            '</infNFeSupl></NFe>'.
            '<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb>'.
            '<chNFe>'.$keyValue.'</chNFe><nProt>'.$protocol.'</nProt>'.
            '<cStat>'.$status.'</cStat><dhRecbto>2026-10-08T13:55:37-03:00</dhRecbto>'.
            '</infProt></protNFe></nfeProc>';
    }

    public function test_danfe_is_extracted_from_authorized_xml_not_sale_records(): void
    {
        $data = (new NFCeDanfeService())->parse($this->xml(), $this->job());

        self::assertSame('EMPRESA TESTE LTDA', $data['issuer']['name']);
        self::assertSame('39323356000100', $data['issuer']['tax_id']);
        self::assertSame('RUA TESTE, 100, CENTRO', $data['issuer']['address']);
        self::assertSame('SKU-1', $data['items'][0]['code']);
        self::assertSame('PRODUTO DE HOMOLOGACAO', $data['items'][0]['description']);
        self::assertSame(12.9, $data['totals']['amount']);
        self::assertSame('Dinheiro', $data['payments'][0]['type']);
        self::assertSame('CONSUMIDOR NÃO IDENTIFICADO', $data['consumer_id'] === '' ? 'CONSUMIDOR NÃO IDENTIFICADO' : 'unexpected');
        self::assertSame(11, count(explode(' ', $data['key_groups'])));
        self::assertSame(str_repeat('2', 15), $data['protocol']);
        self::assertSame('08/10/2026 13:55:37', $data['authorized_at']);
        self::assertTrue($data['homologation']);
        self::assertStringContainsString(str_repeat('1',44), $data['qr_url']);
    }

    public function test_danfe_is_blocked_when_note_is_not_authorized(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeDanfeService())->parse($this->xml(), $this->job(['status' => 'pending']));
    }

    public function test_danfe_rejects_mismatched_access_key(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeDanfeService())->parse($this->xml(['key' => str_repeat('3', 44)]), $this->job());
    }

    public function test_danfe_rejects_inconsistent_qr_code_host(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeDanfeService())->parse($this->xml([
            'qr' => 'https://fake.example/nfce?p='.str_repeat('1', 44),
        ]), $this->job());
    }

    public function test_danfe_rejects_rejected_sefaz_response(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeDanfeService())->parse($this->xml(['cstat' => '204']), $this->job());
    }
}
