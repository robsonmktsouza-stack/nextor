<?php

namespace App\Fiscal\Signature;

use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\State\FiscalDocumentStateMachine;

final class FiscalDocumentSignatureService
{
    public function __construct(
        private readonly A1SigningMaterialProvider $materials,
        private readonly XmlSigner $signer,
        private readonly FiscalDocumentStateMachine $states,
    ) {
    }

    public function sign(FiscalDocument $document): FiscalDocument
    {
        $this->states->assertCanTransition(
            $document->state,
            FiscalDocumentState::SIGNED,
        );

        if ($document->xml_generated === null || $document->xml_generated === '') {
            throw new XmlSignatureException('Documento não possui XML gerado para assinatura.');
        }

        if ($document->xml_signed !== null) {
            throw new XmlSignatureException('Documento já possui XML assinado.');
        }

        $material = $this->materials->forCompany($document->company);
        $result = $this->signer->sign(
            $document->xml_generated,
            (string) $document->access_key,
            $material,
        );

        $document->forceFill([
            'xml_signed' => $result->xml,
            'state' => FiscalDocumentState::SIGNED,
        ])->save();

        return $document->refresh();
    }
}
