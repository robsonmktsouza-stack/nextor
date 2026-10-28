<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\Nfe55Inutilization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Atomic handoff between a checked draft and the existing ACBr worker. */
final class Nfe55NumberReservationService
{
    public function __construct(private readonly Nfe55DocumentSnapshot $snapshots) {}

    public function reserve(int $draftId, string $checkedFingerprint): NfeDraft
    {
        return DB::transaction(function () use ($draftId, $checkedFingerprint) {
            // Every editor mutation locks this same row before changing items.
            $draft = NfeDraft::query()->lockForUpdate()->findOrFail($draftId);
            if ($draft->status !== 'draft' || $draft->document_number !== null
                || $draft->access_key !== null || $draft->transmission_attempted_at !== null
                || $draft->protocol !== null || $draft->authorized_at !== null || $draft->xml_path !== null
                || $draft->cancelled_at !== null || $draft->cancellation_status !== null) {
                throw ValidationException::withMessages(['nfe' => 'Esta nota não pode ser transmitida novamente.']);
            }
            $draft->load(['items', 'operationNature']);
            $company = CompanySetting::query()->lockForUpdate()
                ->findOrFail(CompanySetting::current()->id);
            if (!$draft->validated_at || !hash_equals(
                $checkedFingerprint,
                $this->snapshots->fingerprint($draft, $company),
            )) {
                throw ValidationException::withMessages([
                    'nfe' => 'A nota ou seus dados fiscais foram alterados durante a conferência. Valide a NF-e novamente antes de emitir.',
                ]);
            }
            $numberKey = $draft->environment === 'production' ? 'production_next_number' : 'next_number';
            $settings=app(FiscalDocumentSettings::class);
            $numbering=$settings->nfeNumbering((string)$draft->environment,true);
            if($draft->environment==='production' && (!$settings->productionAuthorized('nfe')
                || (int)$draft->series!==$numbering['series'])) {
                throw ValidationException::withMessages(['nfe'=>'Confira a autorização e a série de produção antes de emitir.']);
            }
            $number = AppSetting::reserveInteger('nfe', $numberKey, 1);
            if ($number < 1 || $number > 999999999) {
                throw ValidationException::withMessages(['nfe' => 'A numeração da NF-e deve estar entre 1 e 999999999.']);
            }
            if(NfeDraft::query()->where('environment',$draft->environment)->where('series',$draft->series)
                ->where('document_number',$number)->whereKeyNot($draft->id)->exists()) {
                throw ValidationException::withMessages(['nfe'=>'O próximo número já foi reservado nesta série e ambiente. Corrija a sequência fiscal.']);
            }
            if (Nfe55Inutilization::query()->where('environment', $draft->environment)
                ->where('series', $draft->series)->where('first_number', '<=', $number)
                ->where('last_number', '>=', $number)->exists()) {
                throw ValidationException::withMessages(['nfe' => 'A numeração configurada coincide com uma solicitação de inutilização. Confira a sequência fiscal antes de emitir.']);
            }
            $draft->fill([
                'document_number' => $number,
                'numeric_code' => random_int(1, 99999999),
                'status' => 'queued',
                'error_message' => null,
                'emitter_snapshot' => $this->snapshots->issuer($company),
            ]);
            $custom = $draft->custom_fields ?? [];
            $custom['_reserved_fiscal_fingerprint'] = $this->snapshots->materialFingerprint($draft, $company);
            $draft->custom_fields = $custom;
            $draft->save();
            return $draft->refresh();
        }, 3);
    }
}
