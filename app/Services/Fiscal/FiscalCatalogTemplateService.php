<?php

namespace App\Services\Fiscal;

use App\Models\FiscalTaxGroup;
use App\Models\OperationNature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Factory configuration proposals. They never establish the taxpayer's legal classification. */
final class FiscalCatalogTemplateService
{
    public function install(string $version): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $version)) {
            throw new InvalidArgumentException('Versão do catálogo inválida.');
        }
        return DB::transaction(function () use ($version): array {
            return [
                'natures' => $this->persist(OperationNature::class, $this->natures(), $version),
                'groups' => $this->persist(FiscalTaxGroup::class, $this->groups(), $version),
            ];
        });
    }

    public function natures(): array
    {
        $rows = [];
        $sale = ['fiscal_operation'=>['sale'], 'destination'=>['internal','interstate'], 'operation_type'=>['outbound'], 'purpose'=>['normal']];
        $rows['nfe55_resale_contributor'] = $this->nature('Venda de mercadoria de terceiros — contribuinte', '5102', '6102', 'outbound', 'normal', $sale + ['recipient_profile'=>['contributor','exempt'], 'product_usage_type'=>['resale']],
            'Mercadoria adquirida ou recebida de terceiros, sem transformação. O modelo não cobre ICMS-ST, combustíveis, venda à ordem, entrega futura ou outras hipóteses com CFOP próprio. Destinatário contribuinte, inclusive isento de inscrição estadual (que não equivale a isenção do imposto); revisar o grupo tributário por produto.');
        $rows['nfe55_resale_non_contributor'] = $this->nature('Venda de mercadoria de terceiros — não contribuinte', '5102', '6108', 'outbound', 'normal', $sale + ['recipient_profile'=>['non_contributor'], 'product_usage_type'=>['resale']],
            'CFOP 6108 para mercadoria de terceiros destinada a não contribuinte do ICMS. Consumidor final não significa automaticamente não contribuinte. Verificar ICMS-ST, DIFAL, FCP e exceções do regime antes de aprovar a regra.');
        $rows['nfe55_own_production_contributor'] = $this->nature('Venda de produção própria — contribuinte', '5101', '6101', 'outbound', 'normal', $sale + ['recipient_profile'=>['contributor','exempt']],
            'Somente produto industrializado ou produzido pelo estabelecimento. Não usar para revenda. A origem produtiva deve ser confirmada expressamente na regra, pois o cadastro atual não comprova industrialização. Exclui operações que exijam CFOP específico.');
        $rows['nfe55_own_production_non_contributor'] = $this->nature('Venda de produção própria — não contribuinte', '5101', '6107', 'outbound', 'normal', $sale + ['recipient_profile'=>['non_contributor']],
            'Somente produção do estabelecimento. CFOP 6107 para destinatário não contribuinte do ICMS. Verificar ICMS-ST, DIFAL, FCP e exceções do regime; consumidor final, por si só, não determina o CFOP.');
        $rows['nfe55_resale_final_consumer'] = $this->nature('Venda a consumidor final não contribuinte — revenda', '5102', '6108', 'outbound', 'normal', $sale + ['recipient_profile'=>['non_contributor'], 'final_consumer'=>[true], 'product_usage_type'=>['resale']],
            'Modelo restrito a consumidor final não contribuinte, sem hipótese especial de CFOP. Não presume DIFAL devido pelo Simples. A regra específica deve demonstrar incidência ou não incidência, UF e vigência.');
        $rows['nfe55_resale_final_consumer']['final_consumer_default'] = true;

        foreach ([
            ['purchase_resale','Compra para comercialização','1102','2102','inbound','normal','Aquisição destinada à revenda, sem industrialização no estabelecimento. Não usar em compra com ICMS-ST que exija CFOP 1403/2403 ou em hipótese específica. Documento próprio de entrada somente nas hipóteses legais; não substituir a NF-e do fornecedor.'],
            ['purchase_industrialization','Compra para industrialização','1101','2101','inbound','normal','Aquisição destinada à industrialização no estabelecimento. Não usar para consumo, ativo, revenda ou operações sujeitas a CFOP específico. NF-e própria de entrada depende de hipótese legal expressa.'],
            ['return_sale_resale','Devolução de venda de mercadoria de terceiros','1202','2202','inbound','return','Entrada que anula venda anterior de mercadoria de terceiros. Referenciar o documento original e limitar quantidades e valores devolvidos. Reproduzir o tratamento tributário aplicável à operação original, inclusive ST/IPI quando exigidos.'],
            ['return_sale_production','Devolução de venda de produção própria','1201','2201','inbound','return','Entrada em devolução de venda de produção do estabelecimento. Referenciar a venda original. Não usar em devolução com CFOP específico de ICMS-ST. Confirmar hipótese legal de emissão de documento de entrada.'],
            ['return_purchase_resale','Devolução de compra para comercialização','5202','6202','outbound','return','Saída em devolução de compra destinada à revenda. Referenciar a compra original, observar motivo, quantidades, imposto devolvido e regras específicas de ICMS-ST/IPI.'],
            ['return_purchase_industrialization','Devolução de compra para industrialização','5201','6201','outbound','return','Saída em devolução de compra para industrialização. Referenciar o documento original e verificar regra específica quando mercadoria sujeita à substituição tributária.'],
            ['repair_send_outbound','Remessa para conserto ou reparo','5915','6915','outbound','normal','Remessa de mercadoria ou bem para conserto/reparo. Não aplicar suspensão, isenção ou não incidência sem fundamento específico da UF e prazo de retorno. Sem venda nem receita presumida.'],
            ['repair_send_inbound','Entrada de remessa para conserto ou reparo','1915','2915','inbound','normal','Recebimento de mercadoria/bem de terceiro para conserto/reparo. Não constitui aquisição para revenda. Documento próprio de entrada somente quando legalmente cabível.'],
            ['repair_return_outbound','Retorno de mercadoria recebida para conserto','5916','6916','outbound','normal','Retorno do mesmo bem recebido para conserto/reparo; referenciar a remessa. Peças aplicadas e serviços podem exigir documentos e tributação distintos. Não presumir suspensão ou dispensa tributária.'],
            ['repair_return_inbound','Retorno de mercadoria remetida para conserto','1916','2916','inbound','normal','Entrada do mesmo bem anteriormente remetido para conserto/reparo. Referenciar a remessa; não classificar como compra. Examinar peças, serviços e prazo de retorno separadamente.'],
            ['bonus_outbound','Remessa em bonificação','5910','6910','outbound','normal','Saída em bonificação efetiva. CFOP também abrange doação/brinde, mas isso não iguala o tratamento tributário. Ausência de cobrança não implica isenção de ICMS, PIS/COFINS, IPI ou IBS/CBS. Base e incidência dependem da regra específica.'],
        ] as [$key,$name,$internal,$interstate,$direction,$purpose,$conditions]) {
            $operation = str_starts_with($key, 'return_') ? 'devolution'
                : (str_contains($key, 'repair_return') ? 'return_of_remittance'
                    : (str_contains($key, 'repair_send') ? 'remittance' : ($key==='bonus_outbound' ? 'bonus' : null)));
            $required = ['destination'=>['internal','interstate'], 'operation_type'=>[$direction], 'purpose'=>[$purpose]];
            if ($operation) $required['fiscal_operation'] = [$operation];
            $rows['nfe55_'.$key] = $this->nature($name, $internal, $interstate, $direction, $purpose, $required, $conditions);
            if (str_contains($key,'repair_') || $key==='bonus_outbound') {
                $rows['nfe55_'.$key]['generate_finance'] = false;
            }
        }
        return $rows;
    }

    public function groups(): array
    {
        $proposal = 'Modelo indisponível para aplicação automática até revisão expressa e regra fiscal aprovada. O nome não classifica produtos; NCM, descrição, UF, regime, vigência e hipótese legal precisam ser verificados.';
        $definitions = [
            'sn_resale' => ['Simples | Revenda sem ST — modelo NF-e','1','102',null,null,null,
                'CSOSN 102 somente se a operação for tributada pelo Simples sem permissão de crédito e não estiver enquadrada em outro CSOSN. Não presumir classificação de PIS/COFINS.'],
            'sn_st_previous' => ['Simples | ICMS-ST anterior — modelo NF-e','1','500',null,null,null,
                'CSOSN 500 depende de ICMS cobrado anteriormente por substituição tributária ou antecipação. CEST não comprova incidência. Valores de retenção devem vir do documento/lote real, nunca de zero padrão.'],
            'sn_st_substitute' => ['Simples | Substituto tributário — modelo NF-e','1',null,null,null,null,
                'Selecionar CSOSN 201, 202 ou 203 conforme crédito, faixa de isenção e operação efetiva. Definir convênio/protocolo, UF, responsabilidade, base, MVA/pauta e alíquotas comprovadas. Nenhum desses valores é pré-preenchido.'],
            'pis_cofins_monophase' => ['PIS/COFINS | Revenda monofásica — modelo NF-e','1',null,null,'04','04',
                'CST 04 somente para revenda de produto efetivamente sujeito à concentração monofásica, conforme descrição/NCM e legislação específica. Não equivale a alíquota zero genérica e não determina o ICMS.'],
            'specific_treatment' => ['Tratamento fiscal específico — modelo NF-e',null,null,null,null,null,
                'Configurar todos os tributos e fundamento particular. Nenhum benefício, alíquota, redução ou suspensão é presumido.'],
            'sn_immunity' => ['Simples | Imunidade — modelo NF-e','1','300',null,null,null,
                'CSOSN 300 somente quando houver imunidade de ICMS efetivamente aplicável. Verificar fundamento constitucional e legislação; não presume imunidade dos demais tributos.'],
            'sn_non_taxed' => ['Simples | Não tributada — modelo NF-e','1','400',null,null,null,
                'CSOSN 400 somente em operação não tributada pelo Simples Nacional. Não tratar ausência de parâmetro como não incidência.'],
            'normal_exempt' => ['Regime normal | Isenção de ICMS — modelo NF-e','3',null,'40',null,null,
                'CST 40 exige hipótese legal de isenção, UF, vigência e requisitos próprios; verificar cBenef e desoneração. Não presume isenção de IPI, PIS/COFINS, IBS/CBS.'],
            'return' => ['Devoluções | Reproduzir operação original — modelo NF-e',null,null,null,null,null,
                'CFOP e tributos dependem da natureza da compra/venda original, mercadoria, regime e quantidade devolvida. Nenhum CST genérico é aplicado; documentos referenciados são indispensáveis.'],
            'interstate' => ['Interestadual | Contexto da operação — modelo NF-e',null,null,null,null,null,
                'CFOP, ICMS-ST, DIFAL e FCP dependem de origem/destino, destinatário e finalidade. Consumidor final não significa não contribuinte. DIFAL não é automaticamente exigido de optante do Simples.'],
            'normal_integral' => ['Regime normal | ICMS integral — modelo NF-e','3',null,'00',null,null,
                'CST 00 somente para tributação integral. Configurar base, modalidade, alíquota, FCP e contribuições aplicáveis. Alíquotas não são fornecidas pelo modelo.'],
            'sublimit' => ['Excesso de sublimite | ICMS normal — modelo NF-e','2',null,null,null,null,
                'CRT 2 utiliza CST do regime normal para ICMS; isso não altera automaticamente o regime de PIS/COFINS. Configurar enquadramento comprovado por tributo.'],
            'rtc' => ['IBS/CBS | Enquadramento da reforma — modelo NF-e',null,null,null,null,null,
                'Escolher CST e cClassTrib oficiais, vigência e indicadores do leiaute aplicável. Não presumir exigibilidade universal, alíquotas, créditos ou redução. O catálogo classificatório não substitui a legislação da operação.'],
        ];
        $rows=[];
        foreach ($definitions as $key => [$name,$crt,$csosn,$cst,$pis,$cofins,$conditions]) {
            $rows['nfe55_template_'.$key] = [
                'name'=>$name, 'kind'=>'products', 'target_crt'=>$crt, 'cfop_pattern'=>null,
                'nfce_csosn'=>null, 'icms_csosn'=>$csosn, 'icms_cst'=>$cst,
                'pis_cst'=>$pis, 'cofins_cst'=>$cofins, 'ipi_cst'=>null, 'iss_exigibility'=>null,
                'tax_config'=>[], 'is_active'=>false, 'is_default'=>false,
                'notes'=>$proposal.' '.$conditions,
                'catalog_metadata'=>$this->metadata($conditions, []),
            ];
        }
        return $rows;
    }

    private function nature(string $name, string $internal, string $interstate, string $direction, string $purpose, array $required, string $conditions): array
    {
        return [
            'name'=>$name, 'operation_type'=>$direction, 'purpose'=>$purpose,
            'cfop_internal'=>$direction==='outbound' ? $internal : null,
            'cfop_interstate'=>$direction==='outbound' ? $interstate : null,
            'cfop_inbound_internal'=>$direction==='inbound' ? $internal : null,
            'cfop_inbound_interstate'=>$direction==='inbound' ? $interstate : null,
            'cfop_foreign'=>null, 'override_product_cfop'=>true,
            'final_consumer_default'=>false, 'presence_default'=>'not_applicable',
            'move_stock'=>true, 'generate_finance'=>true, 'allow_referenced_document'=>true,
            'require_transport'=>false, 'require_invoice'=>false, 'require_duplicates'=>false,
            'additional_info'=>null, 'tax_authority_info'=>null, 'is_active'=>false,
            'catalog_metadata'=>$this->metadata($conditions,$required),
        ];
    }

    private function metadata(string $conditions, array $required): array
    {
        return [
            'type'=>'configuration_template', 'automatic_application'=>false,
            'consulted_at'=>'2026-10-26', 'valid_from'=>null, 'valid_until'=>null,
            'uf'=>null, 'regime'=>'Conforme CRT e regra específica aprovada',
            'required_context'=>$required, 'conditions'=>$conditions,
            'limitations'=>'Definições de CFOP são referência classificatória; não comprovam enquadramento tributário, vigência de benefício ou direito à emissão própria de entrada.',
            'sources'=>[
                ['reference'=>'Convênio SINIEF s/n de 15/12/1970, Anexo II; tabela nacional vigente publicada pelo Portal NF-e em 04/09/2026',
                    'url'=>'https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM=',
                    'version'=>'IT_2023_002_v2_10_Tabela_CFOP_Descrição.xlsx'],
                ['reference'=>'Ajuste SINIEF 03/2010 — CRT e CSOSN; LC 123/2006 — Simples Nacional',
                    'url'=>'https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp123.htm',
                    'version'=>'Texto consolidado; enquadramento individual sujeito a revisão'],
            ],
        ];
    }

    private function persist(string $class, array $definitions, string $version): array
    {
        $counts=['inserted'=>0,'updated'=>0,'preserved'=>0];
        foreach ($definitions as $key=>$definition) {
            $data=$definition + ['catalog_version'=>$version,'catalog_status'=>'template'];
            /** @var Model|null $row */
            $row=$class::query()->where('preset_key',$key)->lockForUpdate()->first();
            if ($row) {
                if ($row->catalog_status !== 'template' || $row->is_active
                    || $row->catalog_fingerprint !== self::fingerprint($row->only(array_keys($data)))) {
                    ++$counts['preserved'];
                    continue;
                }
                if (self::fingerprint($data) === $row->catalog_fingerprint) {
                    ++$counts['preserved'];
                    continue;
                }
                $data['revision']=$row->revision+1;
                $row->fill($data);
            } else {
                $row=new $class($data + ['preset_key'=>$key,'revision'=>1]);
                ++$counts['inserted'];
            }
            $row->catalog_fingerprint=self::fingerprint($row->only(array_keys($definition + ['catalog_version'=>null,'catalog_status'=>null])));
            $row->save();
            $row->revisions()->create(['revision'=>$row->revision,'user_id'=>null,
                'snapshot'=>$row->attributesToArray(),'created_at'=>now()]);
            if ($row->wasRecentlyCreated === false) ++$counts['updated'];
        }
        return $counts;
    }

    public static function fingerprint(array $data): string
    {
        $sort=function (array $value) use (&$sort): array {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$part) if (is_array($part)) $part=$sort($part);
            return $value;
        };
        return hash('sha256', json_encode($sort($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }
}
