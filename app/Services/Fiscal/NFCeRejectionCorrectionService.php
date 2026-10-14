<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A rejected NFC-e is never overwritten or signed again in place.
 * A corrected fiscal attempt gets a separate document ID and archival
 * storage directory but uses the same completed sale, series and number.
 * No sale, stock, payment ledger or financial entry is changed here.
 */
final class NFCeRejectionCorrectionService
{
    private const REJECTION_GROUPS = [
        // Item classification, tax and CFOP (restricted to supported calculator scenarios).
        '777' => 'items', '778' => 'items', '611' => 'items',
        '383' => 'items', '386' => 'items', '382' => 'items',
        '766' => 'items', '725' => 'items',
        // Consumer identification.
        '237' => 'consumer', '220' => 'consumer',
        // Payment reporting, with sale total frozen.
        '769' => 'payments', '767' => 'payments',
        '865' => 'payments', '866' => 'payments', '869' => 'payments',
    ];

    public function __construct(
        private readonly NFCeSefazResponseParser $responses,
        private readonly NFCeProtocolXmlService $xml,
        private readonly NFCeTaxCalculationService $calculator,
        private readonly FiscalDocumentSettings $settings,
    ) {}

    public function rejection(FiscalDocumentJob $document): ?array
    {
        if ($document->document_type !== 'nfce'
            || $document->status !== 'rejected'
            || $document->emission_mode !== 'normal'
            || !$document->sale_id
            || $document->protocol || $document->authorized_at
            || $document->cancelled_at || $document->cancellation_status
            || preg_match('/^\d{44}$/', (string) $document->access_key) !== 1
            || FiscalDocumentJob::query()->where('corrected_from_id', $document->id)->exists()
            || FiscalDocumentJob::query()
                ->where('document_type', 'nfce')
                ->where('sale_id', $document->sale_id)
                ->where('id', '!=', $document->id)
                ->whereIn('status', ['authorized', 'processing', 'offline_signed', 'offline_sending'])
                ->exists()
        ) {
            return null;
        }

        $path = 'fiscal/nfce/'.$document->id.'/sefaz-response.ini';
        if (!Storage::disk('local')->exists($path)) {
            return null;
        }
        try {
            $result = $this->responses->parse(Storage::disk('local')->get($path));
        } catch (Throwable) {
            return null;
        }

        $code = (string) ($result['cstat'] ?? '');
        // Rejeição individual NFE* ou rejeição específica confirmada em
        // [RETORNO] do envio síncrono. Consultas e retornos de lote não
        // habilitam edição. O XML assinado é conferido em prepare().
        if (!$this->responses->definitiveCorrectionRejection($result)
            || !array_key_exists($code, self::REJECTION_GROUPS)
            || ($result['protocol'] ?? null)
            || (($result['key'] ?? null) !== null
                && !hash_equals((string) $document->access_key, (string) $result['key']))
        ) {
            return null;
        }

        return [
            'code' => $code,
            'group' => self::REJECTION_GROUPS[$code],
            'reason' => (string) ($result['reason'] ?? ''),
        ];
    }

