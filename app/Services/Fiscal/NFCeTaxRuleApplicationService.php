<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NFCeTaxRuleApplicationService
{
    public function __construct(private readonly FiscalTaxRuleResolver $resolver) {}

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
            if (!(bool) AppSetting::value('tax', 'use_fiscal_rules', false)) {
                throw new RuntimeException('Ative as regras fiscais nas configurações para utilizá-las.');
            }
            $company = CompanySetting::current();
            if ((string) $company->crt !== '1' || strtoupper((string) $company->state) !== 'BA') {
                throw new RuntimeException('Esta primeira versão suporta somente NFC-e do Simples Nacional emitida na Bahia.');
            }

            $source = $job->source_snapshot ?? [];
            $items = $source['items'] ?? [];
            if (!is_array($items) || !$items) {
                throw new RuntimeException('A NFC-e não possui itens válidos.');
            }

            $date = ($source['operation_date'] ?? null) ?: $job->created_at?->toDateString();
            foreach ($items as $index => &$item) {
                if (($item['item_type'] ?? '') !== 'product') {
                    throw new RuntimeException('Regras iniciais disponíveis apenas para produtos.');
                }
                $rule = $this->resolver->resolve('nfce', 'BA', 'BA', '1', $item, $date);
                if (!$rule) {
                    throw new RuntimeException('Item '.($index + 1).': nenhuma regra fiscal ativa encontrada para o produto/NCM.');
                }
                // Nunca mascarar um grupo tributário que o emissor ainda não
                // implementa. Guardar a regra é diferente de poder transmitir.
                if (!preg_match('/^5\d{3}$/', $rule->cfop)
                    || $rule->csosn !== '102'
                    || $rule->pis_cst !== '49'
                    || $rule->cofins_cst !== '49') {
                    throw new RuntimeException('Item '.($index + 1).': a regra '.$rule->name.' não é compatível com o emissor NFC-e atual (CFOP 5xxx, CSOSN 102 e PIS/COFINS 49).');
                }

                $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
                $item['tax_defaults'] = array_replace($tax, [
                    'cfop_outbound_internal' => $rule->cfop,
                    'nfce_cfop' => $rule->cfop,
                    'cfop' => $rule->cfop,
                    'icms_csosn' => $rule->csosn,
                    'pis_cst' => $rule->pis_cst,
                    'cofins_cst' => $rule->cofins_cst,
                    'fiscal_rule_id' => $rule->id,
                    'fiscal_rule_revision' => $rule->revision,
                    'fiscal_rule_name' => $rule->name,
                ]);
            }
            unset($item);
            $source['items'] = $items;
            $job->update(['source_snapshot' => $source, 'error_message' => null]);
        }, 3);
    }
}
