<?php

namespace App\Support;

/**
 * Bandeiras tBand previstas pelo catálogo de pagamentos fiscal/ACBr.
 * Não inferir a bandeira do nome da instituição adquirente.
 */
final class NFCeCardBrands
{
    public const LABELS=[
        '01'=>'Visa','02'=>'Mastercard','03'=>'American Express',
        '04'=>'Sorocred','05'=>'Diners Club','06'=>'Elo',
        '07'=>'Hipercard','08'=>'Aura','09'=>'Cabal',
        '10'=>'Alelo','11'=>'BanesCard','12'=>'CalCard',
        '13'=>'Credz','14'=>'Discover','15'=>'GoodCard',
        '16'=>'GreenCard','17'=>'Hiper','18'=>'JCB',
        '19'=>'Mais','20'=>'MaxVan','21'=>'Policard',
        '22'=>'RedeCompras','23'=>'Sodexo','24'=>'ValeCard',
        '25'=>'Verocheque','26'=>'VR','27'=>'Ticket',
        '99'=>'Outros',
    ];

    public static function codes(): array
    {
        return array_map(static fn(int|string $code): string => str_pad((string)$code,2,'0',STR_PAD_LEFT),array_keys(self::LABELS));
    }
}
