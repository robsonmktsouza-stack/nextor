<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\FiscalTaxGroup;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NFCeTaxRuleApplicationService
{
    public function __construct(
        private readonly FiscalTaxRuleResolver $resolver,
        private readonly NFCeTaxGroupTranslator $groups,
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
                $group = null;
                $rule = null;
                $explicitId = (int) ($item['fiscal_tax_group_id'] ?? 0);

                if ($explicitId > 0) {
                    // Grupo explicitamente vinculado ao produto: não aceitar
                    // substituição silenciosa por grupo padrão ou regra genérica.
                    $group=FiscalTaxGroup::query()->find($explicitId);
                    if (!$group) {
                        throw new RuntimeException('Item '.($index+1).': grupo tributário vinculado não existe.');
                    }
                } else {
                    // Regras específicas por produto/NCM prevalecem sobre o
                    // grupo padrão, conforme o cadastro fiscal anterior.
                    $rule=$this->resolver->resolve('nfce','BA','BA','1',$item,$date);
                    if (!$rule) {
                        $defaults=FiscalTaxGroup::query()
                            ->where('kind','products')->where('is_active',true)
                            ->where('is_default',true)->limit(2)->get();
                        if ($defaults->count()>1) {
                            throw new RuntimeException('Existem grupos tributários padrão conflitantes para produtos.');
                        }
                        $group=$defaults->first();
                    }
                }

                $tax=is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
                // Ao reaplicar, não preservar identificadores de uma regra
                // anterior no snapshot do documento.
                unset(
                    $tax['fiscal_rule_id'],$tax['fiscal_rule_revision'],$tax['fiscal_rule_name'],
                    $tax['fiscal_group_id'],$tax['fiscal_group_revision'],$tax['fiscal_group_name']
                );

                if ($group) {
                    try {
                        $classification=$this->groups->translate($group);
                    } catch (RuntimeException $e) {
                        throw new RuntimeException('Item '.($index+1).': '.$e->getMessage(),0,$e);
                    }
                } elseif ($rule) {
                    if (!preg_match('/^5\d{3}$/',$rule->cfop)
                        || $rule->csosn!=='102'
                        || $rule->pis_cst!=='49'
                        || $rule->cofins_cst!=='49') {
                        throw new RuntimeException('Item '.($index+1).': regra '.$rule->name.' incompatível com a NFC-e atual.');
                    }
                    $classification=[
                        'cfop_outbound_internal'=>$rule->cfop,
                        'nfce_cfop'=>$rule->cfop,
                        'cfop'=>$rule->cfop,
                        'icms_csosn'=>$rule->csosn,
                        'pis_cst'=>$rule->pis_cst,
                        'cofins_cst'=>$rule->cofins_cst,
                        'fiscal_rule_id'=>$rule->id,
                        'fiscal_rule_revision'=>$rule->revision,
                        'fiscal_rule_name'=>$rule->name,
                    ];
                } else {
                    throw new RuntimeException('Item '.($index+1).': nenhum grupo ou regra fiscal ativa corresponde ao produto/NCM.');
                }

                $item['tax_defaults']=array_replace($tax,$classification);
            }
            unset($item);
            $source['items'] = $items;
            $job->update(['source_snapshot' => $source, 'error_message' => null]);
        }, 3);
    }
}
