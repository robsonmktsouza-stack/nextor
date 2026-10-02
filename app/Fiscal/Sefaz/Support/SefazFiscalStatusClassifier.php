<?php

namespace App\Fiscal\Sefaz\Support;

final class SefazFiscalStatusClassifier
{
    private function __construct()
    {
    }

    public static function classify(string $cStat): string
    {
        return match ($cStat) {
            '107' => 'operational',
            '108' => 'temporarily_unavailable',
            '109' => 'unavailable',
            default => 'other',
        };
    }
}
