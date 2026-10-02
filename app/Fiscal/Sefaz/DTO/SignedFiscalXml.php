<?php

namespace App\Fiscal\Sefaz\DTO;

use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\Sefaz\Exceptions\SefazResponseException;

final readonly class SignedFiscalXml
{
    private function __construct(private string $bytes)
    {
    }

    public static function fromValidatedDocument(FiscalDocument $document): self
    {
        if ($document->state !== FiscalDocumentState::VALIDATED) {
            throw new SefazResponseException(
                'Somente documento localmente validado pode ser entregue à camada SEFAZ.'
            );
        }

        if ($document->xml_signed === null || $document->xml_signed === '') {
            throw new SefazResponseException('Documento validado não possui XML assinado.');
        }

        return new self($document->xml_signed);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}
