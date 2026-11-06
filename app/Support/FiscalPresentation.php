<?php

namespace App\Support;

/** Business-only projections; the underlying fiscal snapshots remain unchanged. */
final class FiscalPresentation
{
    public static function value(mixed $value): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? 'Sim' : 'Não';
        $labels = [
            'outbound'=>'Saída','inbound'=>'Entrada','internal'=>'Interna','interstate'=>'Interestadual',
            'foreign'=>'Exterior','normal'=>'Normal','complementary'=>'Complementar','adjustment'=>'Ajuste',
            'return'=>'Devolução','credit_note'=>'Nota de crédito','debit_note'=>'Nota de débito',
            'sale'=>'Venda','devolution'=>'Devolução','transfer'=>'Transferência','bonus'=>'Bonificação',
            'gift'=>'Brinde','sample'=>'Amostra grátis','remittance'=>'Remessa','return_of_remittance'=>'Retorno de remessa',
            'export'=>'Exportação','import'=>'Importação','contributor'=>'Contribuinte','non_contributor'=>'Não contribuinte',
            'exempt'=>'Isento','final_consumer'=>'Consumidor final','reseller'=>'Revendedor','industrial'=>'Industrial',
            'products'=>'Produtos','services'=>'Serviços','template'=>'Pendente de revisão','reviewed'=>'Revisado',
            'custom'=>'Personalizado','reference'=>'Referência','revoked'=>'Revogado',
            'nfe'=>'NF-e','nfce'=>'NFC-e','nfse'=>'NFS-e','percent'=>'Percentual','quantity'=>'Por quantidade',
            'combined'=>'Percentual e quantidade','line'=>'Por item','unit'=>'Por unidade',
            'simple'=>'Simples Nacional','simples'=>'Simples Nacional','normal_regime'=>'Regime normal',
            'simples_nacional'=>'Simples Nacional','lucro_presumido'=>'Lucro presumido','lucro_real'=>'Lucro real',
            'mei'=>'MEI','outro'=>'Outro regime',
            'a_vista'=>'À vista','30_dias'=>'30 dias','personalizado'=>'Personalizado',
            'cash'=>'Dinheiro','credit_card'=>'Cartão de crédito','debit_card'=>'Cartão de débito',
            'card'=>'Cartão','bank_transfer'=>'Transferência bancária','pix'=>'Pix','boleto'=>'Boleto',
            'complete'=>'Completa','partial'=>'Parcial','not_bundled'=>'Sem tabela validada','unavailable'=>'Indisponível',
            'authorized'=>'Autorizado','rejected'=>'Rejeitado','pending'=>'Pendente','prepared'=>'Não enviado',
            'processing'=>'Em processamento','queued'=>'Aguardando envio','uncertain'=>'Confirmação pendente',
            'cancelled'=>'Cancelado','failed'=>'Falha no processamento','error'=>'Erro no processamento',
        ];
        return $labels[(string) $value] ?? (string) $value;
    }

    public static function rows(array $values, string $prefix = ''): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $label = self::label((string) $key);
            if ($label === null) continue;
            $label = $prefix.$label;
            if (is_array($value)) {
                if (array_is_list($value) && count(array_filter($value, 'is_scalar')) === count($value)) {
                    $rows[] = ['label'=>$label,'value'=>implode(', ', array_map(self::value(...), $value)) ?: '—'];
                } else {
                    $rows = array_merge($rows, self::rows($value, $label.' · '));
                }
            } else {
                $rows[] = ['label'=>$label,'value'=>self::value($value)];
            }
        }
        return $rows;
    }

    public static function label(string $key): ?string
    {
        $labels = [
            'name'=>'Nome','document_type'=>'Documento','kind'=>'Tipo','is_active'=>'Ativo',
            'ncm'=>'NCM','unit'=>'Unidade','tax_unit'=>'Unidade tributável','quantity'=>'Quantidade',
            'unit_price'=>'Preço unitário','ean_gtin'=>'GTIN','notes'=>'Observações',
            'is_default'=>'Padrão','priority'=>'Prioridade','valid_from'=>'Vigência inicial','valid_until'=>'Vigência final',
            'origin_uf'=>'UF de origem','destination_uf'=>'UF de destino','origin_municipality'=>'Município de origem',
            'destination_municipality'=>'Município de destino','crt'=>'CRT','tax_regime'=>'Regime tributário',
            'ncm_prefix'=>'Prefixo NCM','ncm_prefixes'=>'NCM vinculados','cest'=>'CEST','origin'=>'Origem da mercadoria',
            'operation_type'=>'Entrada ou saída','fiscal_operation'=>'Operação','purpose'=>'Finalidade',
            'recipient_profile'=>'Perfil do destinatário','recipient_icms_indicator'=>'Contribuinte do ICMS',
            'final_consumer'=>'Consumidor final','cfop'=>'CFOP','input_cfop'=>'CFOP de origem',
            'cfop_internal'=>'CFOP interno','cfop_interstate'=>'CFOP interestadual','cfop_foreign'=>'CFOP exterior',
            'cfop_inbound_internal'=>'CFOP de entrada interna','cfop_inbound_interstate'=>'CFOP de entrada interestadual',
            'cfop_pattern'=>'CFOP padrão','override_product_cfop'=>'Substituir CFOP do produto','move_stock'=>'Movimentar estoque',
            'csosn'=>'CSOSN','icms_csosn'=>'CSOSN ICMS','icms_cst'=>'CST ICMS','pis_cst'=>'CST PIS',
            'cofins_cst'=>'CST COFINS','ipi_cst'=>'CST IPI','fiscal_benefit_code'=>'Benefício fiscal',
            'ind_nfe'=>'Permitido em NF-e','ind_nfce'=>'Permitido em NFC-e','ind_devolution'=>'Devolução',
            'icms_csts'=>'CST ICMS permitidos','csosns'=>'CSOSN permitidos','crts'=>'CRT permitidos',
            'excluded_ufs'=>'UFs excluídas','tax_parameters'=>'Tributos','tax_config'=>'Tributação',
            'service_list_item'=>'Item da lista de serviços','national_tax_code'=>'Código nacional de tributação',
            'iss_exigibility'=>'Exigibilidade do ISS','legal_reference'=>'Fundamento legal','legal_source'=>'Fonte oficial',
            'nfce_csosn'=>'CSOSN NFC-e','nfce_icms_cst'=>'CST ICMS NFC-e',
        ];
        if (isset($labels[$key])) return $labels[$key];
        // Only recognized tax names are projected; ids, hashes, technical notes,
        // provenance and unknown metadata remain in the audit storage.
        if (!preg_match('/^(?:icms|st_retained|fcp|difal|ipi|pis|cofins|ibs|cbs|rtc|is)_/', $key)) return null;
        $words = [
            'icms'=>'ICMS','st'=>'ST','retained'=>'retido','fcp'=>'FCP','difal'=>'DIFAL','ipi'=>'IPI',
            'pis'=>'PIS','cofins'=>'COFINS','ibs'=>'IBS','cbs'=>'CBS','rtc'=>'IBS/CBS','is'=>'Imposto Seletivo',
            'uf'=>'estadual','municipal'=>'municipal','cst'=>'CST','csosn'=>'CSOSN','class'=>'classificação tributária',
            'rate'=>'alíquota','base'=>'base','amount'=>'valor','reduction'=>'redução','deferral'=>'diferimento',
            'desoneration'=>'desoneração','reason'=>'motivo','deduct'=>'deduzir','credit'=>'crédito','mode'=>'modalidade',
            'unit'=>'por unidade','calculation'=>'cálculo','type'=>'tipo','regular'=>'regular',
            'returned'=>'devolvido','return'=>'devolução','percentage'=>'percentual','original'=>'original',
            'add'=>'somar','to'=>'ao','total'=>'total','scope'=>'abrangência','enq'=>'enquadramento legal',
            'internal'=>'interna','interstate'=>'interestadual','share'=>'partilha','mva'=>'MVA',
            'effective'=>'efetiva','previous'=>'anterior','quantity'=>'quantidade','benefit'=>'benefício',
            'substitute'=>'substituto','monophase'=>'monofásico','adrem'=>'por unidade',
            'deferment'=>'diferimento','deduction'=>'dedução','substitutor'=>'substituto','value'=>'valor',
            'legal'=>'legal','source'=>'fonte','reference'=>'referência','transfer'=>'transferência',
            'adjustment'=>'ajuste','period'=>'período','valid'=>'vigente','from'=>'desde','until'=>'até',
        ];
        $tokens = explode('_', $key);
        foreach ($tokens as $token) if (!isset($words[$token])) return null;
        return implode(' ', array_map(static fn($word) => $words[$word], $tokens));
    }
}
