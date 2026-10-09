<?php

namespace App\Support;

/**
 * Modelos de parametrização, nunca classificação automática por NCM.
 *
 * Fonte dos códigos: MOC 7.0 e tabelas oficiais de CFOP (IT 2023.002 v2.10,
 * 04/09/2026); regras MEI: NT 2024.001.
 * Não há alíquotas ICMS, PIS, COFINS, FCP ou IBS/CBS presumidas.
 */
final class FiscalTaxPresetCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $sn = 'Aplica-se apenas quando o enquadramento fiscal efetivo do produto '
            .'e da operação foi confirmado. Não é regra geral por NCM. '
            .'Não se destina a NF-e interestadual; emissão atual: NFC-e interna BA.';
        $normal = 'Modelo inicial: preencha alíquotas e classificação tributária '
            .'conforme operação, UF e regime antes de ativar. O modelo não '
            .'presume incidência, benefício ou alíquota.';
        return [
            'sn_resale_common' => [
                'name' => 'Simples | Revenda comum sem ST',
                'target_crt' => '1',
                'cfop_pattern' => '5102', 'icms_csosn' => '102',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => true,
                'notes' => $sn.' Revenda de mercadoria adquirida de terceiros '
                    .'sem ICMS-ST e sem tratamento especial de PIS/COFINS. '
                    .'A situação 49 é o enquadramento legado da emissão atual; '
                    .'confirmar a adequação ao item.',
            ],
            'sn_production_common' => [
                'name' => 'Simples | Venda de produção própria',
                'target_crt' => '1',
                'cfop_pattern' => '5101', 'icms_csosn' => '102',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => true,
                'notes' => $sn.' Somente para produto de fabricação própria '
                    .'classificado sem crédito de ICMS e sem tratamentos especiais.',
            ],
            'sn_resale_monophase' => [
                'name' => 'Simples | Revenda PIS/COFINS monofásico',
                'target_crt' => '1',
                'cfop_pattern' => '5102', 'icms_csosn' => '102',
                'pis_cst' => '04', 'cofins_cst' => '04',
                'is_active' => true,
                'notes' => $sn.' Apenas revenda efetivamente enquadrada no '
                    .'regime monofásico. A segregação das receitas no PGDAS-D '
                    .'continua necessária e é independente do XML.',
            ],
            'sn_resale_zero' => [
                'name' => 'Simples | Revenda PIS/COFINS alíquota zero',
                'target_crt' => '1',
                'cfop_pattern' => '5102', 'icms_csosn' => '102',
                'pis_cst' => '06', 'cofins_cst' => '06',
                'is_active' => true,
                'notes' => $sn.' Somente se houver fundamento legal para '
                    .'alíquota zero de ambas as contribuições.',
            ],
            'sn_resale_st_retained' => [
                'name' => 'Simples | Revenda com ICMS-ST anterior',
                'target_crt' => '1',
                'cfop_pattern' => '5405', 'icms_csosn' => '500',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => true,
                'notes' => $sn.' Informar no produto a base e o ICMS-ST '
                    .'retidos anteriormente e se os valores são por unidade '
                    .'ou pelo item. Não presume que todo produto com CEST '
                    .'tenha ST na operação.',
            ],
            'sn_immunity' => [
                'name' => 'Simples | Imunidade ICMS (verificar)',
                'target_crt' => '1',
                'cfop_pattern' => '5102', 'icms_csosn' => '300',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => false,
                'notes' => $sn.' Ativar somente após confirmação da hipótese '
                    .'legal de imunidade e exigências da UF.',
            ],
            'sn_unreached' => [
                'name' => 'Simples | Não tributada ICMS (verificar)',
                'target_crt' => '1',
                'cfop_pattern' => '5102', 'icms_csosn' => '400',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => false,
                'notes' => $sn.' Ativar após análise da não tributação e '
                    .'possíveis informações adicionais.',
            ],
            'sn_revenue_band_exemption' => [
                'name' => 'Simples | Isenção por faixa (verificar)',
                'target_crt' => '1',
                'cfop_pattern' => '5102', 'icms_csosn' => '103',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => false,
                'notes' => $sn.' CSOSN 103 depende das condições legais '
                    .'e da aplicação das regras de validação pela UF.',
            ],
            'mei_resale_common' => [
                'name' => 'MEI | Revenda comum (NFC-e)',
                'target_crt' => '4',
                'cfop_pattern' => '5102', 'icms_csosn' => '102',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => true,
                'notes' => 'Modelo 65/CRT 4: CFOP 5102 e CSOSN 102, '
                    .'conforme NT 2024.001. Verificar credenciamento, '
                    .'tratamento dos produtos e demais exigências da UF.',
            ],
            'mei_immunity' => [
                'name' => 'MEI | Mercadoria imune (verificar)',
                'target_crt' => '4',
                'cfop_pattern' => '5102', 'icms_csosn' => '300',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'is_active' => false,
                'notes' => 'Modelo 65/CRT 4: verificar hipótese real de '
                    .'imunidade antes de ativar. NT 2024.001.',
            ],
            'normal_icms_00' => [
                'name' => 'Regime normal | Revenda ICMS integral',
                'target_crt' => '3',
                'cfop_pattern' => '5102', 'icms_cst' => '00',
                'pis_cst' => '01', 'cofins_cst' => '01',
                'tax_config' => ['mod_bc' => '3'],
                'is_active' => false,
                'notes' => $normal.' Configurar ICMS, PIS e COFINS. '
                    .'CST PIS/COFINS 01 é apenas uma proposta para revisão.',
            ],
            'normal_icms_20' => [
                'name' => 'Regime normal | Revenda ICMS reduzido',
                'target_crt' => '3',
                'cfop_pattern' => '5102', 'icms_cst' => '20',
                'pis_cst' => '01', 'cofins_cst' => '01',
                'tax_config' => ['mod_bc' => '3'],
                'is_active' => false,
                'notes' => $normal.' Configurar ICMS, redução da base, '
                    .'PIS e COFINS; verificar benefício e desoneração.',
            ],
            'excess_icms_00' => [
                'name' => 'Simples excesso sublimite | ICMS integral',
                'target_crt' => '2',
                'cfop_pattern' => '5102', 'icms_cst' => '00',
                'pis_cst' => '49', 'cofins_cst' => '49',
                'tax_config' => ['mod_bc' => '3'],
                'is_active' => false,
                'notes' => $normal.' CRT 2 exige ICMS CST do regime normal '
                    .'mas não autoriza presumir alíquotas de PIS/COFINS.',
            ],
        ];
    }
}
