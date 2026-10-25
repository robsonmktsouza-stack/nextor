<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use RuntimeException;

/**
 * NF-e model 55: operation policy, explicit tax calculations and ACBr INI serialization.
 * Unsupported fiscal groups are rejected before any external transmission.
 */
final class Nfe55IniBuilder
{
    private const UF=[
        'RO'=>'11','AC'=>'12','AM'=>'13','RR'=>'14','PA'=>'15','AP'=>'16','TO'=>'17',
        'MA'=>'21','PI'=>'22','CE'=>'23','RN'=>'24','PB'=>'25','PE'=>'26','AL'=>'27',
        'SE'=>'28','BA'=>'29','MG'=>'31','ES'=>'32','RJ'=>'33','SP'=>'35',
        'PR'=>'41','SC'=>'42','RS'=>'43','MS'=>'50','MT'=>'51','GO'=>'52','DF'=>'53',
    ];
    public function __construct(private readonly Nfe55RtcTaxAdapter $rtc) {}

    public function build(NfeDraft $draft,CompanySetting $company,int $number,int $numericCode): string
    {
        $draft->loadMissing(['items','operationNature']);
        app(Nfe55TaxRuleApplicationService::class)->assertCurrent($draft,$company);
        $operation=app(Nfe55OperationPolicy::class)->validate($draft,$company);
        $foreignTrade=app(Nfe55ForeignTradeService::class)->validate($draft,$company);
        if(!in_array((string)$company->crt,['1','2','3','4'],true)){
            throw new RuntimeException('CRT do emitente inválido.');
        }
        if($draft->government_purchase||$draft->advance_payment
            ||$draft->items->isEmpty()||$draft->items->count()>80) {
            throw new RuntimeException('Operação especial ou quantidade de itens ainda não homologada.');
        }
        if($draft->has_referenced_document && !$draft->references) {
            throw new RuntimeException('O documento referenciado não foi informado.');
        }
        $uf=strtoupper((string)$company->state);
        if(!isset(self::UF[$uf]))throw new RuntimeException('UF do emitente inválida.');
        $cnpj=$this->digits($company->document);
        if(strlen($cnpj)!==14 || strlen($this->digits($company->city_ibge_code))!==7
            ||strlen($this->digits($company->zip_code))!==8
            ||!$company->legal_name || !$company->address || !$company->address_number || !$company->district
            ||!$company->city || !$company->state_registration) {
            throw new RuntimeException('Cadastro do emitente incompleto para NF-e.');
        }
        $recipient=$draft->recipient_snapshot??[];
        $doc=$this->digits($recipient['document']??'');
        $isForeign=$foreignTrade['is_foreign'];
        if(!($recipient['name']??'') || !($recipient['address']??'')
            || !($recipient['address_number']??'') || !($recipient['district']??'')
            || !($recipient['city']??'')){
            throw new RuntimeException('Nome e endereço do destinatário incompletos para NF-e.');
        }
        if(!$isForeign && (!in_array(strlen($doc),[11,14],true)
            ||!isset(self::UF[strtoupper((string)($recipient['state']??''))])
            ||strlen($this->digits($recipient['city_ibge_code']??''))!==7
            ||strlen($this->digits($recipient['zip_code']??''))!==8)) {
            throw new RuntimeException('Destinatário nacional incompleto para NF-e.');
        }
        if($number<1||$number>999999999||$numericCode<1||$numericCode>99999999
            ||$draft->series===null||(int)$draft->series<0||(int)$draft->series>999) {
            throw new RuntimeException('Série, número ou código numérico da NF-e inválidos.');
        }
        $sections=[];
        $add=static function(string $name,array $fields)use(&$sections): void {
            $output=["[{$name}]"];
            foreach($fields as $key=>$value){
                if($value===null || (string)$value==='')continue;
                $text=trim(str_replace(["\r","\n",'=','[',']'], ' ',(string)$value));
                $output[]=$key.'='.$text;
            }
            $sections[]=implode("\r\n",$output);
        };
        $env=$draft->environment==='production'?'1':'2';
        $add('infNFe',['versao'=>'4.00']);
        $add('Identificacao',[
            'cUF'=>self::UF[$uf],'cNF'=>sprintf('%08d',$numericCode),
            'natOp'=>$draft->operationNature?->name,
            'Modelo'=>'55','serie'=>$draft->series,'nNF'=>$number,
            'dhEmi'=>$draft->issue_date->format('d/m/Y').' '.substr((string)$draft->issue_time,0,5).':00',
            'tpNF'=>$operation['tpNF'],'idDest'=>$operation['idDest'],'tpAmb'=>$env,'tpImp'=>'1','tpEmis'=>'1',
            'finNFe'=>$operation['finNFe'],'indFinal'=>$draft->final_consumer?'1':'0',
            'indPres'=>match($draft->presence){'presential'=>'1','internet'=>'2','phone'=>'3',
                'delivery_home'=>'4','outside_establishment'=>'5','other'=>'9',default=>'0'},
            'procEmi'=>'0','verProc'=>'LUMERON 1.0','cMunFG'=>$this->digits($company->city_ibge_code),
        ]);
        $add('Emitente',[
            'CRT'=>$company->crt,'CNPJCPF'=>$cnpj,'xNome'=>$company->legal_name,'xFant'=>$company->trade_name,
            'IE'=>$this->digits($company->state_registration),'IM'=>$this->digits($company->municipal_registration),
            'CNAE'=>$this->digits($company->cnae_main),
            'xLgr'=>$company->address,'nro'=>$company->address_number,'xCpl'=>$company->address_complement,
            'xBairro'=>$company->district,'cMun'=>$this->digits($company->city_ibge_code),'xMun'=>$company->city,
            'cUF'=>self::UF[$uf],'UF'=>$uf,'CEP'=>$this->digits($company->zip_code),
            'cPais'=>'1058','xPais'=>'BRASIL','Fone'=>$this->digits($company->phone),
        ]);
        $add('Destinatario',[
            'CNPJCPF'=>$isForeign?null:$doc,'idEstrangeiro'=>$isForeign?$recipient['foreign_id']:null,
            'xNome'=>$env==='2'?'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL':$recipient['name'],
            'indIEDest'=>$isForeign?'9':match($recipient['ie_indicator']??'non_contributor'){
                'contributor'=>'1','exempt'=>'2',default=>'9'},
            'IE'=>!$isForeign && ($recipient['ie_indicator']??'non_contributor')==='contributor'
                ? $this->digits($recipient['state_registration']??'') : null,
            'xLgr'=>$recipient['address'],'nro'=>$recipient['address_number'],
            'xCpl'=>$recipient['address_complement']??null,'xBairro'=>$recipient['district'],
            'cMun'=>$isForeign?'9999999':$this->digits($recipient['city_ibge_code']),'xMun'=>$isForeign?'EXTERIOR':$recipient['city'],
            'UF'=>$isForeign?'EX':strtoupper((string)$recipient['state']),'CEP'=>$isForeign?null:$this->digits($recipient['zip_code']),
            'cPais'=>$isForeign?$recipient['country_code']:'1058','xPais'=>$isForeign?$recipient['country_name']:'BRASIL','Fone'=>$this->digits($recipient['phone']??null),
            'email'=>$recipient['email']??null,
        ]);
        if($draft->different_delivery){
            $delivery=$draft->delivery_snapshot??[];
            if(!$delivery || strlen($this->digits($delivery['zip_code']??''))!==8
                || strlen($this->digits($delivery['city_ibge_code']??''))!==7
                || !($delivery['address']??null)||!($delivery['address_number']??null)
                || !($delivery['district']??null)||!($delivery['city']??null)
                || !isset(self::UF[strtoupper((string)($delivery['state']??''))])
                || !in_array((string)($delivery['country_code']??'1058'),['','1058'],true)){
                throw new RuntimeException('Local de entrega incompleto.');
            }
            $deliveryDoc=$this->digits($delivery['document']??'');
            $add('Entrega',[
                'CNPJCPF'=>in_array(strlen($deliveryDoc),[11,14],true)?$deliveryDoc:null,
                'xNome'=>$delivery['name']??null,
                'xLgr'=>$delivery['address'],'nro'=>$delivery['address_number'],
                'xCpl'=>$delivery['address_complement']??null,
                'xBairro'=>$delivery['district'],
                'cMun'=>$this->digits($delivery['city_ibge_code']),
                'xMun'=>$delivery['city'],'UF'=>strtoupper($delivery['state']),
                'CEP'=>$this->digits($delivery['zip_code']),
                'PaisCod'=>'1058','Pais'=>'BRASIL',
            ]);
        }
        // Allocate document-level amounts to the items before emitting the INI.
        // Values are kept in integer cents so the XML item sums always match vNF.
        $items=$draft->items->values();
        $grossWeights=$items->map(fn($item)=>NfeTaxMath::line($item->quantity,$item->dimension_quantity,$item->unit_price)['gross_cents'])->all();
        $globalFreight=$this->cents(data_get($draft->transport_data,'freight_value',0));
        $globalSurcharge=$this->cents($draft->surcharge);
        $globalDiscount=$this->cents($draft->discount);
        if($globalFreight<0||$globalSurcharge<0||$globalDiscount<0){
            throw new RuntimeException('Frete, desconto ou acréscimo geral inválido.');
        }
        $freightShares=$this->proportionalShares($globalFreight,$grossWeights);
        $otherShares=$this->proportionalShares($globalSurcharge,$grossWeights);
        $discountWeights=[];
        foreach($items as $index=>$item){
            $available=$grossWeights[$index]+$freightShares[$index]+$otherShares[$index]
                +$this->cents($item->freight)+$this->cents($item->insurance)
                +$this->cents($item->other_expenses)-$this->cents($item->discount);
            if($available<0){
                throw new RuntimeException('O desconto de um item supera seu valor.');
            }
            $discountWeights[]=$available;
        }
        if($globalDiscount>array_sum($discountWeights)){
            throw new RuntimeException('O desconto geral supera o valor da NF-e.');
        }
        $discountShares=$this->proportionalShares($globalDiscount,$discountWeights);

        $totalProduct=0;$totalPis=0;$totalCofins=0;$totalIpi=0;
        $totalFederalStAddition=0;$totalReturnedIpi=0;
        $totalIcmsBase=0;$totalIcms=0;$totalStBase=0;$totalSt=0;$totalFcp=0;$totalFcpSt=0;
        $totalDesonerated=0;$totalDesonerationDeduction=0;$totalRetainedFcp=0;
        $totalDestinationIcms=0;$totalOriginIcms=0;$totalDestinationFcp=0;
        $totalFreight=0;$totalInsurance=0;$totalOther=0;$totalDiscount=0;
        $rtcTotal=array_fill_keys(['bc','uf','mun','cbs','dif_uf','dif_mun','dif_cbs','dev_uf','dev_mun','dev_cbs','presumed_ibs','presumed_cbs','presumed_conditional_ibs','presumed_conditional_cbs'],0);$rtcCount=0;$totalSelective=0;$selectiveCount=0;
        foreach($items as $index=>$item) {
            $tax=Nfe55TaxParameters::forDirection($item->tax_data??[],(string)$draft->operation_type);
            Nfe55TaxParameters::assertSupported($tax);
            // Normalize legacy editor keys into the RTC engine's canonical fields.
            $ibsCst=trim((string)($tax['ibs_cst']??''))?:trim((string)($tax['ibs_cbs_cst']??''));
            $cbsCst=trim((string)($tax['cbs_cst']??''))?:trim((string)($tax['ibs_cbs_cst']??''));
            $classification=trim((string)($tax['tax_classification_code']??''))?:trim((string)($tax['ibs_cbs_class']??''));
            if($ibsCst!==''||$cbsCst!==''||$classification!==''){
                if($ibsCst===''||$cbsCst===''||$ibsCst!==$cbsCst){
                    throw new RuntimeException('CST de IBS e CBS devem corresponder à classificação informada.');
                }
                $tax['ibs_cbs_cst']=$ibsCst;
                $tax['ibs_cbs_class']=$classification;
            }
            $line=NfeTaxMath::line($item->quantity,$item->dimension_quantity,$item->unit_price);
            $qty=$line['quantity_units']/10000;$grossCents=$line['gross_cents'];$gross=$grossCents/100;
            $cfop=(string)$item->cfop;
            if($qty<=0 || $gross<=0 || !preg_match('/^'.$operation['cfop_prefix'].'\d{3}$/',$cfop)
                ||!preg_match('/^\d{8}$/',$this->digits($item->ncm))
                ||!preg_match('/^[0-8]$/',(string)$item->origin)) {
                throw new RuntimeException('Confira quantidade, NCM, CFOP e origem dos produtos da NF-e.');
            }
            $special=$item->special_data??[];
            if($isForeign && $foreignTrade['kind']==='import')unset($special['import_declarations'],$special['import_taxes']);
            if($this->hasFiscalValue($special)) {
                throw new RuntimeException('Este produto exige grupo fiscal específico ainda não homologado.');
            }
            if(trim((string)($tax['ipi_cst']??''))===''){
                throw new RuntimeException('Informe o CST do IPI para cada item.');
            }
            if(isset($tax['iss_rate']) && $tax['iss_rate']!=='' && NfeTaxMath::rate($tax['iss_rate'],'ISS')!==0){
                throw new RuntimeException('Serviço sujeito ao ISS exige grupo fiscal específico.');
            }
            $freightCents=$this->cents($item->freight)+$freightShares[$index];
            $insuranceCents=$this->cents($item->insurance);
            $otherCents=$this->cents($item->other_expenses)+$otherShares[$index];
            $discountCents=$this->cents($item->discount)+$discountShares[$index];
            $freight=$freightCents/100;$insurance=$insuranceCents/100;
            $other=$otherCents/100;$discount=$discountCents/100;
            if($freight<0||$insurance<0||$other<0||$discount<0
                ||$discount>$gross+$freight+$insurance+$other){
                throw new RuntimeException('Valores adicionais de item inconsistentes.');
            }
            $totalFreight+=$freightCents;$totalInsurance+=$insuranceCents;
            $totalOther+=$otherCents;$totalDiscount+=$discountCents;
            $factor=NfeTaxMath::scaled($tax['tax_quantity_factor']??'1',4,'Fator da quantidade tributável');
            if($factor<=0)throw new RuntimeException('O fator da unidade tributável deve ser positivo.');
            $taxQuantity=NfeTaxMath::product($line['quantity_units'],$factor,4);
            if($taxQuantity<=0)throw new RuntimeException('A quantidade tributável não pode ser arredondada para zero.');
            $taxPrice=NfeTaxMath::ratio(NfeTaxMath::scaled($item->unit_price,4,'Preço unitário'),10000000000,$factor);
            if(abs(NfeTaxMath::product($taxQuantity,$taxPrice,12)-$grossCents)>1){
                throw new RuntimeException('A conversão da unidade tributável não concilia com o valor do produto. Confira o fator e a precisão.');
            }
            $i=sprintf('%03d',$index+1);
            $totalProduct+=$grossCents;
            $add('Produto'.$i,[
                'cProd'=>$item->product_sku?:'ITEM-'.$i,
                'cEAN'=>$this->digits($item->ean_gtin)?:'SEM GTIN',
                'xProd'=>$env==='2'&&$index===0
                    ? 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
                    : $item->product_name,
                'NCM'=>$this->digits($item->ncm),'CEST'=>$this->digits($item->cest),
                'cBenef'=>$item->fiscal_benefit_code,
                'CFOP'=>$cfop,'uCom'=>$item->unit?:'UN',
                'qCom'=>number_format($qty,4,'.',''),
                'vUnCom'=>number_format((float)$item->unit_price,4,'.',''),
                'vProd'=>NfeTaxMath::format($grossCents),
                'vFrete'=>$freight>0?$this->money($freight):null,
                'vSeg'=>$insurance>0?$this->money($insurance):null,
                'vOutro'=>$other>0?$this->money($other):null,
                'vDesc'=>$discount>0?$this->money($discount):null,
                'cEANTrib'=>$this->digits($item->ean_gtin)?:'SEM GTIN',
                'uTrib'=>$item->tax_unit?:$item->unit?:'UN',
                'qTrib'=>NfeTaxMath::format($taxQuantity,4),
                'vUnTrib'=>NfeTaxMath::format($taxPrice,10),
                'indTot'=>'1','infAdProd'=>$item->notes,
            ]);
            $federalBase=$grossCents+$freightCents+$insuranceCents+$otherCents-$discountCents;
            $icms=app(NfeIcmsItemTaxCalculator::class)->calculate($tax,$federalBase,(string)$company->crt,number_format($qty,4,'.',''));
            $federal=app(NfeFederalItemTaxCalculator::class)->calculate($tax,$federalBase);
            $selective=app(Nfe55SelectiveTaxCalculator::class)->calculate($tax,$draft->issue_date->toDateString());
            if($selective!==null){$selectiveCount++;$add('IS'.$i,$selective['fields']);$totalSelective+=$selective['amount'];}
            $federalSt=app(Nfe55FederalStCalculator::class)->calculate($tax);
            $returnedIpi=app(Nfe55ReturnedIpiCalculator::class)->calculate($tax);
            $totalFederalStAddition+=$federalSt['total_addition'];
            $totalReturnedIpi+=$returnedIpi['amount']??0;
            if($federalSt['pis_st'])$add('PISST'.$i,$federalSt['pis_st']);
            if($federalSt['cofins_st'])$add('COFINSST'.$i,$federalSt['cofins_st']);
            if($returnedIpi!==null)$add('impostoDevol'.$i,$returnedIpi['fields']);
            $difal=app(Nfe55DifalCalculator::class)->calculate($tax,$draft->issue_date->toDateString());
            if($difal!==null){
                if($draft->destination!=='interstate' || !$draft->final_consumer
                    || ($recipient['ie_indicator']??'')!=='non_contributor'
                    || !in_array((string)$company->crt,['2','3'],true) || $operation['tpNF']!=='1'
                    || $draft->purpose!=='normal'){
                    throw new RuntimeException('DIFAL de destino incompatível com a operação ou regime informado.');
                }
                $add('ICMSUFDEST'.$i,$difal['fields']);
                $totalDestinationIcms+=$difal['destination_amount'];
                $totalOriginIcms+=$difal['origin_amount'];
                $totalDestinationFcp+=$difal['fcp_destination_amount'];
            }
            $add('ICMS'.$i,['orig'=>$item->origin]+$icms['fields']);
            $totalDesonerated+=$icms['desonerated_amount'];
            $totalDesonerationDeduction+=$icms['total_deduction'];
            $totalRetainedFcp+=$icms['fcp_st_retained_amount'];
            $totalIcmsBase+=$icms['base_cents'];$totalIcms+=$icms['icms_amount'];
            $totalStBase+=$icms['st_base_cents'];$totalSt+=$icms['st_amount'];
            $totalFcp+=$icms['fcp_amount'];$totalFcpSt+=$icms['fcp_st_amount'];
            $totalPis+=$federal['pis_amount'];$totalCofins+=$federal['cofins_amount'];$totalIpi+=$federal['ipi_amount'];
            $add('PIS'.$i,$federal['pis']);$add('COFINS'.$i,$federal['cofins']);$add('IPI'.$i,$federal['ipi']);
            // The RTC base includes apportioned ancillary amounts and discounts.
            // Legacy taxes are the same amounts serialized above, never zero placeholders.
            $rtc=$this->rtc->calculate($tax,$federalBase-$icms['total_deduction'],(string)$company->crt,
                $draft->issue_date->toDateString(),[
                    'icms'=>$icms['icms_amount']+$icms['fcp_amount']
                        +($difal['destination_amount']??0)+($difal['origin_amount']??0)+($difal['fcp_destination_amount']??0),
                    'pis'=>$federal['pis_amount'],'cofins'=>$federal['cofins_amount'],
                ],$isForeign && $foreignTrade['kind']==='import');
            if($rtc!==null){
                $rtcCount++;
                foreach(['ibscbs'=>'IBSCBS','gibscbs'=>'gIBSCBS','gibsuf'=>'gIBSUF',
                    'gibsmun'=>'gIBSMun','gcbs'=>'gCBS'] as $from=>$prefix){
                    if(!empty($rtc[$from]))$add($prefix.$i,$rtc[$from]);
                }
                foreach($rtc['extra_sections']??[] as $prefix=>$fields){
                    if($prefix==='gAjusteCompet'){
                        $month=\DateTimeImmutable::createFromFormat('!Y-m',$fields['competApur']);
                        $fields['competApur']=$month->format('d/m/Y');
                    }
                    $add($prefix.$i,$fields);
                }
                foreach($rtcTotal as $key=>$unused)$rtcTotal[$key]+=$rtc['totals'][$key]??0;
            }
        }
        if($rtcCount>0 && $rtcCount!==$draft->items->count()){
            throw new RuntimeException('IBS/CBS preenchido somente em alguns itens; confira a classificação fiscal.');
        }
        foreach($foreignTrade['sections'] as $foreignSection)$add($foreignSection['name'],$foreignSection['fields']);
        $totalCents=$totalProduct+$totalFreight+$totalInsurance+$totalOther+$totalIpi+$totalSt+$totalFcpSt
            +$foreignTrade['ii_total_cents']+$totalFederalStAddition+$totalReturnedIpi-$totalDiscount-$totalDesonerationDeduction;
        if($totalCents<0 || $totalCents>999999999999999)throw new RuntimeException('Total da NF-e fora dos limites do documento fiscal.');
        $legacyTotal=NfeTaxMath::format($totalCents);
        $modern=$draft->issue_date->toDateString()>='2027-01-01';
        $total=NfeTaxMath::format($totalCents+($modern?($totalSelective+$rtcTotal['uf']+$rtcTotal['mun']+$rtcTotal['cbs']):0));
        $expected=$this->money(data_get($draft->totals,'total',data_get($draft->totals,'products',0)));
        if($expected!==$total)throw new RuntimeException('Total fiscal não confere com o rascunho.');
        $add('Total',[
            'vBC'=>NfeTaxMath::format($totalIcmsBase),'vICMS'=>NfeTaxMath::format($totalIcms),
            'vBCST'=>NfeTaxMath::format($totalStBase),'vST'=>NfeTaxMath::format($totalSt),
            'vFCP'=>NfeTaxMath::format($totalFcp),'vFCPST'=>NfeTaxMath::format($totalFcpSt),
            'vII'=>NfeTaxMath::format($foreignTrade['ii_total_cents']),
            'vFCPSTRet'=>NfeTaxMath::format($totalRetainedFcp),'vICMSDeson'=>NfeTaxMath::format($totalDesonerated),
            'vFCPUFDest'=>NfeTaxMath::format($totalDestinationFcp),
            'vICMSUFDest'=>NfeTaxMath::format($totalDestinationIcms),'vICMSUFRemet'=>NfeTaxMath::format($totalOriginIcms),
            'vProd'=>$this->money($totalProduct/100),
            'vFrete'=>$this->money($totalFreight/100),
            'vSeg'=>$this->money($totalInsurance/100),
            'vOutro'=>$this->money($totalOther/100),
            'vDesc'=>$this->money($totalDiscount/100),'vIPI'=>NfeTaxMath::format($totalIpi),
            'vIPIDevol'=>NfeTaxMath::format($totalReturnedIpi),
            'vPIS'=>$this->money($totalPis/100),'vCOFINS'=>$this->money($totalCofins/100),
            'vNF'=>$legacyTotal,'vNFTot'=>$modern?$total:null,
        ]);
        if($selectiveCount>0)$add('ISTot',['vIS'=>NfeTaxMath::format($totalSelective)]);
        if($rtcCount>0){
            $m=fn($key)=>$this->money($rtcTotal[$key]/100);
            $add('IBSCBSTot',['vBCIBSCBS'=>$m('bc')]);
            $add('gIBS',['vIBS'=>$this->money(($rtcTotal['uf']+$rtcTotal['mun'])/100),'vCredPres'=>$m('presumed_ibs'),'vCredPresCondSus'=>$m('presumed_conditional_ibs')]);
            $add('gIBSUFTot',['vIBSUF'=>$m('uf'),'vDif'=>$m('dif_uf'),'vDevTrib'=>$m('dev_uf')]);
            $add('gIBSMunTot',['vIBSMun'=>$m('mun'),'vDif'=>$m('dif_mun'),'vDevTrib'=>$m('dev_mun')]);
            $add('gCBSTot',['vCBS'=>$m('cbs'),'vDif'=>$m('dif_cbs'),'vDevTrib'=>$m('dev_cbs'),'vCredPres'=>$m('presumed_cbs'),'vCredPresCondSus'=>$m('presumed_conditional_cbs')]);
        }
        $transport=$draft->transport_data??[];
        $freightMode=(string)($transport['freight_mode']??'9');
        if(!in_array($freightMode,['0','1','2','3','4','9'],true)){
            throw new RuntimeException('Modalidade de frete inválida.');
        }
        $carrier=$transport['carrier']??[];
        $add('Transportador',[
            'modFrete'=>$freightMode,
            'CNPJCPF'=>$this->digits($carrier['document']??null)?:null,
            'xNome'=>$carrier['name']??null,'IE'=>$this->digits($carrier['state_registration']??null)?:null,
            'xEnder'=>$carrier['address']??null,'xMun'=>$carrier['city']??null,
            'UF'=>$carrier['state']??null,
            'Placa'=>strtoupper(trim((string)($transport['vehicle_plate']??'')))?:null,
            'UFPlaca'=>$transport['vehicle_state']??null,
            'RNTC'=>$carrier['rntrc']??null,
        ]);
        if((float)($transport['volume_quantity']??0)>0){
            $add('Volume001',[
                'qVol'=>(int)$transport['volume_quantity'],
                'esp'=>$transport['volume_species']??null,
                'nVol'=>$transport['volume_numbering']??null,
                'pesoL'=>$transport['net_weight']??null,
                'pesoB'=>$transport['gross_weight']??null,
            ]);
        }
        $duplicates=$draft->duplicates??[];
        $payments=$draft->payments??[];
        $hasDuplicatePayment=false;
        foreach($payments as $payment){
            if((string)($payment['type']??'')==='14')$hasDuplicatePayment=true;
        }
        if((bool)$duplicates!==$hasDuplicatePayment){
            throw new RuntimeException('Duplicatas exigem forma de pagamento Duplicata Mercantil e vice-versa.');
        }
        if($duplicates){
            $dueTotal=0;
            foreach($duplicates as $dup)$dueTotal+=(int)round((float)($dup['value']??0)*100);
            if($dueTotal!==(int)round((float)$total*100)){
                throw new RuntimeException('Somatório das duplicatas difere do valor da NF-e.');
            }
            $add('Fatura',[
                'nFat'=>(string)$number,'vOrig'=>$total,'vDesc'=>'0.00','vLiq'=>$total,
            ]);
            $previousDue=null;
            foreach($duplicates as $index=>$duplicate){
                $due=(string)($duplicate['due_date']??'');
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$due);
                if(!$date||$date->format('Y-m-d')!==$due){
                    throw new RuntimeException('Vencimento de duplicata inválido.');
                }
                if($previousDue!==null&&strcmp($due,$previousDue)<0){
                    throw new RuntimeException('Duplicatas fora da ordem de vencimento.');
                }
                $previousDue=$due;
                $add('Duplicata'.sprintf('%03d',$index+1),[
                    'nDup'=>$duplicate['number']?:str_pad((string)($index+1),3,'0',STR_PAD_LEFT),
                    'dVenc'=>$date->format('d/m/Y'),'vDup'=>$this->money($duplicate['value']),
                ]);
            }
        }
        if(!$payments)throw new RuntimeException('Nenhuma forma de pagamento informada.');
        $totalPaid=0;$hasNoPayment=false;
        foreach($payments as $index=>$payment){
            $type=(string)($payment['type']??'');
            $amount=(float)($payment['amount']??0);
            if(!preg_match('/^\d{2}$/',$type)||$amount<0)
                throw new RuntimeException('Forma de pagamento inválida.');
            if($type==='90')$hasNoPayment=true;
            $card=in_array($type,['03','04'],true);
            $integration=(string)($payment['integration_type']??'');
            if($card){
                if(!in_array($integration,['1','2'],true)){
                    throw new RuntimeException('Informe o tipo de integração do pagamento com cartão.');
                }
                $acquirer=$this->digits($payment['acquirer_document']??null);
                if($integration==='1'&&(strlen($acquirer)!==14
                    ||trim((string)($payment['authorization_code']??''))==='')){
                    throw new RuntimeException('Pagamento integrado exige CNPJ da credenciadora e autorização.');
                }
            }
            $totalPaid+=(int)round($amount*100,0,PHP_ROUND_HALF_UP);
            $add('pag'.sprintf('%03d',$index+1),[
                'tPag'=>$type,'vPag'=>$this->money($amount),
                'indPag'=>($payment['condition']??'a_vista')==='a_vista'?'0':'1',
                'xPag'=>$type==='99'?($payment['other_description']??null):null,
                'tpIntegra'=>$card?$integration:null,
                'tBand'=>$card?($payment['card_brand']??null):null,
                'CNPJ'=>in_array($type,['03','04'],true)
                    ?$this->digits($payment['acquirer_document']??null):null,
                'cAut'=>in_array($type,['03','04'],true)?($payment['authorization_code']??null):null,
            ]);
        }
        if($hasNoPayment ? count($payments)!==1 || $totalPaid!==0
            : $totalPaid!==(int)round((float)$total*100)){
            throw new RuntimeException('Os pagamentos não conciliam com o total da NF-e.');
        }
        if($draft->has_referenced_document){
            $refs=$draft->references??[];
            if(!$refs)throw new RuntimeException('Documento referenciado não informado.');
            foreach($refs as $i=>$reference){
                $type=strtoupper((string)($reference['type']??'NFE'));
                $key=(string)($reference['key']??'');
                if(!in_array($type,['NFE','CTE'],true)||!preg_match('/^\d{44}$/',$key)){
                    throw new RuntimeException('Documento fiscal referenciado inválido.');
                }
                $add('NFRef'.sprintf('%03d',$i+1),[
                    'Tipo'=>$type,
                    'refNFe'=>$type==='NFE'?$key:null,
                    'refCTe'=>$type==='CTE'?$key:null,
                ]);
            }
        }
        if($draft->additional_info || $draft->tax_authority_info){
            $add('DadosAdicionais',['infCpl'=>$draft->additional_info,'infAdFisco'=>$draft->tax_authority_info]);
        }
        return implode("\r\n\r\n",$sections)."\r\n";
    }

    /**
     * Cent-exact proportional allocation. Cumulative rounding assigns every
     * residual cent exactly once, without modifying stored draft item values.
     *
     * @param array<int,int> $weights Nonnegative item weights expressed in cents
     * @return array<int,int>
     */
    private function hasFiscalValue(mixed $value): bool
    {
        if(is_array($value)){
            foreach($value as $child)if($this->hasFiscalValue($child))return true;
            return false;
        }
        return $value!==null && $value!=='' && $value!==false && $value!==0;
    }

    private function proportionalShares(int $amount,array $weights): array
    {
        $shares=array_fill(0,count($weights),0);
        if($amount===0)return $shares;
        $totalWeight=array_sum($weights);
        if($amount<0||$totalWeight<=0){
            throw new RuntimeException('Não é possível ratear valores sem produtos válidos.');
        }
        $cumulativeWeight=0;
        $distributed=0;
        foreach($weights as $index=>$weight){
            if($weight<0)throw new RuntimeException('Peso de rateio inválido.');
            $cumulativeWeight+=$weight;
            $next=NfeTaxMath::ratio($amount,$cumulativeWeight,$totalWeight);
            $shares[$index]=$next-$distributed;
            $distributed=$next;
        }
        return $shares;
    }

    private function cents(mixed $value): int
    {
        $amount=NfeTaxMath::scaled($value??'0',2,'Valor monetário');
        if($amount>999999999999999)throw new RuntimeException('Valor monetário superior ao limite do documento fiscal.');
        return $amount;
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D/','',(string)$value);
    }

    private function money(mixed $value): string
    {
        return number_format(round((float)$value,2,PHP_ROUND_HALF_UP),2,'.','');
    }
}
