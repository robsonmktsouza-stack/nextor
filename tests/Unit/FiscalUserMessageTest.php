<?php

namespace Tests\Unit;

use App\Services\Fiscal\NfeIcmsItemTaxCalculator;
use App\Support\FiscalUserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FiscalUserMessageTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_internal_diagnostics_have_actionable_business_wording(string $original, string $expected): void
    {
        $result = FiscalUserMessage::present($original);
        self::assertStringContainsString($expected, $result);
        self::assertDoesNotMatchRegularExpression('/implementad|snapshot|fingerprint|fiscal_rule_trace|[a-z]+_[a-z]+/', $result);
        self::assertSame($result, FiscalUserMessage::present($result));
    }

    public static function messages(): array
    {
        return [
            ['CST 10 do ICMS sem cálculo implementado.', 'CST 10 do ICMS'],
            ['IPI: CST sem cálculo implementado.', 'IPI: CST'],
            ['O parâmetro ibs_uf_deferral_rate exige grupo fiscal específico ainda não implementado para NF-e.', 'IBS estadual diferimento alíquota'],
            ['Grupo "Revenda": o campo cofins_st_rate está configurado, mas não é suportado pela NFC-e atual.', 'COFINS ST alíquota'],
            ['O snapshot da regra fiscal foi alterado.', 'Revise e valide novamente a regra'],
            ['Applicable fiscal rule lacks normative traceability.', 'fundamento legal'],
            ['No approved fiscal rule covers this operation.', 'Não há regra fiscal aprovada'],
            ['Conflicting fiscal rules have equal precedence.', 'regras fiscais em conflito'],
            ['Rule criteria must be an array.', 'critérios da regra fiscal'],
            ['Invalid effective date.', 'data de operação válida'],
            ['Invalid tax base or percentage.', 'base e a alíquota'],
            ['Número da DI inválido. DUIMP não está implementada neste fluxo.', 'DUIMP não está disponível'],
            ['RTC: tipo quantity incompatível com a operação inbound.', 'Por quantidade'],
            ['Forma de pagamento sem mapeamento fiscal: credit_card', 'Revise a forma de pagamento'],
            ['Falha da ACBr em NFE_EnviarEvento (código -10).', 'Confira a situação na SEFAZ'],
        ];
    }

    public function test_sefaz_rejection_code_and_original_reason_are_preserved(): void
    {
        foreach ([
            'Rejeição: CST com benefício fiscal e não informado o código de benefício fiscal [nItem:2]',
            'Retorno da SEFAZ: 778 - Rejeição: Informado NCM inexistente [nItem:2]',
            'Consulta SEFAZ: 217 - NF-e não consta na base de dados',
        ] as $original) self::assertSame($original, FiscalUserMessage::present($original));
    }

    public function test_fiscal_constraint_and_its_original_diagnostic_are_not_changed(): void
    {
        try {
            (new NfeIcmsItemTaxCalculator())->calculate(['icms_cst'=>'99'], 10000, '3');
            self::fail('Unsupported ICMS code must remain blocked.');
        } catch (RuntimeException $error) {
            self::assertSame('CST 99 do ICMS sem cálculo implementado.', $error->getMessage());
            self::assertSame('CST 99 do ICMS com tratamento indisponível para emissão.', FiscalUserMessage::present($error->getMessage()));
        }
    }

    public function test_actionable_fiscal_requirement_is_not_replaced_with_a_generic_error(): void
    {
        $message = 'CSOSN 900 não admite FCP próprio; FCP-ST exige o grupo ST.';
        self::assertSame($message, FiscalUserMessage::present($message));
    }
}
