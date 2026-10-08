<?php

namespace App\Support;

/**
 * Catálogos de códigos para seleção assistida.
 * O catálogo NÃO escolhe automaticamente o enquadramento tributário.
 * IBS/CBS (cClassTrib) e ANP são tabelas extensas, com versões oficiais;
 * não incluir listas parciais como se fossem completas.
 */
final class FiscalCodeCatalog
{
    public static function all(): array
    {
        $community = json_decode((string) @file_get_contents(
            resource_path('data/fiscal/cclasstrib-community.json')
        ), true);
        $classifications = is_array($community['codes'] ?? null)
            ? $community['codes'] : [];

        return [
            'ibs_cbs_class'=>$classifications,
            'origin' => [
                '0'=>'Nacional, exceto indicadas nos códigos 3, 4, 5 e 8',
                '1'=>'Estrangeira — importação direta, exceto código 6',
                '2'=>'Estrangeira — adquirida no mercado interno, exceto código 7',
                '3'=>'Nacional — conteúdo de importação superior a 40% e até 70%',
                '4'=>'Nacional — processos produtivos básicos / produção conforme legislação',
                '5'=>'Nacional — conteúdo de importação até 40%',
                '6'=>'Estrangeira — importação direta, sem similar nacional',
                '7'=>'Estrangeira — adquirida no mercado interno, sem similar nacional',
                '8'=>'Nacional — conteúdo de importação superior a 70%',
            ],
            'csosn'=>[
                '101'=>'Tributada pelo Simples Nacional com permissão de crédito',
                '102'=>'Tributada pelo Simples Nacional sem permissão de crédito',
                '103'=>'Isenção do ICMS no Simples Nacional por faixa de receita',
                '201'=>'Com crédito do Simples Nacional e ICMS-ST',
                '202'=>'Sem crédito do Simples Nacional e ICMS-ST',
                '203'=>'Isenção por faixa de receita e ICMS-ST',
                '300'=>'Imune',
                '400'=>'Não tributada pelo Simples Nacional',
                '500'=>'ICMS cobrado anteriormente por substituição tributária / antecipação',
                '900'=>'Outros',
            ],
            'icms_cst'=>[
                '00'=>'Tributada integralmente',
                '02'=>'Tributação monofásica própria sobre combustíveis',
                '10'=>'Tributada com cobrança de ICMS por substituição tributária',
                '15'=>'Tributação monofásica própria e com responsabilidade pela retenção',
                '20'=>'Com redução da base de cálculo',
                '30'=>'Isenta/não tributada com ICMS-ST',
                '40'=>'Isenta',
                '41'=>'Não tributada',
                '50'=>'Suspensão',
                '51'=>'Diferimento',
                '53'=>'Tributação monofásica de combustíveis com diferimento',
                '60'=>'ICMS cobrado anteriormente por substituição tributária',
                '61'=>'Tributação monofásica de combustíveis cobrada anteriormente',
                '70'=>'Redução da base de cálculo com ICMS-ST',
                '90'=>'Outras',
            ],
            'pis_cofins_cst'=>[
                '01'=>'Operação tributável com alíquota básica',
                '02'=>'Operação tributável com alíquota diferenciada',
                '03'=>'Operação tributável com alíquota por unidade de medida',
                '04'=>'Operação tributável monofásica — revenda a alíquota zero',
                '05'=>'Operação tributável por substituição tributária',
                '06'=>'Operação tributável a alíquota zero',
                '07'=>'Operação isenta da contribuição',
                '08'=>'Operação sem incidência da contribuição',
                '09'=>'Operação com suspensão da contribuição',
                '49'=>'Outras operações de saída',
                '50'=>'Aquisição com direito a crédito, vinculada a receitas tributadas no mercado interno',
                '51'=>'Aquisição com direito a crédito, vinculada a receitas não tributadas no mercado interno',
                '52'=>'Aquisição com direito a crédito, vinculada a receitas de exportação',
                '53'=>'Aquisição com direito a crédito, receitas tributadas e não tributadas no mercado interno',
                '54'=>'Aquisição com direito a crédito, receitas tributadas no mercado interno e exportação',
                '55'=>'Aquisição com direito a crédito, receitas não tributadas no mercado interno e exportação',
                '56'=>'Aquisição com direito a crédito, receitas tributadas/não tributadas e exportação',
                '60'=>'Crédito presumido, vinculado a receitas tributadas no mercado interno',
                '61'=>'Crédito presumido, vinculado a receitas não tributadas no mercado interno',
                '62'=>'Crédito presumido, vinculado a receitas de exportação',
                '63'=>'Crédito presumido, receitas tributadas e não tributadas no mercado interno',
                '64'=>'Crédito presumido, receitas tributadas no mercado interno e exportação',
                '65'=>'Crédito presumido, receitas não tributadas no mercado interno e exportação',
                '66'=>'Crédito presumido, receitas tributadas/não tributadas e exportação',
                '67'=>'Crédito presumido — outras operações',
                '70'=>'Aquisição sem direito a crédito',
                '71'=>'Aquisição com isenção',
                '72'=>'Aquisição com suspensão',
                '73'=>'Aquisição a alíquota zero',
                '74'=>'Aquisição sem incidência',
                '75'=>'Aquisição por substituição tributária',
                '98'=>'Outras operações de entrada',
                '99'=>'Outras operações',
            ],
            'ipi_cst'=>[
                '00'=>'Entrada com recuperação de crédito',
                '01'=>'Entrada tributada com alíquota zero',
                '02'=>'Entrada isenta',
                '03'=>'Entrada não tributada',
                '04'=>'Entrada imune',
                '05'=>'Entrada com suspensão',
                '49'=>'Outras entradas',
                '50'=>'Saída tributada',
                '51'=>'Saída tributada com alíquota zero',
                '52'=>'Saída isenta',
                '53'=>'Saída não tributada',
                '54'=>'Saída imune',
                '55'=>'Saída com suspensão',
                '99'=>'Outras saídas',
            ],
            'crt'=>[
                '1'=>'Simples Nacional',
                '2'=>'Simples Nacional — excesso de sublimite',
                '3'=>'Regime normal',
                '4'=>'Simples Nacional — MEI (quando aplicável)',
            ],
            'icms_mod_bc'=>[
                '0'=>'Margem Valor Agregado (%)',
                '1'=>'Pauta (valor)',
                '2'=>'Preço tabelado máximo (valor)',
                '3'=>'Valor da operação',
            ],
            'icms_mod_bc_st'=>[
                '0'=>'Preço tabelado ou máximo sugerido',
                '1'=>'Lista negativa (valor)',
                '2'=>'Lista positiva (valor)',
                '3'=>'Lista neutra (valor)',
                '4'=>'Margem Valor Agregado (%)',
                '5'=>'Pauta (valor)',
                '6'=>'Valor da operação',
            ],
            'iss_exigibility'=>[
                '1'=>'Exigível',
                '2'=>'Não incidência',
                '3'=>'Isenção',
                '4'=>'Exportação',
                '5'=>'Imunidade',
                '6'=>'Exigibilidade suspensa por decisão judicial',
                '7'=>'Exigibilidade suspensa por processo administrativo',
            ],
            'cfop'=>[
                '1101'=>'Compra para industrialização',
                '1102'=>'Compra para comercialização',
                '1202'=>'Devolução de venda de mercadoria adquirida/recebida de terceiros',
                '1403'=>'Compra para comercialização em operação com ICMS-ST',
                '1551'=>'Compra de bem para ativo imobilizado',
                '1910'=>'Entrada de bonificação, doação ou brinde',
                '2101'=>'Compra para industrialização de outro estado',
                '2102'=>'Compra para comercialização de outro estado',
                '2202'=>'Devolução de venda de mercadoria de terceiros, interestadual',
                '2403'=>'Compra para comercialização com ICMS-ST, interestadual',
                '2551'=>'Compra de bem para ativo imobilizado, interestadual',
                '5101'=>'Venda de produção do estabelecimento',
                '5102'=>'Venda de mercadoria adquirida ou recebida de terceiros',
                '5104'=>'Venda fora do estabelecimento, mercadoria de terceiros',
                '5202'=>'Devolução de compra para comercialização',
                '5401'=>'Venda de produção com ICMS-ST como contribuinte substituto',
                '5403'=>'Venda de mercadoria de terceiros com ICMS-ST como substituto',
                '5405'=>'Venda de mercadoria de terceiros com ICMS-ST retido anteriormente',
                '5551'=>'Venda de bem do ativo imobilizado',
                '5910'=>'Remessa em bonificação, doação ou brinde',
                '5915'=>'Remessa para conserto ou reparo',
                '5922'=>'Simples faturamento decorrente de venda para entrega futura',
                '5949'=>'Outra saída não especificada',
                '6101'=>'Venda interestadual de produção do estabelecimento',
                '6102'=>'Venda interestadual de mercadoria de terceiros',
                '6202'=>'Devolução de compra para comercialização, interestadual',
                '6403'=>'Venda interestadual de mercadoria de terceiros com ICMS-ST como substituto',
                '6404'=>'Venda interestadual de mercadoria com ICMS-ST retido anteriormente',
                '6551'=>'Venda interestadual de bem do ativo imobilizado',
                '6910'=>'Remessa interestadual em bonificação, doação ou brinde',
                '6915'=>'Remessa interestadual para conserto ou reparo',
                '6949'=>'Outra saída interestadual não especificada',
                '7101'=>'Venda de produção do estabelecimento ao exterior',
                '7102'=>'Venda de mercadoria de terceiros ao exterior',
            ],
            'cfop_pattern'=>[
                'x101'=>'Venda de produção própria — prefixo conforme destino',
                'x102'=>'Venda de mercadoria de terceiros — prefixo conforme destino',
                'x405'=>'Venda de mercadoria de terceiros com ICMS-ST anterior — conforme destino',
                'x910'=>'Remessa em bonificação, doação ou brinde — conforme destino',
                'x915'=>'Remessa para conserto ou reparo — conforme destino',
                'x949'=>'Outras saídas — conforme destino',
            ],
            'ibs_cbs_cst'=>[
                '000'=>'Tributação integral',
                '010'=>'Tributação com alíquotas uniformes',
                '011'=>'Tributação com alíquotas uniformes reduzidas',
                '200'=>'Alíquota reduzida',
                '220'=>'Alíquota fixa',
                '221'=>'Alíquota fixa proporcional',
                '222'=>'Redução de base de cálculo',
                '400'=>'Isenção',
                '410'=>'Imunidade e não incidência',
                '510'=>'Diferimento',
                '515'=>'Diferimento com redução de alíquota',
                '550'=>'Suspensão',
                '620'=>'Tributação monofásica',
                '800'=>'Transferência de crédito',
                '810'=>'Ajuste do IBS na ZFM',
                '811'=>'Ajustes',
                '820'=>'Tributação em documento específico',
                '830'=>'Exclusão da base de cálculo',
            ],
        ];
    }

