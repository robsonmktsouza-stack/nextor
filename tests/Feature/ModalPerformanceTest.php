<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ModalPerformanceTest extends TestCase
{
    public function test_global_modal_styles_avoid_full_screen_blur(): void
    {
        $main=file_get_contents(public_path('css/erp.css'));
        $pdv=file_get_contents(public_path('css/pdv.css'));

        self::assertIsString($main);
        self::assertIsString($pdv);
        self::assertStringNotContainsString('backdrop-filter:blur(1.5px)',$main);
        self::assertStringNotContainsString('backdrop-filter:blur(2px)',$pdv);
        self::assertStringContainsString('.nextor-confirm-backdrop',$main);
        self::assertStringContainsString('.nextor-loading',$main);
        self::assertStringContainsString('.erp-dialog::backdrop',$main);
    }

    public function test_dynamic_forms_use_one_shared_dom_observer_and_defer_closed_modals(): void
    {
        $js=file_get_contents(public_path('js/erp.js'));
        self::assertIsString($js);

        self::assertStringContainsString("dialog:not([open]), .nextor-modal-layer:not(.is-open)",$js);
        self::assertStringContainsString('const enhanceContainer=root=>',$js);
        self::assertStringContainsString('const modalOpenObserver=new MutationObserver',$js);
        self::assertStringContainsString('const dynamicSelectObserver=new MutationObserver',$js);
        self::assertStringNotContainsString('const numberObserver=new MutationObserver',$js);
        self::assertStringNotContainsString('const textMaskObserver=new MutationObserver',$js);
        self::assertStringContainsString("dynamicSelectObserver.observe(document.body,{childList:true,subtree:true})",$js);
        self::assertStringContainsString('document.createDocumentFragment()',$js);
        self::assertStringContainsString('menu.replaceChildren(fragment)',$js);
        self::assertStringContainsString('let selectLayoutScheduled=false',$js);
        self::assertStringContainsString('watchedModals.get(modal)?.disconnect()',$js);
        self::assertStringContainsString('watchedModals.delete(modal)',$js);
    }

    public function test_browser_regression_covers_both_types_of_modals(): void
    {
        $fixture=file_get_contents(base_path('tests/browser/modal-lifecycle.html'));
        $workflow=file_get_contents(base_path('.github/workflows/acbr-integration-checks.yml'));
        self::assertIsString($fixture);
        self::assertIsString($workflow);
        self::assertStringContainsString('document.body.dataset.modalLifecycleTest',$fixture);
        self::assertStringContainsString("native.showModal()",$fixture);
        self::assertStringContainsString("custom.classList.add('is-open')",$fixture);
        self::assertStringContainsString("modal-lifecycle) marker=",$workflow);
    }
}
