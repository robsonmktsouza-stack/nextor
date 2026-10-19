<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regressão: o título da aba é a identificação do painel, não um segundo
 * cabeçalho ocupando espaço acima dos controles.
 */
final class FiscalConciseLayoutTest extends TestCase
{
    public function test_all_nfce_editor_modes_keep_tabs_without_duplicate_section_headings(): void
    {
        foreach ([
            'fiscal/nfce/create.blade.php',
            'fiscal/nfce/_readonly_panels.blade.php',
            'fiscal/nfce/correct-rejection.blade.php',
        ] as $path) {
            $source=file_get_contents(resource_path('views/'.$path));
            self::assertNotFalse($source);
            foreach (['Dados gerais','Consumidor','Pagamento','Resumo da NFC-e'] as $name) {
                self::assertStringNotContainsString('<h3>'.$name.'</h3>',$source,$path);
            }
            self::assertStringContainsString('data-nfce-panel="general"',$source);
            self::assertStringContainsString('data-nfce-panel="payment"',$source);
        }
    }

    public function test_nfce_create_keeps_actions_without_generic_tutorials(): void
    {
        $source=file_get_contents(resource_path('views/fiscal/nfce/create.blade.php'));
        self::assertStringContainsString('data-nfce-tab="{{ $key }}"',$source);
        self::assertStringContainsString("'general'=>'Dados gerais'",$source);
        self::assertStringContainsString('id="nfceAddProduct"',$source);
        self::assertStringContainsString('id="nfceAddPayment"',$source);
        self::assertStringContainsString('id="nfceIssue"',$source);
        self::assertStringContainsString('nfceFormError',$source);
        self::assertStringNotContainsString('nfce-compliance-note',$source);
        self::assertStringNotContainsString('não altere esses dados',$source);
        self::assertStringNotContainsString('NCM, CFOP, ICMS, PIS, COFINS e demais',$source);
        self::assertStringNotContainsString('<h3>Produtos</h3>',$source);
        self::assertStringContainsString('nfe-section-title--actions',$source);
    }

    public function test_nfe_editor_and_settings_keep_real_controls_without_duplicate_descriptions(): void
    {
        $nfe=file_get_contents(resource_path('views/fiscal/nfe/editor.blade.php'));
        self::assertStringContainsString('data-tab-target="nfe-payment"',$nfe);
        self::assertStringContainsString('data-nfe-general-toggle',$nfe);
        self::assertStringNotContainsString('<h3>Dados gerais</h3>',$nfe);
        self::assertStringNotContainsString('<h3>Pagamento</h3>',$nfe);
        self::assertStringNotContainsString('<h3>IBS / CBS — estrutura Lumeron</h3>',$nfe);

        $settings=file_get_contents(resource_path('views/settings/index.blade.php'));
        self::assertStringContainsString('name="production_csc_token"',$settings);
        self::assertStringContainsString('name="production_enabled"',$settings);
        self::assertStringNotContainsString('Configurações do cupom fiscal eletrônico.',$settings);
        self::assertStringNotContainsString('Série, ambiente e modalidade são controlados',$settings);

        $tax=file_get_contents(resource_path('views/fiscal/tax-groups/form.blade.php'));
        self::assertStringContainsString('<details class="erp-help-details">',$tax);
        self::assertStringContainsString('name="tax_config[fcp_st_retained_value]"',$tax);
    }
}
