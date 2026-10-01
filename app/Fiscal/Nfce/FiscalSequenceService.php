<?php

namespace App\Fiscal\Nfce;

use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Exceptions\InvalidFiscalDataException;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Models\FiscalSequence;
use Illuminate\Support\Facades\DB;

final class FiscalSequenceService
{
    public function ensure(
        FiscalCompany $company,
        int $series = 1,
        FiscalEnvironment $environment = FiscalEnvironment::HOMOLOGATION,
        int $nextNumber = 1,
    ): FiscalSequence {
        if ($series < 0 || $series > 999) {
            throw new InvalidFiscalDataException('Série deve estar entre 0 e 999.');
        }

        if ($nextNumber < 1 || $nextNumber > 999999999) {
            throw new InvalidFiscalDataException('Próximo número fiscal fora do intervalo permitido.');
        }

        return FiscalSequence::query()->firstOrCreate(
            [
                'fiscal_company_id' => $company->id,
                'model' => '65',
                'series' => $series,
                'environment' => $environment->value,
            ],
            [
                'next_number' => $nextNumber,
                'last_reserved_number' => null,
            ],
        );
    }

    public function reserveNext(
        FiscalCompany $company,
        int $series = 1,
        FiscalEnvironment $environment = FiscalEnvironment::HOMOLOGATION,
    ): int {
        return DB::transaction(function () use ($company, $series, $environment) {
            $sequence = FiscalSequence::query()
                ->where('fiscal_company_id', $company->id)
                ->where('model', '65')
                ->where('series', $series)
                ->where('environment', $environment->value)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                throw new InvalidFiscalDataException(
                    'Sequência fiscal não configurada. Execute ensure() ao configurar a empresa.'
                );
            }

            $number = (int) $sequence->next_number;

            if ($number < 1 || $number > 999999999) {
                throw new InvalidFiscalDataException('Numeração fiscal fora do intervalo permitido.');
            }

            $sequence->forceFill([
                'last_reserved_number' => $number,
                'next_number' => $number + 1,
            ])->save();

            return $number;
        }, 5);
    }
}
