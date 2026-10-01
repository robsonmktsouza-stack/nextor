<?php

namespace App\Fiscal\DTO;

use App\Fiscal\Exceptions\InvalidFiscalDataException;
use App\Fiscal\Support\UfCode;
use DateTimeInterface;

final readonly class AccessKeyData
{
    public string $cUf;
    public string $emitterDocument;
    public string $model;
    public string $numericCode;

    public function __construct(
        string $cUf,
        public DateTimeInterface $issueDate,
        string $emitterDocument,
        string $model,
        public int $series,
        public int $number,
        public int $emissionType,
        string $numericCode,
    ) {
        $cUf = preg_replace('/\D/', '', $cUf) ?? '';
        $emitterDocument = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $emitterDocument) ?? '');
        $model = preg_replace('/\D/', '', $model) ?? '';
        $numericCode = preg_replace('/\D/', '', $numericCode) ?? '';

        if (!preg_match('/^\d{2}$/', $cUf) || !UfCode::isStateCode($cUf)) {
            throw new InvalidFiscalDataException('cUF não corresponde a uma UF/DF emissora válida.');
        }

        if (!preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $emitterDocument)) {
            throw new InvalidFiscalDataException(
                'CNPJ do emitente deve possuir 14 posições; as 12 primeiras podem ser alfanuméricas e as 2 últimas são numéricas.'
            );
        }

        if ($model !== '65') {
            throw new InvalidFiscalDataException('Nesta fase o motor aceita somente NFC-e modelo 65.');
        }

        if ($series < 0 || $series > 999) {
            throw new InvalidFiscalDataException('Série deve estar entre 0 e 999.');
        }

        if ($number < 1 || $number > 999999999) {
            throw new InvalidFiscalDataException('Número da NFC-e deve estar entre 1 e 999999999.');
        }

        if ($emissionType < 1 || $emissionType > 9) {
            throw new InvalidFiscalDataException('Tipo de emissão inválido.');
        }

        if (!preg_match('/^\d{8}$/', $numericCode)) {
            throw new InvalidFiscalDataException('cNF deve possuir exatamente 8 dígitos.');
        }

        $this->cUf = $cUf;
        $this->emitterDocument = $emitterDocument;
        $this->model = $model;
        $this->numericCode = $numericCode;
    }
}
