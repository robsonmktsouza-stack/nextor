<?php

namespace App\Services\Fiscal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Explicit local fiscal approval; installation/update never grants this approval. */
final class FiscalCatalogModelReviewService
{
    public function apply(Request $request, Model $model, array $data): array
    {
        if (!$model->catalog_version) return $data;
        if (empty($data['is_active'])) {
            return $data + ['catalog_status'=>'template','fiscal_reviewed_at'=>null,'fiscal_reviewed_by'=>null];
        }
        $review=$request->validate([
            'fiscal_review_confirmed'=>['required','accepted'],
            'fiscal_review_legal_reference'=>['required','string','max:2000'],
            'fiscal_review_source'=>['required','url:https','max:2000'],
            'fiscal_review_conditions'=>['required','string','max:5000'],
            'fiscal_review_valid_from'=>['required','date_format:Y-m-d'],
            'fiscal_review_valid_until'=>['nullable','date_format:Y-m-d','after_or_equal:fiscal_review_valid_from'],
        ], [
            'fiscal_review_confirmed.required'=>'Confirme a revisão fiscal antes de ativar o modelo do catálogo.',
            'fiscal_review_confirmed.accepted'=>'Confirme a revisão fiscal antes de ativar o modelo do catálogo.',
        ]);
        $host=strtolower((string)parse_url($review['fiscal_review_source'],PHP_URL_HOST));
        if (!str_ends_with($host,'.gov.br') && !str_ends_with($host,'.leg.br')
            && !in_array($host,['acbr.sourceforge.io','svn.code.sf.net'],true)) {
            throw ValidationException::withMessages(['fiscal_review_source'=>'Informe uma fonte oficial da legislação ou do leiaute aplicável.']);
        }
        $metadata=$model->catalog_metadata ?? [];
        $metadata['review']=[
            'legal_reference'=>$review['fiscal_review_legal_reference'],
            'source'=>$review['fiscal_review_source'],
            'conditions'=>$review['fiscal_review_conditions'],
            'valid_from'=>$review['fiscal_review_valid_from'],
            'valid_until'=>$review['fiscal_review_valid_until'] ?? null,
            'consulted_at'=>now()->toDateString(),
            'user_id'=>$request->user()?->id,
        ];
        return $data + ['catalog_status'=>'reviewed','catalog_metadata'=>$metadata,
            'fiscal_reviewed_at'=>now(),'fiscal_reviewed_by'=>$request->user()?->id];
    }

    public function record(Model $model, ?int $userId): void
    {
        $model->revisions()->firstOrCreate(['revision'=>$model->revision], [
            'user_id'=>$userId, 'snapshot'=>$model->attributesToArray(), 'created_at'=>now(),
        ]);
    }
}
