<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\FiscalTaxGroup;
use App\Support\FiscalServicePresetCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Congela códigos do serviço configurado no snapshot fiscal da venda.
 *
 * Esta etapa NÃO calcula alíquotas municipais, não prepara DPS/XML
 * e não autoriza NFS-e. Dados inexistentes ou conflitantes são recusados.
 */
final class NFSeTaxRuleApplicationService
{
    public function apply(int $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            $job=FiscalDocumentJob::query()->lockForUpdate()->findOrFail($jobId);
            if ($job->document_type !== 'nfse' || $job->status !== 'prepared'
                || $job->xml_path || $job->access_key || $job->protocol) {
                throw new RuntimeException('A configuração só pode ser aplicada à NFS-e preparada.');
            }

            $company=CompanySetting::current();
            $snapshot=$job->source_snapshot ?? [];
            $items=$snapshot['items'] ?? [];
            if (!is_array($items) || !$items) {
                throw new RuntimeException('Documento de serviço sem itens.');
            }

            $services=0;
            foreach ($items as $index=>&$item) {
                if (($item['item_type'] ?? '') !== 'service') {
                    continue;
                }
                $services++;
                $groupId=(int)($item['fiscal_tax_group_id'] ?? 0);
                if (!$groupId) {
                    throw new RuntimeException('Serviço '.($index+1).': selecione a tributação no cadastro do serviço.');
                }
                $group=FiscalTaxGroup::query()->find($groupId);
                if (!$group || !$group->is_active || $group->kind !== 'services') {
                    throw new RuntimeException('Serviço '.($index+1).': grupo tributário indisponível.');
                }
                if ($group->target_crt !== null && (string)$group->target_crt !== (string)$company->crt) {
                    throw new RuntimeException('Serviço '.($index+1).': tributação incompatível com a empresa.');
                }
                $config=is_array($group->tax_config) ? $group->tax_config : [];
                $national=(string)($config['national_tax_code'] ?? '');
                $listItem=(string)($config['service_list_item'] ?? '');
                if (!preg_match('/^[0-9]{6}$/',$national)
                    || $listItem !== FiscalServicePresetCatalog::item($national)
                    || !in_array((string)$group->iss_exigibility,['1','2','3','4','5','6','7'],true)) {
                    throw new RuntimeException('Serviço '.($index+1).': grupo tributário incompleto.');
                }

                // Uma alíquota vinculada a outro município não pode ser usada.
                if (isset($config['iss_rate']) && $config['iss_rate'] !== '') {
                    if (($config['iss_city_ibge'] ?? '') !== (string)$company->city_ibge_code
                        || empty($config['iss_legal_reference'])) {
                        throw new RuntimeException('Serviço '.($index+1).': configuração de ISS de outro município ou sem referência legal.');
                    }
                }

                $item['national_tax_code']=$national;
                $item['service_list_item']=$listItem;
                $item['tax_defaults']=array_replace($config, [
                    'iss_exigibility'=>(string)$group->iss_exigibility,
                    'fiscal_group_id'=>$group->id,
                    'fiscal_group_revision'=>$group->revision,
                    'fiscal_group_name'=>$group->name,
                ]);
            }
            unset($item);
            if (!$services) {
                throw new RuntimeException('Documento sem serviços para classificação.');
            }
            $snapshot['items']=$items;
            $job->update(['source_snapshot'=>$snapshot, 'error_message'=>null]);
        },3);
    }
}
