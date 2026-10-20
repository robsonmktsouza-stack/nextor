<?php

namespace Tests\Feature;

use Tests\TestCase;

final class LumeronShellPaletteTest extends TestCase
{
    public function test_erp_shell_uses_gray_surfaces_and_blue_only_as_a_functional_accent(): void
    {
        $css=file_get_contents(public_path('css/erp.css'));
        self::assertIsString($css);
        $position=strpos($css,'/* NEXTOR color hierarchy inspired by the fiscal base system */');
        self::assertNotFalse($position);
        $palette=substr($css,$position);
        foreach ([
            '--base-topbar:#f2f2f2;',
            '--base-sidebar:#424b50;',
            '--base-sidebar-active:#30393e;',
            '--base-module:#798a92;',
            '--base-tab:#edf0f2;',
            '--base-tab-hover:#e0e6e9;',
            '.main-content{'."\n".'  background:#e9e9e9;',
            'background:var(--base-tab);'."\n".'  color:#45545e;',
        ] as $expected) {
            self::assertStringContainsString($expected,$palette);
        }

        // Neutral toolbar/tabs don't remove blue from primary buttons or
        // alter the semantic green for the active module and sale actions.
        self::assertStringContainsString('background:var(--base-green);',$palette);
        self::assertStringContainsString('background:#2e7eb7;',$palette);
    }

    public function test_quotation_pdf_remains_separate_from_erp_application_theme(): void
    {
        $document=file_get_contents(resource_path('views/sales/quote-pdf.blade.php'));
        self::assertIsString($document);
        self::assertStringContainsString('Itens do orçamento',$document);
        self::assertStringContainsString('<table class="items">',$document);
        self::assertStringNotContainsString('css/erp.css',$document);
    }
}
