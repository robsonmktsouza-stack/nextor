<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NFCeTaxRuleApplicationService
{
    public function __construct(
        private readonly NFCeFiscalProfileService $profiles,
    ) {}

    /**
     * Atualiza somente o snapshot tributário de uma NFC-e preparada, sem
     * modificar venda, estoque, pagamentos ou numeração já reservada.
     */
    public function apply(int $id): void
    {
        DB::transaction(function () use ($id) {
            $job = FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
            if ($job->document_type !== 'nfce' || $job->status !== 'prepared'
                || $job->access_key || $job->protocol || $job->xml_path
                || $job->response_path || $job->authorized_at) {
                throw new RuntimeException('Regras só podem ser aplicadas antes de assinar ou transmitir a NFC-e.');
            }
            $company = CompanySetting::current();
            $uf = strtoupper(trim((string)$company->state));
            if (!in_array((string)$company->crt, ['1','2','3','4'], true)) {
                throw new RuntimeException('Configure um CRT suportado nos dados tributários da empresa.');
            }
            if (!preg_match('/^(?:AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO)$/', $uf)) {
                throw new RuntimeException('Configure uma UF válida nos dados da empresa.');
            }

            $source = $job->source_snapshot ?? [];
            $items = $source['items'] ?? [];
            if (!is_array($items) || !$items) {
                throw new RuntimeException('A NFC-e não possui itens válidos.');
            }

            $date = ($source['operation_date'] ?? null) ?: $job->created_at?->toDateString();
            foreach ($items as $index => &$item) {
                try {
                    $result = $this->profiles->classify($item, $date, (string)$company->crt, $uf);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(
                        'Item '.($index + 1).': '.$exception->getMessage(),
                        0,
                        $exception
                    );
                }
                $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
                unset(
                    $tax['fiscal_rule_id'], $tax['fiscal_rule_revision'], $tax['fiscal_rule_name'],
                    $tax['fiscal_group_id'], $tax['fiscal_group_revision'], $tax['fiscal_group_name'],
                    $tax['fiscal_config_source']
                );
                $classification = $result['tax'];
                $item['tax_defaults']=array_replace($tax,$classification);
            }
            unset($item);
            $source['items'] = $items;
            $job->update(['source_snapshot' => $source, 'error_message' => null]);
        }, 3);
    }
}
