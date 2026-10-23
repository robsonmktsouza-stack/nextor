<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErpRemovalIconsTest extends TestCase
{
    public function test_purchase_editor_has_flat_surfaces_and_uses_trash_for_removal(): void
    {
        $css=file_get_contents(public_path('css/purchases.css'));
        $js=file_get_contents(public_path('js/purchases.js'));
        self::assertStringContainsString('.purchase-editor>.editor-panel{', $css);
        self::assertStringContainsString('border:0!important', $css);
        self::assertStringContainsString('.purchase-items{', $css);
        self::assertStringContainsString('border:0;background:transparent', $css);
        self::assertStringContainsString('data-purchase-remove', $js);
        self::assertStringContainsString('<svg width="16" height="16"', $js);
        self::assertStringNotContainsString('data-tooltip="Remover produto">×', $js);
    }

    public function test_all_known_dynamic_removal_controls_use_trash_icons(): void
    {
        $nfe=file_get_contents(public_path('js/nfe-editor.js'));
        $pdv=file_get_contents(public_path('js/pdv.js'));
        $customers=file_get_contents(resource_path('views/customers/form.blade.php'));
        foreach(['data-nfe-remove-item','data-nfe-remove-reference','data-nfe-remove-duplicate'] as $hook) {
            self::assertStringContainsString($hook, $nfe);
        }
        self::assertStringContainsString('removeTrashIcon', $nfe);
        self::assertStringNotContainsString('data-tooltip="Remover item">×', $nfe);
        self::assertStringNotContainsString('data-nfe-remove-reference="${index}">×', $nfe);
        self::assertStringNotContainsString('data-nfe-remove-duplicate="${index}">×', $nfe);
        self::assertStringContainsString("remove.innerHTML='<svg", $pdv);
        self::assertStringNotContainsString("remove.textContent='×'", $pdv);
        self::assertStringContainsString("@include('partials.icon',['name'=>'trash','size'=>16])", $customers);
    }
}
