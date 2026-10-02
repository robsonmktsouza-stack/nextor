<?php

namespace App\Fiscal\Validation;

use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\Schema\SchemaValidator;
use App\Fiscal\Signature\XmlSignatureException;
use App\Fiscal\Signature\XmlSignatureVerifier;
use App\Fiscal\State\FiscalDocumentStateMachine;

final class FiscalDocumentValidationService
{
    public function __construct(
        private readonly XmlSignatureVerifier $signatureVerifier,
        private readonly SchemaValidator $schemaValidator,
        private readonly FiscalDocumentStateMachine $states,
    ) {
    }

    public function validate(FiscalDocument $document): FiscalValidationResult
    {
        $this->states->assertCanTransition(
            $document->state,
            FiscalDocumentState::VALIDATED,
        );

        if ($document->xml_signed === null || $document->xml_signed === '') {
            throw new XmlSignatureException('Documento não possui XML assinado para validação.');
        }

        $signature = $this->signatureVerifier->verify(
            $document->xml_signed,
            (string) $document->access_key,
        );

        $schema = $this->schemaValidator->validate(
            $document->xml_signed,
            'NFe',
            (string) $document->layout_version,
        );

        $valid = $signature->valid && $schema->valid;

        $document->forceFill([
            'state' => $valid
                ? FiscalDocumentState::VALIDATED
                : FiscalDocumentState::ERROR,
        ])->save();

        return new FiscalValidationResult(
            valid: $valid,
            signature: $signature,
            schema: $schema,
        );
    }
}
