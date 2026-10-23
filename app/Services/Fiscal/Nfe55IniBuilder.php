<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use RuntimeException;

/**
 * NF-e model 55: initial, deliberately bounded domestic merchandise scenario.
 * It will refuse unknown taxes rather than turn an editor label into a fiscal value.
 */
final class Nfe55IniBuilder
{
    private const UF=[
        'RO'=>'11','AC'=>'12','AM'=>'13','RR'=>'14','PA'=>'15','AP'=>'16','TO'=>'17',
        'MA'=>'21','PI'=>'22','CE'=>'23','RN'=>'24','PB'=>'25','PE'=>'26','AL'=>'27',
        'SE'=>'28','BA'=>'29','MG'=>'31','ES'=>'32','RJ'=>'33','SP'=>'35',
        'PR'=>'41','SC'=>'42','RS'=>'43','MS'=>'50','MT'=>'51','GO'=>'52','DF'=>'53',
    ];
    public function __construct(private readonly NFCeRtcTaxService $rtc) {}

    public function build(NfeDraft $draft,CompanySetting $company,int $number,int $numericCode): string
    {
        $draft->loadMissing(['items','operationNature']);
        if($draft->operation_type!=='outbound'
            ||$draft->destination!=='internal'
            ||$draft->purpose!=='normal'
            ||!in_array((string)$company->crt,['1','4'],true)) {
            throw new RuntimeException('Esta operação de NF-e exige um cenário fiscal específico ainda não homologado.');
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
        if(!in_array(strlen($doc),[11,14],true)
            ||strtoupper((string)($recipient['state']??''))!==$uf
            ||strlen($this->digits($recipient['city_ibge_code']??''))!==7
            ||strlen($this->digits($recipient['zip_code']??''))!==8
            ||!($recipient['name']??'')
            ||!($recipient['address']??'')
            ||!($recipient['address_number']??'')
            ||!($recipient['district']??'')) {
            throw new RuntimeException('Destinatário incompleto ou fora do cenário interno.');
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
            'dhEmi'=>($draft->issue_date??now())->format('d/m/Y').' '.substr((string)($draft->issue_time?:'12:00'),0,5).':00',
            'tpNF'=>'1','idDest'=>'1','tpAmb'=>$env,'tpImp'=>'1','tpEmis'=>'1',
            'finNFe'=>'1','indFinal'=>$draft->final_consumer?'1':'0',
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
            'CNPJCPF'=>$doc,
            'xNome'=>$env==='2'?'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL':$recipient['name'],
            'indIEDest'=>match($recipient['ie_indicator']??'non_contributor'){
                'contributor'=>'1','exempt'=>'2',default=>'9'},
            'IE'=>($recipient['ie_indicator']??'non_contributor')==='contributor'
                ? $this->digits($recipient['state_registration']??'') : null,
            'xLgr'=>$recipient['address'],'nro'=>$recipient['address_number'],
            'xCpl'=>$recipient['address_complement']??null,'xBairro'=>$recipient['district'],
            'cMun'=>$this->digits($recipient['city_ibge_code']),'xMun'=>$recipient['city'],
            'UF'=>$uf,'CEP'=>$this->digits($recipient['zip_code']),
            'cPais'=>'1058','xPais'=>'BRASIL','Fone'=>$this->digits($recipient['phone']??null),
            'email'=>$recipient['email']??null,
        ]);
        if($draft->different_delivery){
            $delivery=$draft->delivery_snapshot??[];
            if(!$delivery || strlen($this->digits($delivery['zip_code']??''))!==8
                || strlen($this->digits($delivery['city_ibge_code']??''))!==7
                || !($delivery['address']??null)||!($delivery['address_number']??null)
                || !($delivery['district']??null)||!($delivery['city']??null)
                || !($delivery['state']??null)){
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
        $grossWeights=$items->map(fn($item)=>(int)round(
            (float)$item->quantity*(float)$item->dimension_quantity*(float)$item->unit_price*100,
            0,PHP_ROUND_HALF_UP
        ))->all();
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
        $totalFreight=0;$totalInsurance=0;$totalOther=0;$totalDiscount=0;
        $rtcTotal=['bc'=>0,'uf'=>0,'mun'=>0,'cbs'=>0];$rtcCount=0;
        foreach($items as $index=>$item) {
            $tax=$item->tax_data??[];
            // Normalize legacy editor keys into the RTC engine's canonical fields.
            $ibsCst=trim((string)($tax['ibs_cst']??''));
            $cbsCst=trim((string)($tax['cbs_cst']??''));
            $classification=trim((string)($tax['tax_classification_code']??''));
            if($ibsCst!==''||$cbsCst!==''||$classification!==''){
                if($ibsCst===''||$cbsCst===''||$ibsCst!==$cbsCst){
                    throw new RuntimeException('CST de IBS e CBS devem corresponder à classificação informada.');
                }
                $tax['ibs_cbs_cst']=$ibsCst;
                $tax['ibs_cbs_class']=$classification;
            }
            $qty=(float)$item->quantity*(float)$item->dimension_quantity;
            $gross=round($qty*(float)$item->unit_price,2);
            $cfop=(string)$item->cfop;
            if($qty<=0 || $gross<=0 || !preg_match('/^5\d{3}$/',$cfop)
                ||!preg_match('/^\d{8}$/',$this->digits($item->ncm))
                ||!preg_match('/^[0-8]$/',(string)$item->origin)) {
                throw new RuntimeException('Confira quantidade, NCM, CFOP e origem dos produtos da NF-e.');
            }
            if($item->special_data && array_filter($item->special_data,static fn($v)=>$v!==null&&$v!==''&&$v!==false&&$v!==0)) {
                throw new RuntimeException('Este produto exige grupo fiscal específico ainda não homologado.');
            }
            $csosn=(string)($tax['icms_csosn']??'');
            if(!in_array($csosn,['102','103','300','400'],true)) {
                throw new RuntimeException("CSOSN {$csosn} não homologado para NF-e neste fluxo.");
            }
            $pis=(string)($tax['pis_cst']??'');
            $cofins=(string)($tax['cofins_cst']??'');
            $ipi=(string)($tax['ipi_cst']??'');
            if(!in_array($pis,['49','99'],true)||!in_array($cofins,['49','99'],true)
                ||!in_array($ipi,['53'],true)) {
                throw new RuntimeException('Tributação federal deste item requer cálculo específico ainda não homologado.');
            }
            foreach(['ipi_rate','pis_rate','cofins_rate','iss_rate'] as $rateField){
                if((float)($tax[$rateField]??0)!==0.0){
                    throw new RuntimeException('Tributação federal deste item exige cálculo ainda não homologado.');
                }
            }
            $freight=($this->cents($item->freight)+$freightShares[$index])/100;
            $insurance=$this->cents($item->insurance)/100;
            $other=($this->cents($item->other_expenses)+$otherShares[$index])/100;
            $discount=($this->cents($item->discount)+$discountShares[$index])/100;
            if($freight<0||$insurance<0||$other<0||$discount<0
                ||$discount>$gross+$freight+$insurance+$other){
                throw new RuntimeException('Valores adicionais de item inconsistentes.');
            }
            $totalFreight+=(int)round($freight*100);
            $totalInsurance+=(int)round($insurance*100);
            $totalOther+=(int)round($other*100);
            $totalDiscount+=(int)round($discount*100);
            $i=sprintf('%03d',$index+1);
            $totalProduct+=(int)round($gross*100);
            $add('Produto'.$i,[
                'cProd'=>$item->product_sku?:'ITEM-'.$i,
                'cEAN'=>$this->digits($item->ean_gtin)?:'SEM GTIN',
                'xProd'=>$env==='2'&&$index===0
                    ? 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
                    : $item->product_name,
                'NCM'=>$this->digits($item->ncm),'CEST'=>$this->digits($item->cest),
                'CFOP'=>$cfop,'uCom'=>$item->unit?:'UN',
                'qCom'=>number_format($qty,4,'.',''),
                'vUnCom'=>number_format((float)$item->unit_price,4,'.',''),
                'vProd'=>$this->money($gross),
                'vFrete'=>$freight>0?$this->money($freight):null,
                'vSeg'=>$insurance>0?$this->money($insurance):null,
                'vOutro'=>$other>0?$this->money($other):null,
                'vDesc'=>$discount>0?$this->money($discount):null,
                'cEANTrib'=>$this->digits($item->ean_gtin)?:'SEM GTIN',
                'uTrib'=>$item->tax_unit?:$item->unit?:'UN',
                'qTrib'=>number_format($qty,4,'.',''),
                'vUnTrib'=>number_format((float)$item->unit_price,4,'.',''),
                'indTot'=>'1','infAdProd'=>$item->notes,
            ]);
            $add('ICMS'.$i,['orig'=>$item->origin,'CSOSN'=>$csosn]);
            $add('PIS'.$i,['CST'=>$pis,'vBC'=>'0.00','pPIS'=>'0.00','vPIS'=>'0.00']);
            $add('COFINS'.$i,['CST'=>$cofins,'vBC'=>'0.00','pCOFINS'=>'0.00','vCOFINS'=>'0.00']);
            $add('IPI'.$i,['CST'=>$ipi,'cEnq'=>$tax['ipi_enq']??'999']);
            // Only tax profiles the RTC calculator can prove; never fabricate IBS/CBS.
            $rtc=$this->rtc->calculate([
                'quantity'=>$qty,'unit_price'=>(float)$item->unit_price,
                'discount'=>0,'tax_defaults'=>$tax,'origin'=>$item->origin,
            ],(string)$company->crt,$draft->issue_date?->toDateString());
            if($rtc!==null){
                if($freight>0||$insurance>0||$other>0||$discount>0){
                    throw new RuntimeException('IBS/CBS com rateio de valores ainda não homologado.');
                }
                $rtcCount++;
                foreach(['ibscbs'=>'IBSCBS','gibscbs'=>'gIBSCBS','gibsuf'=>'gIBSUF',
                    'gibsmun'=>'gIBSMun','gcbs'=>'gCBS'] as $from=>$prefix){
                    $add($prefix.$i,$rtc[$from]);
                }
                $rtcTotal['bc']+=(int)round((float)$rtc['gibscbs']['vBC']*100);
                $rtcTotal['uf']+=(int)round((float)$rtc['gibsuf']['vIBSUF']*100);
                $rtcTotal['mun']+=(int)round((float)$rtc['gibsmun']['vIBSMun']*100);
                $rtcTotal['cbs']+=(int)round((float)$rtc['gcbs']['vCBS']*100);
            }
        }
        if($rtcCount>0 && $rtcCount!==$draft->items->count()){
            throw new RuntimeException('IBS/CBS preenchido somente em alguns itens; confira a classificação fiscal.');
        }
        $total=$this->money(($totalProduct+$totalFreight+$totalInsurance+$totalOther-$totalDiscount)/100);
        $expected=$this->money(data_get($draft->totals,'total',data_get($draft->totals,'products',0)));
        if($expected!==$total)throw new RuntimeException('Total fiscal não confere com o rascunho.');
        $add('Total',[
            'vBC'=>'0.00','vICMS'=>'0.00','vBCST'=>'0.00','vST'=>'0.00',
            'vProd'=>$this->money($totalProduct/100),
            'vFrete'=>$this->money($totalFreight/100),
            'vSeg'=>$this->money($totalInsurance/100),
            'vOutro'=>$this->money($totalOther/100),
            'vDesc'=>$this->money($totalDiscount/100),'vIPI'=>'0.00',
            'vPIS'=>$this->money($totalPis/100),'vCOFINS'=>$this->money($totalCofins/100),
            'vNF'=>$total,
        ]);
        if($rtcCount>0){
            $m=fn($key)=>$this->money($rtcTotal[$key]/100);
            $add('IBSCBSTot',['vBCIBSCBS'=>$m('bc')]);
            $add('gIBS',['vIBS'=>$this->money(($rtcTotal['uf']+$rtcTotal['mun'])/100)]);
            $add('gIBSUFTot',['vIBSUF'=>$m('uf')]);
            $add('gIBSMunTot',['vIBSMun'=>$m('mun')]);
            $add('gCBSTot',['vCBS'=>$m('cbs')]);
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
        if($draft->additional_info)$add('DadosAdicionais',['infCpl'=>$draft->additional_info]);
        return implode("\r\n\r\n",$sections)."\r\n";
    }

    /**
     * Cent-exact proportional allocation. Cumulative rounding assigns every
     * residual cent exactly once, without modifying stored draft item values.
     *
     * @param array<int,int> $weights Nonnegative item weights expressed in cents
     * @return array<int,int>
     */
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
            $next=(int)round($amount*$cumulativeWeight/$totalWeight,0,PHP_ROUND_HALF_UP);
            $shares[$index]=$next-$distributed;
            $distributed=$next;
        }
        return $shares;
    }

    private function cents(mixed $value): int
    {
        return (int)round((float)$value*100,0,PHP_ROUND_HALF_UP);
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