    public static function forField(string $name): ?string
    {
        $field=preg_replace('/^.*\[([^]]+)\]$/','$1',$name);
        $field=preg_replace('/_default$/','',$field);
        if (in_array($field,['origin','icms_origin'],true)) return 'origin';
        if ($field==='crt') return 'crt';
        if (in_array($field,['ibs_cbs_class','tax_classification_code','cclasstrib'],true)) return 'ibs_cbs_class';
        if (str_contains($field,'csosn')) return 'csosn';
        if (preg_match('/^(?:icms_cst)(?:_|$)/',$field)) return 'icms_cst';
        if (preg_match('/^(?:pis_cst|cofins_cst)(?:_|$)/',$field)) return 'pis_cofins_cst';
        if (preg_match('/^ipi_cst(?:_|$)/',$field)) return 'ipi_cst';
        if (preg_match('/^(?:ibs_cbs_cst|ibs_cst|cbs_cst)(?:_|$)/',$field)) return 'ibs_cbs_cst';
        if ($field==='mod_bc') return 'icms_mod_bc';
        if ($field==='mod_bc_st') return 'icms_mod_bc_st';
        if ($field==='iss_exigibility') return 'iss_exigibility';
        if ($field==='cfop_pattern') return 'cfop_pattern';
        if ($field==='cfop'||$field==='default_cfop'||str_starts_with($field,'cfop_')
            ||str_starts_with($field,'nfce_cfop')) return 'cfop';
        return null;
    }
}
