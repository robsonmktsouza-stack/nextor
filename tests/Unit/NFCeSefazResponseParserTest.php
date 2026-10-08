<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeSefazResponseParser;
use PHPUnit\Framework\TestCase;

final class NFCeSefazResponseParserTest extends TestCase
{
    private NFCeSefazResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NFCeSefazResponseParser();
    }

    public function test_successful_batch_does_not_imply_invoice_was_authorized(): void
    {
        $response = "[ENVIO]\nCStat=103\nXMotivo=Lote recebido com sucesso\n".
            "[RETORNO]\nCStat=104\nXMotivo=Lote processado\n";
        $parsed = $this->parser->parse($response);

        self::assertSame('104', $parsed['cstat']);
        self::assertFalse($parsed['individual']);
        self::assertFalse($this->parser->authorized($parsed));
    }

    public function test_only_individual_100_with_valid_key_and_protocol_is_authorized(): void
    {
        $key = str_repeat('1', 44);
        $protocol = str_repeat('2', 15);
        $response = "[ENVIO]\nCStat=103\n[RETORNO]\nCStat=104\n".
            "[NFE7]\nCStat=100\nXMotivo=Autorizado o uso da NF-e\n".
            "chDFe={$key}\nNProt={$protocol}\nTpAmb=2\n".
            "VerAplic=BA_TESTE\nDhRecbto=2026-10-08T12:15:00-03:00\n".
            "DigVal=ABCD1234=\n";

        $parsed = $this->parser->parse($response);

        self::assertSame('100', $parsed['cstat']);
        self::assertSame($key, $parsed['key']);
        self::assertSame($protocol, $parsed['protocol']);
        self::assertTrue($parsed['individual']);
        self::assertTrue($this->parser->authorized($parsed));
    }

    public function test_authorization_with_invalid_protocol_is_not_accepted(): void
    {
        $parsed = $this->parser->parse("[NFE1]\nCStat=100\nchDFe=".str_repeat('1', 44)."\nNProt=INVALID\n");
        self::assertFalse($this->parser->authorized($parsed));
    }

    public function test_consultation_authorization_is_parsed_separately(): void
    {
        $parsed = $this->parser->parseConsult(
            "[CONSULTA]\nCStat=100\nXMotivo=Autorizado o uso da NF-e\n".
            "chDFe=".str_repeat('3', 44)."\nNProt=".str_repeat('4', 15)."\n".
            "TpAmb=2\nVerAplic=BA_TESTE\nDhRecbto=2026-10-08T12:15:00-03:00\nDigVal=ABCD1234=\n"
        );
        self::assertTrue($this->parser->authorized($parsed));
    }

    public function test_duplicate_invoice_response_must_not_be_treated_as_authorization(): void
    {
        $parsed = $this->parser->parse("[NFE7]\nCStat=204\nXMotivo=Duplicidade de NF-e\n");
        self::assertSame('204', $parsed['cstat']);
        self::assertFalse($this->parser->authorized($parsed));
    }
}