    public function prepare(int $id, array $input, int $userId): FiscalDocumentJob
    {
        $lock = Cache::lock('nextor:nfce:'.$id, 300);
        if (!$lock->get()) {
            $this->fail('Documento fiscal em processamento. Aguarde.');
        }

        try {
            return DB::transaction(function () use ($id, $input, $userId) {
                $source = FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
                $existing = FiscalDocumentJob::query()->where('corrected_from_id', $id)->first();
                if ($existing) {
                    return $existing;
                }
                $rejection = $this->rejection($source);
                if (!$rejection) {
                    $this->fail('Esta nota ainda não pode ser corrigida. Consulte a situação na SEFAZ.');
                }
                if (!$source->sale || $source->sale->status !== 'completed') {
                    $this->fail('A venda vinculada precisa estar concluída.');
                }
                if (FiscalDocumentJob::query()->where('sale_id', $source->sale_id)
                    ->where('document_type', 'nfce')
                    ->whereIn('status', ['authorized', 'processing', 'offline_signed', 'offline_sending'])
                    ->exists()
                ) {
                    $this->fail('A venda já possui documento autorizado ou em processamento.');
                }

                $company = CompanySetting::current();
                if (!InstanceIdentity::matches($company->document)
                    || $this->settings->transmissionIssue('nfce', (string) $source->environment)
                    || $this->settings->environment('nfce') !== $source->environment
                    || (int) AppSetting::value('nfce', 'series', -1) !== (int) $source->series
                ) {
                    $this->fail('Os dados da empresa não correspondem aos da nota. Confira as configurações fiscais.');
                }

                $disk = Storage::disk('local');
                $base = 'fiscal/nfce/'.$source->id;
                $signedPath = $base.'/signed.xml';
                $responsePath = $base.'/sefaz-response.ini';
                if ($source->xml_path !== $signedPath
                    || !$disk->exists($signedPath) || !$disk->exists($responsePath)) {
                    $this->fail('Não foi possível acessar os arquivos da nota. Entre em contato com o suporte.');
                }

                $signed = $disk->get($signedPath);
                $response = $disk->get($responsePath);
                try {
                    $signedKey = $this->xml->verifySigned($signed, $source);
                } catch (RuntimeException) {
                    $this->fail('Não foi possível conferir o arquivo da nota. Entre em contato com o suporte.');
                }
                if (!hash_equals((string) $source->access_key, $signedKey)) {
                    $this->fail('Os dados da nota não conferem. Entre em contato com o suporte.');
                }

                $before = $source->source_snapshot ?? [];
                $after = $before;
                // O código da rejeição explica o erro original, mas não
                // limita quais dados fiscais desta versão podem ser revisados.
                // Somente os grupos enviados são aplicados; chamadas antigas
                // com um único grupo continuam compatíveis.
                $group = $rejection['group'];
                if (array_key_exists('items', $input)) {
                    $after = $this->correctItems($after, $input, (string) $company->crt);
                }
                if (array_key_exists('consumer_document', $input) || array_key_exists('consumer_name', $input)) {
                    $after = $this->correctConsumer($after, $input);
                }
                if (array_key_exists('payments', $input)) {
                    $after = $this->correctPayments($after, $input);
                }
                if (array_key_exists('nature', $input) || array_key_exists('notes', $input)) {
                    $validated = Validator::make($input, [
                        'nature' => ['sometimes', 'required', 'string', 'min:1', 'max:60'],
                        'notes' => ['nullable', 'string', 'max:2000'],
                    ])->validate();
                    if (array_key_exists('nature', $validated)) {
                        $after['nature'] = trim($validated['nature']);
                        if ($after['nature'] === '') {
                            $this->fail('Informe a natureza da operação.');
                        }
                    }
                    if (array_key_exists('notes', $input)) {
                        $after['notes'] = trim((string)($validated['notes'] ?? '')) ?: null;
                    }
                }

                $changed = $this->changedFields($before, $after);
                if ($changed === []) {
                    $this->fail('Faça uma alteração nos dados fiscais antes de salvar a correção.');
                }

                $settings = $source->settings_snapshot ?? [];
                foreach (['csc_token', 'municipal_password', 'api_key', 'api_secret'] as $secret) {
                    unset($settings[$secret]);
                }

                $copy = FiscalDocumentJob::query()->create([
                    'document_type' => 'nfce',
                    'sale_id' => $source->sale_id,
                    'corrected_from_id' => $source->id,
                    'status' => 'prepared',
                    'emission_mode' => 'normal',
                    'environment' => $source->environment,
                    'series' => $source->series,
                    // Rejected, not authorized: retain number. This is NOT
                    // the 539 duplicate workflow (which reserves another).
                    'document_number' => $source->document_number,
                    'settings_snapshot' => $settings,
                    'source_snapshot' => $after,
                    'prepared_at' => now(),
                ]);

                DB::table('nfce_correction_revisions')->insert([
                    'original_document_id' => $source->id,
                    'corrected_document_id' => $copy->id,
                    'user_id' => $userId,
                    'rejection_code' => $rejection['code'],
                    'original_access_key' => $source->access_key,
                    'original_xml_path' => $signedPath,
                    'original_response_path' => $responsePath,
                    'original_xml_sha256' => hash('sha256', $signed),
                    'original_response_sha256' => hash('sha256', $response),
                    'previous_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                    'corrected_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
                    'changed_fields' => json_encode(['group' => $group, 'code' => $rejection['code'],
                        'fields' => $changed], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $copy;
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function correctItems(array $snapshot, array $input, string $crt): array
    {
        $items = $snapshot['items'] ?? [];
        if (!is_array($items) || !$items || count($items) > 100) {
            $this->fail('A NFC-e não possui itens válidos para correção.');
        }
        $data = Validator::make($input, [
            'items' => ['required', 'array', 'size:'.count($items)],
            'items.*.ncm' => ['required', 'string', 'max:15'],
            'items.*.cfop' => ['required', 'regex:/^5\d{3}$/'],
            'items.*.origin' => ['required', Rule::in(array_map('strval', range(0, 8)))],
            'items.*.csosn' => ['nullable', Rule::in(['102','103','300','400','500'])],
            'items.*.icms_cst' => ['nullable', Rule::in(['00','20','40','41'])],
            'items.*.pis_cst' => ['required', Rule::in(['01','02','03','04','06','07','08','09','49','99'])],
            'items.*.cofins_cst' => ['required', Rule::in(['01','02','03','04','06','07','08','09','49','99'])],
            'items.*.gtin' => ['nullable', 'regex:/^\d{8}(\d{4,6})?$/'],
        ])->validate();

        if (array_keys($data['items']) !== array_keys($items)) {
            $this->fail('Não é possível alterar a ordem dos produtos nesta correção.');
        }
        foreach ($items as $i => &$item) {
            if (($item['item_type'] ?? '') !== 'product' || empty($item['product_id'])) {
                $this->fail('Não é possível incluir outros produtos nesta correção.');
            }

            $form = $data['items'][$i];
            $ncm = preg_replace('/\D/', '', (string) $form['ncm']);
            if (preg_match('/^[0-9.\s-]+$/', (string) $form['ncm']) !== 1
                || !preg_match('/^\d{8}$/', $ncm)) {
                $this->fail('Item '.($i+1).': NCM deve conter oito dígitos.');
            }
            $gtin = (string) ($form['gtin'] ?? '');
            if ($gtin !== '' && !$this->gtinValid($gtin)) {
                $this->fail('Item '.($i+1).': GTIN inválido.');
            }

            $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
            // Preserve existing tax rates, withheld-ST values, exemptions and
            // all commercial fields. Change only supported classification codes.
            $tax['cfop_outbound_internal'] = $form['cfop'];
            $tax['nfce_cfop'] = $form['cfop'];
            $tax['cfop'] = $form['cfop'];
            $tax['pis_cst'] = $form['pis_cst'];
            $tax['cofins_cst'] = $form['cofins_cst'];
            if (in_array($crt, ['1','4'], true)) {
                if (empty($form['csosn'])) {
                    $this->fail('Item '.($i+1).': informe o CSOSN.');
                }
                $tax['icms_csosn'] = $form['csosn'];
                unset($tax['icms_cst']);
            } elseif (in_array($crt, ['2','3'], true)) {
                if (empty($form['icms_cst'])) {
                    $this->fail('Item '.($i+1).': informe o CST do ICMS.');
                }
                $tax['icms_cst'] = $form['icms_cst'];
                unset($tax['icms_csosn']);
            } else {
                $this->fail('O regime tributário informado não permite emitir esta NFC-e.');
            }

            $item['ncm'] = $ncm;
            $item['gtin'] = $gtin;
            $item['origin'] = $form['origin'];
            $item['tax_defaults'] = $tax;
            try {
                $this->calculator->calculate($item, $crt);
            } catch (RuntimeException $exception) {
                $this->fail('Item '.($i+1).': '.$exception->getMessage());
            }
        }
        unset($item);
        $snapshot['items'] = $items;
        return $snapshot;
    }

    private function correctConsumer(array $snapshot, array $input): array
    {
        $data = Validator::make($input, [
            'consumer_document' => ['nullable', 'string', 'max:20'],
            'consumer_name' => ['nullable', 'string', 'max:190'],
        ])->validate();
        $document = preg_replace('/\D/', '', (string) ($data['consumer_document'] ?? ''));
        if ($document !== '' && !$this->documentValid($document)) {
            $this->fail('CPF/CNPJ do consumidor inválido.');
        }
        $name = trim((string) ($data['consumer_name'] ?? ''));
        if ($document !== '' && $name === '') {
            $this->fail('Informe o nome do consumidor identificado.');
        }
        $snapshot['consumer_document'] = $document ?: null;
        $snapshot['consumer_name'] = $document ? $name : null;
        return $snapshot;
    }

    private function correctPayments(array $snapshot, array $input): array
    {
        $payments = $snapshot['payments'] ?? [];
        if (!is_array($payments) || !$payments || count($payments) > 10) {
            $this->fail('A NFC-e não possui pagamentos válidos.');
        }
        $data = Validator::make($input, [
            'payments' => ['required', 'array', 'size:'.count($payments)],
            'payments.*.payment_method' => ['required', 'string', 'max:40', Rule::exists('payment_methods', 'code')->where('is_active', true)],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
        ])->validate();

        if (array_keys($data['payments']) !== array_keys($payments)) {
            $this->fail('Não é possível alterar a quantidade de pagamentos nesta correção.');
        }
        $paidCents = 0;
        foreach ($payments as $i => &$payment) {
            $form = $data['payments'][$i];
            $method = PaymentMethod::query()->where('code', $form['payment_method'])->where('is_active', true)->first();
            $kind = (string) ($method?->kind ?? '');
            $code = (string) ($method?->code ?? '');
            if ($kind === 'card') {
                $kind = str_contains($code, 'debit') || str_contains($code, 'debito')
                    ? 'debit_card' : (str_contains($code, 'credit') || str_contains($code, 'credito')
                    ? 'credit_card' : '');
            }
            if (!in_array($kind, ['cash', 'money', 'pix', 'credit_card', 'debit_card', 'bank_slip', 'bank_transfer', 'transfer'], true)) {
                $this->fail('Forma de pagamento não disponível para NFC-e: '.$code);
            }

            $payment['payment_method'] = $code;
            $payment['payment_kind'] = $kind;
            $payment['amount'] = number_format((float) $form['amount'], 2, '.', '');
            if (!in_array($kind, ['credit_card', 'debit_card'], true)) {
                foreach (['integration_type','transaction_document','transaction_state',
                    'institution_document','card_brand','authorization_code',
                    'beneficiary_document','terminal_id'] as $field) {
                    $payment[$field] = null;
                }
            } elseif (!in_array((string) ($payment['integration_type'] ?? ''), ['1','2'], true)) {
                $payment['integration_type'] = '2';
            }
            $paidCents += (int) round((float) $form['amount'] * 100);
        }
        unset($payment);

        if ($paidCents !== (int) round((float) ($snapshot['total'] ?? 0) * 100)) {
            $this->fail('A soma dos pagamentos deve ser igual ao valor da venda.');
        }
        if ((float) ($snapshot['change_amount'] ?? 0) > 0
            && !collect($payments)->contains(fn ($p) => in_array($p['payment_kind'], ['cash','money'], true))) {
            $this->fail('Existe troco em dinheiro, mas nenhum pagamento em espécie.');
        }
        $snapshot['payments'] = $payments;
        return $snapshot;
    }

    /** List only actual changes, including edits in other form tabs. */
    private function changedFields(array $before, array $after): array
    {
        $fields = [];
        foreach (($before['items'] ?? []) as $i => $original) {
            $updated = $after['items'][$i] ?? [];
            $originalTax = is_array($original['tax_defaults'] ?? null) ? $original['tax_defaults'] : [];
            $updatedTax = is_array($updated['tax_defaults'] ?? null) ? $updated['tax_defaults'] : [];
            foreach (['ncm', 'gtin', 'origin'] as $field) {
                if ((string)($original[$field] ?? '') !== (string)($updated[$field] ?? '')) {
                    $fields[] = 'items.'.$i.'.'.$field;
                }
            }
            $oldCfop = (string)($originalTax['cfop_outbound_internal']
                ?? $originalTax['nfce_cfop'] ?? $originalTax['cfop'] ?? '');
            $newCfop = (string)($updatedTax['cfop_outbound_internal']
                ?? $updatedTax['nfce_cfop'] ?? $updatedTax['cfop'] ?? '');
            if ($oldCfop !== $newCfop) $fields[] = 'items.'.$i.'.cfop';
            foreach (['icms_csosn','icms_cst','pis_cst','cofins_cst'] as $field) {
                if ((string)($originalTax[$field] ?? '') !== (string)($updatedTax[$field] ?? '')) {
                    $fields[] = 'items.'.$i.'.'.$field;
                }
            }
        }
        if (trim((string)($before['nature'] ?? 'VENDA DE MERCADORIA'))
            !== trim((string)($after['nature'] ?? 'VENDA DE MERCADORIA'))) {
            $fields[] = 'nature';
        }
        if (trim((string)($before['notes'] ?? '')) !== trim((string)($after['notes'] ?? ''))) {
            $fields[] = 'notes';
        }
        foreach (['consumer_document','consumer_name'] as $field) {
            if ((string)($before[$field] ?? '') !== (string)($after[$field] ?? '')) {
                $fields[] = $field;
            }
        }
        foreach (($before['payments'] ?? []) as $i => $original) {
            $updated = $after['payments'][$i] ?? [];
            foreach (['payment_method','amount'] as $field) {
                $first = (string)($original[$field] ?? '');
                $second = (string)($updated[$field] ?? '');
                if ($field === 'amount' && is_numeric($first) && is_numeric($second)) {
                    if ((int)round((float)$first*100) === (int)round((float)$second*100)) continue;
                }
                if ($first !== $second) $fields[] = 'payments.'.$i.'.'.$field;
            }
        }
        return $fields;
    }

    private function gtinValid(string $digits): bool
    {
        if (!in_array(strlen($digits), [8,12,13,14], true)) {
            return false;
        }
        $sum = 0;
        $body = substr($digits, 0, -1);
        for ($i = strlen($body) - 1, $weight = 3; $i >= 0; $i--, $weight = $weight === 3 ? 1 : 3) {
            $sum += (int) $body[$i] * $weight;
        }
        return ((10 - $sum % 10) % 10) === (int) substr($digits, -1);
    }

    private function documentValid(string $digits): bool
    {
        if (strlen($digits) === 11) {
            if (preg_match('/^(\d)\1{10}$/', $digits)) return false;
            for ($length = 9; $length <= 10; $length++) {
                $sum = 0;
                for ($i = 0; $i < $length; $i++) {
                    $sum += (int) $digits[$i] * ($length + 1 - $i);
                }
                $r = ($sum * 10) % 11;
                if ($r === 10) $r = 0;
                if ((int) $digits[$length] !== $r) return false;
            }
            return true;
        }
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) return false;
        foreach ([12,13] as $length) {
            $weights = $length === 12
                ? [5,4,3,2,9,8,7,6,5,4,3,2]
                : [6,5,4,3,2,9,8,7,6,5,4,3,2];
            $sum = 0;
            foreach ($weights as $i => $weight) $sum += (int)$digits[$i] * $weight;
            $rem = $sum % 11;
            if ((int)$digits[$length] !== ($rem < 2 ? 0 : 11 - $rem)) return false;
        }
        return true;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['nfce' => $message]);
    }
}
