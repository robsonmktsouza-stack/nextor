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
        if((float)$draft->surcharge!==0.0
            ||(float)data_get($draft->transport_data,'freight_value',0)!==0.0
            ||(float)$draft->discount!==0.0) {
            throw new RuntimeException('Rateio de frete, descontos ou acréscimos exige tratamento fiscal específico.');
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
        $totalProduct=0;$totalPis=0;$totalCofins=0;$totalIpi=0;
        $rtcTotal=['bc'=>0,'uf'=>0,'mun'=>0,'cbs'=>0];$rtcCount=0;
        foreach($draft->items as $index=>$item) {
            $tax=$item->tax_data??[];
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
        $total=$this->money($totalProduct/100);
        $expected=$this->money(data_get($draft->totals,'total',data_get($draft->totals,'products',0)));
        if($expected!==$total)throw new RuntimeException('Total fiscal não confere com o rascunho.');
        $add('Total',[
            'vBC'=>'0.00','vICMS'=>'0.00','vBCST'=>'0.00','vST'=>'0.00',
            'vProd'=>$total,'vFrete'=>'0.00','vDesc'=>'0.00','vIPI'=>'0.00',
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
        $add('Transportador',['modFrete'=>'9']);
        $add('pag001',[
            'tPag'=>$draft->payment_type==='90'?'90':$draft->payment_type,
            'vPag'=>$draft->payment_type==='90'?'0.00':$total,
            'indPag'=>'0',
            'xPag'=>$draft->payment_type==='99'?$draft->payment_other_description:null,
        ]);
        if($draft->additional_info)$add('DadosAdicionais',['infCpl'=>$draft->additional_info]);
        return implode("\r\n\r\n",$sections)."\r\n";
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
