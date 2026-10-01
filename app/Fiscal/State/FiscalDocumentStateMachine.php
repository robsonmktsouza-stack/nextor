<?php

namespace App\Fiscal\State;

use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Exceptions\InvalidStateTransitionException;

final class FiscalDocumentStateMachine
{
    /** @var array<string, list<FiscalDocumentState>> */
    private const TRANSITIONS = [
        'draft' => [
            FiscalDocumentState::GENERATED,
            FiscalDocumentState::ERROR,
        ],
        'generated' => [
            FiscalDocumentState::VALIDATED,
            FiscalDocumentState::ERROR,
        ],
        'validated' => [
            FiscalDocumentState::SIGNED,
            FiscalDocumentState::ERROR,
        ],
        'signed' => [
            FiscalDocumentState::PENDING,
            FiscalDocumentState::CONTINGENCY,
            FiscalDocumentState::ERROR,
        ],
        'pending' => [
            FiscalDocumentState::AUTHORIZED,
            FiscalDocumentState::REJECTED,
            FiscalDocumentState::ERROR,
        ],
        'rejected' => [
            FiscalDocumentState::GENERATED,
            FiscalDocumentState::ERROR,
        ],
        'contingency' => [
            FiscalDocumentState::PENDING,
            FiscalDocumentState::ERROR,
        ],
        'authorized' => [
            FiscalDocumentState::CANCELLED,
        ],
        'cancelled' => [],
        'error' => [
            FiscalDocumentState::GENERATED,
        ],
    ];

    public function canTransition(FiscalDocumentState $from, FiscalDocumentState $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertCanTransition(FiscalDocumentState $from, FiscalDocumentState $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new InvalidStateTransitionException(
                sprintf('Transição fiscal inválida: %s -> %s.', $from->value, $to->value)
            );
        }
    }
}
