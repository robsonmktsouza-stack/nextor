<?php

namespace Tests\Feature;

use App\Fiscal\Certificate\IcpBrasilSubjectDocumentExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalCertificateIcpBrasilTest extends TestCase
{
    #[Test]
    public function it_extracts_cnpj_from_icp_brasil_other_name_oid(): void
    {
        $san = $this->subjectAltName('2.16.76.1.3.3', '12345678000195');

        $document = (new IcpBrasilSubjectDocumentExtractor())
            ->extractFromSubjectAltNameDer($san);

        $this->assertSame('12345678000195', $document);
    }

    #[Test]
    public function it_extracts_cpf_from_icp_brasil_pf_data_oid(): void
    {
        $san = $this->subjectAltName('2.16.76.1.3.1', '0101199012345678901');

        $document = (new IcpBrasilSubjectDocumentExtractor())
            ->extractFromSubjectAltNameDer($san);

        $this->assertSame('12345678901', $document);
    }

    #[Test]
    public function it_does_not_treat_responsible_person_oid_as_holder_document(): void
    {
        $san = $this->subjectAltName('2.16.76.1.3.4', '12345678901');

        $document = (new IcpBrasilSubjectDocumentExtractor())
            ->extractFromSubjectAltNameDer($san);

        $this->assertNull($document);
    }

    #[Test]
    public function malformed_subject_alt_name_keeps_document_nullable(): void
    {
        $document = (new IcpBrasilSubjectDocumentExtractor())
            ->extractFromSubjectAltNameDer("\x30\x05\xA0");

        $this->assertNull($document);
    }

    private function subjectAltName(string $oid, string $text): string
    {
        $oidNode = $this->node(0x06, $this->oidValue($oid));
        $printableString = $this->node(0x13, $text);
        $explicitValue = $this->node(0xA0, $printableString);
        $otherName = $this->node(0xA0, $oidNode.$explicitValue);

        return $this->node(0x30, $otherName);
    }

    private function node(int $tag, string $content): string
    {
        return chr($tag).$this->length(strlen($content)).$content;
    }

    private function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function oidValue(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $encoded = chr(($parts[0] * 40) + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $stack = [chr($part & 0x7F)];
            $part >>= 7;

            while ($part > 0) {
                array_unshift($stack, chr(0x80 | ($part & 0x7F)));
                $part >>= 7;
            }

            $encoded .= implode('', $stack);
        }

        return $encoded;
    }
}
