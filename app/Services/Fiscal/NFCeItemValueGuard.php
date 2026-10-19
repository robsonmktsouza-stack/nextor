<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Evita diferenças entre valores comerciais e XML (qCom: 3 casas,
 * vUnCom: 4 casas, vProd/vDesc: 2 casas neste emissor).
 * Nunca arredonda silenciosamente um preço ou uma quantidade recebida.
 */
final class NFCeItemValueGuard
{
    public function verify(array $item): void
    {
        foreach (['quantity'=>3, 'unit_price'=>4, 'discount'=>2] as $field=>$places) {
            $raw = trim((string) ($item[$field] ?? ($field==='discount' ? '0' : '')));
            if (!preg_match('/^[0-9]+(?:\.[0-9]{1,'.$places.'})?$/', $raw)) {
                throw new RuntimeException("O campo {$field} excede a precisão fiscal de {$places} casas ou é inválido.");
            }
        }
        $qty=(float)$item['quantity'];
        $unit=(float)$item['unit_price'];
        $discount=(float)($item['discount'] ?? 0);
        if ($qty<=0 || $unit<0) {
            throw new RuntimeException('Quantidade ou preço de venda inválido.');
        }
        $grossCents=(int)round($qty*$unit*100,0,PHP_ROUND_HALF_UP);
        $discountCents=(int)round($discount*100,0,PHP_ROUND_HALF_UP);
        if ($discountCents>$grossCents) {
            throw new RuntimeException('Desconto do item superior ao valor bruto.');
        }
        $declared=$item['line_total'] ?? null;
        if ($declared!==null && trim((string)$declared)!=='') {
            $value=trim((string)$declared);
            if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/',$value)) {
                throw new RuntimeException('Total da linha deve ter até duas casas decimais.');
            }
            $declaredCents=(int)round((float)$value*100,0,PHP_ROUND_HALF_UP);
            if ($declaredCents!==$grossCents-$discountCents) {
                throw new RuntimeException('Valor líquido do item diverge de quantidade, preço e desconto.');
            }
        }
    }

    public function verifyGtin(mixed $gtin): void
    {
        $raw=trim((string)($gtin??''));
        if ($raw==='' || strtoupper($raw)==='SEM GTIN') {
            return;
        }
        if (!preg_match('/^[0-9]{8}$|^[0-9]{12}$|^[0-9]{13}$|^[0-9]{14}$/',$raw)) {
            throw new RuntimeException('GTIN/EAN deve conter 8, 12, 13 ou 14 dígitos, sem letras.');
        }
        // Módulo 10 GS1, da direita para a esquerda: 3/1 alternados.
        $sum=0;
        for($i=strlen($raw)-2,$weight=3;$i>=0;$i--,$weight=4-$weight) {
            $sum+=(int)$raw[$i]*$weight;
        }
        if ((10-$sum%10)%10!==(int)$raw[strlen($raw)-1]) {
            throw new RuntimeException('Dígito verificador do GTIN/EAN inválido.');
        }
    }
}
