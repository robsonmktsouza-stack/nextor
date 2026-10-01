<?php

namespace App\Fiscal\Nfce;

use App\Fiscal\DTO\AccessKeyData;
use App\Fiscal\Support\Modulo11;

final class AccessKeyGenerator
{
    public function generate(AccessKeyData $data): string
    {
        $base = $data->cUf
            .$data->issueDate->format('ym')
            .$data->emitterDocument
            .$data->model
            .str_pad((string) $data->series, 3, '0', STR_PAD_LEFT)
            .str_pad((string) $data->number, 9, '0', STR_PAD_LEFT)
            .$data->emissionType
            .$data->numericCode;

        return $base.Modulo11::accessKeyDigit($base);
    }

    public function generateNumericCode(): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
