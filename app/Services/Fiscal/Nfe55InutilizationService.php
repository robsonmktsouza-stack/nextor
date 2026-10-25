<?php

namespace App\Services\Fiscal;

use App\Jobs\ProcessNfe55InutilizationJob;
use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Nfe55Inutilization;
use App\Models\NfeDraft;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class Nfe55InutilizationService
{
    public function __construct(
        private readonly Nfe55EmissionService $emission,
        private readonly NFCeFiscalEventResponse $responses,
    ) {}

    public function request(array $input, int $userId): Nfe55Inutilization
    {
        $record = DB::transaction(function () use ($input, $userId) {
            $environment = app(FiscalDocumentSettings::class)->environment('nfe');
            if ($environment !== 'homologation') {
                throw ValidationException::withMessages(['first_number' => 'A inutilização NF-e está disponível somente em homologação nesta versão.']);
            }
            // Same physical sequence row locked by model 55 number reservation.
            $sequence = AppSetting::query()->where('group', 'nfe')->where('key', 'next_number')->lockForUpdate()->first();
            $raw = $sequence?->value;
            $next = is_string($raw) && str_starts_with($raw, 'json:') ? json_decode(substr($raw, 5), true) : $raw;
            $company = CompanySetting::current();
            $issuer = preg_replace('/\D/', '', (string) $company->document);
            $year = (int) ($input['year'] ?? 0);
            $series = (int) ($input['series'] ?? -1);
            $first = (int) ($input['first_number'] ?? 0);
            $last = (int) ($input['last_number'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? ''));
            if (!InstanceIdentity::matches($company->document) || !preg_match('/^\d{14}$/', $issuer)
                || !$sequence || !is_numeric($next) || $sequence->is_secret
                || $year < 2020 || $year > (int) now()->year
                || $series < 0 || $series > 999 || $series !== (int) AppSetting::value('nfe', 'series', 1)
                || $first < 1 || $last < $first
                || $last > 999999999 || $last - $first > 999 || $last >= (int) $next
                || mb_strlen($reason) < 15 || mb_strlen($reason) > 255) {
                throw ValidationException::withMessages([
                    'first_number' => 'Informe uma lacuna anterior à próxima NF-e, com ano, série e justificativa válidos; limite de 1.000 números por solicitação.',
                ]);
            }
            if ($this->rangeUsed($environment, $series, $first, $last)) {
                throw ValidationException::withMessages(['first_number' => 'A faixa inclui número já reservado ou utilizado em uma NF-e.']);
            }
            if (Nfe55Inutilization::query()->where('issuer_document', $issuer)
                ->where('environment', $environment)->where('year', $year)->where('series', $series)
                ->where('first_number', '<=', $last)->where('last_number', '>=', $first)->exists()) {
                throw ValidationException::withMessages(['first_number' => 'Esta faixa já possui solicitação. Confira o resultado antes de qualquer nova operação.']);
            }
            return Nfe55Inutilization::query()->create([
                'issuer_document' => $issuer, 'environment' => $environment, 'year' => $year,
                'series' => $series, 'first_number' => $first, 'last_number' => $last,
                'reason' => $reason, 'status' => 'pending', 'requested_by' => $userId,
            ]);
        }, 3);

        try {
            ProcessNfe55InutilizationJob::dispatch($record->id)->onConnection('database')->onQueue('fiscal');
        } catch (Throwable) {
            // A database acknowledgement may be lost after enqueueing. Preserve
            // this request and never authorize a second overlapping operation.
            Nfe55Inutilization::query()->whereKey($record->id)->where('status', 'pending')
                ->update(['status' => 'uncertain', 'error_message' => 'Agendamento não confirmado. Confira a fila fiscal e a SEFAZ antes de prosseguir.']);
            throw ValidationException::withMessages(['first_number' => 'Pedido preservado, mas o agendamento não foi confirmado. Solicite conferência da fila fiscal.']);
        }
        return $record;
    }

    /** Explicit recovery of an existing request only before an external attempt. */
    public function retry(Nfe55Inutilization $record): Nfe55Inutilization
    {
        $lock = Cache::lock('nfe55-inutilization:'.$record->id, 300);
        if (!$lock->get()) {
            throw ValidationException::withMessages(['first_number' => 'Já existe uma operação fiscal em andamento para esta solicitação.']);
        }
        try {
            $updated = DB::transaction(function () use ($record) {
                $sequence = AppSetting::query()->where('group', 'nfe')->where('key', 'next_number')->lockForUpdate()->first();
                $row = Nfe55Inutilization::query()->lockForUpdate()->findOrFail($record->id);
                $stalled = $row->status === 'processing' && $row->updated_at?->lt(now()->subMinutes(15));
                $company = CompanySetting::current();
                $raw = $sequence?->value;
                $next = is_string($raw) && str_starts_with($raw, 'json:') ? json_decode(substr($raw, 5), true) : $raw;
                if ((!in_array($row->status, ['prepared', 'pending', 'uncertain'], true) && !$stalled)
                    || $row->attempted_at !== null || $row->protocol !== null
                    || $row->environment !== 'homologation'
                    || !InstanceIdentity::matches($company->document)
                    || $row->issuer_document !== preg_replace('/\D/', '', (string) $company->document)
                    || !$sequence || $sequence->is_secret || !is_numeric($next) || $row->last_number >= (int) $next
                    || (int) $row->series !== (int) AppSetting::value('nfe', 'series', 1)
                    || $this->rangeUsed($row->environment, $row->series, $row->first_number, $row->last_number)) {
                    throw ValidationException::withMessages([
                        'first_number' => 'Não é possível repetir esta solicitação. Confira a faixa e consulte a SEFAZ antes de prosseguir.',
                    ]);
                }
                $row->update(['status' => 'pending', 'error_message' => null]);
                return $row->refresh();
            }, 3);
            try {
                ProcessNfe55InutilizationJob::dispatch($updated->id)->onConnection('database')->onQueue('fiscal');
            } catch (Throwable) {
                Nfe55Inutilization::query()->whereKey($updated->id)->where('status', 'pending')->whereNull('attempted_at')
                    ->update(['status' => 'uncertain', 'error_message' => 'Reagendamento não confirmado; confira a fila fiscal.']);
                throw ValidationException::withMessages(['first_number' => 'A solicitação foi preservada, mas o reagendamento não foi confirmado.']);
            }
            return $updated;
        } finally {
            $lock->release();
        }
    }

    public function process(int $id): bool
    {
        $lock = Cache::lock('nfe55-inutilization:'.$id, 300);
        if (!$lock->get()) return false;
        $service = null;
        $runtime = null;
        $record = null;
        try {
            $record = DB::transaction(function () use ($id) {
                $row = Nfe55Inutilization::query()->lockForUpdate()->findOrFail($id);
                if ($row->status !== 'pending') return null;
                if ($row->attempted_at !== null) {
                    $row->update(['status' => 'uncertain', 'error_message' => 'Tentativa fiscal já registrada. Confira a SEFAZ antes de prosseguir.']);
                    return null;
                }
                $row->update(['status' => 'processing']);
                return $row->refresh();
            }, 3);
            if (!$record) return true;
            $company = CompanySetting::current();
            if ($record->environment !== 'homologation'
                || !InstanceIdentity::matches($company->document)
                || preg_replace('/\D/', '', (string) $company->document) !== $record->issuer_document
                || $this->rangeUsed($record->environment, $record->series, $record->first_number, $record->last_number)) {
                throw new RuntimeException('Emitente, ambiente ou disponibilidade da faixa não conferem.');
            }
            $context = new NfeDraft(['environment' => $record->environment]);
            $this->emission->assertReady($context, $company);
            $folder = storage_path('app/acbr-runtime');
            if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
                throw new RuntimeException('Diretório fiscal privado indisponível.');
            }
            $runtime = $folder.'/nfe55-inutilization-'.$id.'-'.bin2hex(random_bytes(8)).'.ini';
            $service = new ACBrNFeService(null, $runtime);
            $this->emission->configure($service, $company, $context, (string) $company->certificate_password);
            $record->update(['attempted_at' => now()]);
            $raw = $service->inutilize($record->issuer_document, $record->reason,
                $record->year, $record->series, $record->first_number, $record->last_number, 55);
            $this->applyResponse($record, $raw);
        } catch (Throwable $error) {
            if ($record) {
                $attempted = $record->attempted_at !== null;
                $record->update([
                    'status' => $attempted ? 'uncertain' : 'prepared', 'processed_at' => now(),
                    'error_message' => $attempted
                        ? 'Resultado indeterminado. Consulte a SEFAZ antes de qualquer nova operação.'
                        : 'Pedido não transmitido. Confira o certificado, o serviço fiscal e a disponibilidade da faixa.',
                ]);
            }
            \Illuminate\Support\Facades\Log::warning('NF-e: inutilização requer conferência', [
                'inutilization_id' => $id, 'exception_type' => get_class($error),
            ]);
        } finally {
            try { $service?->close(); } finally {
                if ($runtime && is_file($runtime)) @unlink($runtime);
                $lock->release();
            }
        }
        return true;
    }

    /** Persists only the receipt of the already attempted operation. Never sends. */
    public function applyResponse(Nfe55Inutilization $record, string $raw): void
    {
        if (strlen($raw) > 8388608 || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) {
            throw new RuntimeException('Retorno XML da inutilização inválido.');
        }
        if ($record->status !== 'processing' || $record->attempted_at === null) {
            throw new RuntimeException('A inutilização não possui tentativa fiscal em processamento.');
        }
        $path = 'fiscal/nfe55/inutilizations/'.$record->id.'/sefaz-response.ini';
        if (!Storage::disk('local')->put($path, $raw)) throw new RuntimeException('Retorno fiscal não armazenado.');
        $result = $this->responses->parse($raw, 'inutilizacao');
        if (!$this->responseMatches($result, $record)) {
            $record->update([
                'status' => 'uncertain', 'response_path' => $path, 'processed_at' => now(),
                'error_message' => 'A SEFAZ não confirmou a inutilização desta faixa. Resultado '.$result['cstat'].': confira o retorno antes de prosseguir.',
            ]);
            return;
        }
        $xmlPath = 'fiscal/nfe55/inutilizations/'.$record->id.'/authorized.xml';
        try {
            $stored = Storage::disk('local')->put($xmlPath, $result['xml']);
        } catch (Throwable) {
            $stored = false;
        }
        // A confirmed SEFAZ protocol is irreversible even if XML storage fails.
        $record->update([
            'status' => 'authorized', 'protocol' => $result['protocol'], 'response_path' => $path,
            'xml_path' => $stored ? $xmlPath : null, 'processed_at' => now(),
            'error_message' => $stored ? null : 'Inutilização confirmada; recuperação do XML pendente.',
        ]);
    }

    public function responseMatches(array $result, Nfe55Inutilization $record): bool
    {
        if (!$this->responses->inutilizationAccepted($result, $record->environment)) return false;
        $proof = $result['xml_proof'];
        return ($proof['model'] ?? '') === '55'
            && hash_equals($record->issuer_document, (string) ($proof['issuer'] ?? ''))
            && ctype_digit((string) ($proof['year'] ?? '')) && (int) $proof['year'] === (int) $record->year % 100
            && ctype_digit((string) ($proof['series'] ?? '')) && (int) $proof['series'] === (int) $record->series
            && ctype_digit((string) ($proof['first_number'] ?? '')) && (int) $proof['first_number'] === (int) $record->first_number
            && ctype_digit((string) ($proof['last_number'] ?? '')) && (int) $proof['last_number'] === (int) $record->last_number;
    }

    private function rangeUsed(string $environment, int $series, int $first, int $last): bool
    {
        return NfeDraft::query()->where('environment', $environment)->where('series', $series)
            ->whereBetween('document_number', [$first, $last])->exists();
    }
}
