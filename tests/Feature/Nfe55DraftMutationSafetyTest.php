<?php

namespace Tests\Feature;

use App\Http\Controllers\NfeDraftController;
use App\Models\NfeDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class Nfe55DraftMutationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_bound_model_cannot_update_delete_or_validate_reserved_note(): void
    {
        foreach(['update','destroy','validateDraft'] as $action){
            $stale=NfeDraft::query()->create(['issue_date'=>'2026-10-25','status'=>'draft',
                'additional_info'=>'Documento preservado','custom_fields'=>['_editor_form'=>['items_json'=>'[]']]]);
            NfeDraft::query()->whereKey($stale->id)->update(['status'=>'queued','document_number'=>123]);
            $request=Request::create('/fiscal/nfe','POST',['items_json'=>'[]']);
            try {
                $controller=app(NfeDraftController::class);
                if($action==='destroy')$controller->destroy($stale);
                else $controller->{$action}($request,$stale);
                self::fail('Uma instância desatualizada não pode modificar nota reservada: '.$action);
            }catch(HttpException $error){self::assertSame(409,$error->getStatusCode());}
            $saved=NfeDraft::query()->findOrFail($stale->id);
            self::assertSame('queued',$saved->status);
            self::assertSame(123,(int)$saved->document_number);
            self::assertSame('Documento preservado',$saved->additional_info);
        }
    }

    public function test_partial_save_cannot_clear_a_number_even_if_status_is_inconsistent(): void
    {
        $stale=NfeDraft::query()->create(['issue_date'=>'2026-10-25','status'=>'draft']);
        NfeDraft::query()->whereKey($stale->id)->update(['document_number'=>456]);
        try {
            app(NfeDraftController::class)->update(Request::create('/','PUT',[
                'draft_action'=>'draft','items_json'=>'[]','status'=>'draft','document_number'=>'',
            ]),$stale);
            self::fail('Uma numeração reservada não pode ser apagada.');
        }catch(HttpException $error){self::assertSame(409,$error->getStatusCode());}
        self::assertSame(456,(int)$stale->refresh()->document_number);
    }
}
