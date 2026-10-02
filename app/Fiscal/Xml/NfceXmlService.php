<?php

namespace App\Fiscal\Xml;

use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Exceptions\ImmutableFiscalDocumentException;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\State\FiscalDocumentStateMachine;

final class NfceXmlService
{
    public function __construct(
        private readonly NfceXmlGenerator $generator,
        private readonly FiscalDocumentStateMachine $states,
    ) {
    }

    public function generate(FiscalDocument $document): FiscalDocument
    {
        if ($document->xml_generated !== null || $document->xml_signed !== null) {
            throw new ImmutableFiscalDocumentException(
                'Documento fiscal que já possui XML gerado/assinado não pode ser regenerado silenciosamente. '
                .'Crie um novo documento fiscal para uma nova tentativa lógica.'
            );
        }

        $this->states->assertCanTransition(
            $document->state,
            FiscalDocumentState::GENERATED,
        );

        $xml = $this->generator->generate($document);

        $document->forceFill([
            'xml_generated' => $xml,
            'state' => FiscalDocumentState::GENERATED,
        ])->save();

        return $document->refresh();
    }
}
