@extends('layouts.app')
@section('title',$editing ? 'Editar produto' : 'Novo produto')

@section('content')
@php
$units=['UN'=>'UN — Unidade','KG'=>'KG — Quilograma','G'=>'G — Grama','M'=>'M — Metro','M2'=>'M² — Metro quadrado','M3'=>'M³ — Metro cúbico','L'=>'L — Litro','ML'=>'ML — Mililitro','CX'=>'CX — Caixa','PC'=>'PC — Peça','PCT'=>'PCT — Pacote'];
$taxDefaults=old('tax_defaults',$product->tax_defaults ?? []);
$origins=[
 '0'=>'0 - Nacional, exceto as indicadas nos códigos 3, 4, 5 e 8',
 '1'=>'1 - Estrangeira: importação direta',
 '2'=>'2 - Estrangeira: adquirida no mercado interno',
 '3'=>'3 - Nacional, mercadoria com conteúdo de importação superior a 40% e inferior ou igual a 70%',
 '4'=>'4 - Nacional, produção em conformidade com processo produtivo básico',
 '5'=>'5 - Nacional, mercadoria com conteúdo de importação inferior ou igual a 40%',
 '6'=>'6 - Estrangeira: importação direta, sem similar nacional',
 '7'=>'7 - Estrangeira: adquirida no mercado interno, sem similar nacional',
 '8'=>'8 - Nacional, mercadoria com conteúdo de importação superior a 70%',
];
@endphp

<form class="product-editor" method="post" enctype="multipart/form-data" action="{{ $editing ? route('products.update',$product) : route('products.store') }}">
 @csrf
 @if($editing) @method('PUT') @endif

 <div class="editor-tabs product-tabs" data-tabs>
   <button type="button" class="editor-tab active" data-tab-target="product-data">Dados do produto</button>
   <button type="button" class="editor-tab" data-tab-target="product-fiscal">Dados fiscais</button>
   <button type="button" class="editor-tab" data-tab-target="product-media">Fotos / Integração</button>
 </div>

 <section class="editor-tab-panel active" data-tab-panel="product-data">
   <div class="editor-panel">
     <div class="editor-grid cols-12">
       <label class="field col-9"><span>Nome *</span><input name="name" value="{{ old('name',$product->name) }}" required autofocus maxlength="190"></label>
       <label class="field col-3"><span>Código próprio</span><input name="sku" value="{{ old('sku',$product->sku) }}" maxlength="80" placeholder="Gerado automaticamente"></label>

       <label class="field col-4"><span>Categoria</span><input name="category" value="{{ old('category',$product->category) }}"></label>
       <label class="field col-4"><span>Palavras-chave</span><input name="keywords" value="{{ old('keywords',$product->keywords) }}"></label>
       <label class="field col-4"><span>Tipo de uso</span>
         <select name="usage_type">
           <option value="resale" @selected(old('usage_type',$product->usage_type)==='resale')>Mercadoria para revenda</option>
           <option value="consumption" @selected(old('usage_type',$product->usage_type)==='consumption')>Material de uso e consumo</option>
           <option value="raw_material" @selected(old('usage_type',$product->usage_type)==='raw_material')>Matéria-prima</option>
           <option value="fixed_asset" @selected(old('usage_type',$product->usage_type)==='fixed_asset')>Ativo imobilizado</option>
           <option value="packaging" @selected(old('usage_type',$product->usage_type)==='packaging')>Embalagem</option>
           <option value="other" @selected(old('usage_type',$product->usage_type)==='other')>Outros</option>
         </select>
       </label>

       <div class="field col-4"><span>Arquivar (ocultar)</span>
         <label class="switch-field inverse-switch">
           <input type="hidden" name="is_active" value="0">
           <input type="checkbox" name="is_active" value="1" @checked(old('is_active',$product->is_active ?? true))>
           <span class="switch-track"></span><strong data-active-label>{{ old('is_active',$product->is_active ?? true) ? 'Não' : 'Sim' }}</strong>
         </label>
       </div>
     </div>
   </div>

   <div class="editor-panel">
     <h3>Valores unitários</h3>
     <div class="editor-grid cols-12" data-price-area>
       <label class="field col-4"><span>Preço de custo</span><div class="input-prefix"><span>R$</span><input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price',$product->cost_price ?? 0) }}" required data-cost-price></div></label>
       <label class="field col-4"><span>Preço de venda</span><div class="input-prefix"><span>R$</span><input type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price',$product->sale_price ?? 0) }}" required data-sale-price></div></label>
       <label class="field col-4"><span>Margem de contribuição</span><div class="input-prefix input-readonly"><span>%</span><input type="text" readonly tabindex="-1" data-margin-output></div></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>Estoque</h3>
     <div class="editor-grid cols-12">
       <div class="field col-2"><span>Controlar estoque</span>
         <label class="switch-field">
           <input type="hidden" name="control_stock" value="0">
           <input type="checkbox" name="control_stock" value="1" @checked(old('control_stock',$product->control_stock ?? true)) data-stock-control>
           <span class="switch-track"></span><strong data-switch-label>{{ old('control_stock',$product->control_stock ?? true) ? 'Sim' : 'Não' }}</strong>
         </label>
       </div>
       <label class="field col-5"><span>Estoque atual</span><input type="number" step="{{ $stockDecimalPlaces===0 ? '1' : '0.'.str_repeat('0',$stockDecimalPlaces-1).'1' }}" name="stock_quantity" value="{{ old('stock_quantity',number_format((float)($product->stock_quantity ?? 0),$stockDecimalPlaces,'.','')) }}" data-stock-field></label>
       <label class="field col-5"><span>Estoque mínimo</span><input type="number" step="{{ $stockDecimalPlaces===0 ? '1' : '0.'.str_repeat('0',$stockDecimalPlaces-1).'1' }}" min="0" name="minimum_stock" value="{{ old('minimum_stock',number_format((float)($product->minimum_stock ?? 0),$stockDecimalPlaces,'.','')) }}" required data-stock-field></label>
     </div>
     <p class="editor-help">@include('partials.icon',['name'=>'clock','size'=>14]) Alterações no estoque atual geram uma movimentação de ajuste no histórico.</p>
   </div>

   <div class="editor-panel notes-panel">
     <label class="field"><span>Anotações internas</span><textarea name="description" rows="4">{{ old('description',$product->description) }}</textarea></label>
   </div>
 </section>

 <section class="editor-tab-panel" data-tab-panel="product-fiscal" hidden>
   <div class="editor-panel">
     <div class="editor-grid cols-12">
       <label class="field col-12"><span>Origem</span>
         <select name="origin">@foreach($origins as $code=>$label)<option value="{{ $code }}" @selected(old('origin',$product->origin ?? '0')===$code)>{{ $label }}</option>@endforeach</select>
       </label>

       <label class="field col-3"><span>Referência EAN/GTIN</span><input name="ean_gtin" value="{{ old('ean_gtin',$product->ean_gtin) }}" placeholder="EAN/GTIN"></label>
       <label class="field col-3"><span>Peso unitário líquido (kg)</span><input type="number" step="0.001" min="0" name="net_weight" value="{{ old('net_weight',$product->net_weight) }}"></label>
       <label class="field col-3"><span>Peso unitário bruto (kg)</span><input type="number" step="0.001" min="0" name="gross_weight" value="{{ old('gross_weight',$product->gross_weight) }}"></label>
       <label class="field col-3"><span>NCM</span><input name="ncm" value="{{ old('ncm',$product->ncm) }}" maxlength="10" placeholder="00000000"></label>

       <label class="field col-3"><span>Unidade comercial</span><select name="unit">@foreach($units as $code=>$label)<option value="{{ $code }}" @selected(old('unit',$product->unit ?? 'UN')===$code)>{{ $label }}</option>@endforeach</select></label>
       <label class="field col-3"><span>Exceção tabela IPI</span><input name="ipi_exception" value="{{ old('ipi_exception',$product->ipi_exception) }}"></label>
       <label class="field col-3"><span>Código CEST</span><input name="cest" value="{{ old('cest',$product->cest) }}" maxlength="10"></label>
       <label class="field col-3"><span>Cód. benefício fiscal na UF</span><input name="fiscal_benefit_code" value="{{ old('fiscal_benefit_code',$product->fiscal_benefit_code) }}"></label>

       <div class="field col-3"><span>Unidade tributada diferente</span>
         <label class="switch-field">
           <input type="hidden" name="different_tax_unit" value="0">
           <input type="checkbox" name="different_tax_unit" value="1" @checked(old('different_tax_unit',$product->different_tax_unit)) data-tax-unit-toggle>
           <span class="switch-track"></span><strong data-switch-label>{{ old('different_tax_unit',$product->different_tax_unit) ? 'Sim' : 'Não' }}</strong>
         </label>
       </div>
       <label class="field col-3"><span>Unidade tributável</span><select name="tax_unit" data-tax-unit-field><option value="">Igual à comercial</option>@foreach($units as $code=>$label)<option value="{{ $code }}" @selected(old('tax_unit',$product->tax_unit)===$code)>{{ $label }}</option>@endforeach</select></label>
       <label class="field col-3"><span>Tributos no preço compra/venda</span>
         <select name="ignore_taxes_mode">
           <option value="none" @selected(old('ignore_taxes_mode',$product->ignore_taxes_mode ?? 'none')==='none')>Não ignorar tributos</option>
           <option value="purchase" @selected(old('ignore_taxes_mode',$product->ignore_taxes_mode)==='purchase')>Ignorar na compra</option>
           <option value="sale" @selected(old('ignore_taxes_mode',$product->ignore_taxes_mode)==='sale')>Ignorar na venda</option>
           <option value="both" @selected(old('ignore_taxes_mode',$product->ignore_taxes_mode)==='both')>Ignorar na compra e venda</option>
         </select>
       </label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>CFOP padrão da NF-e</h3>
     <div class="editor-grid cols-12">
       <label class="field col-3"><span>Saída dentro do estado</span><input name="tax_defaults[cfop_outbound_internal]" value="{{ data_get($taxDefaults,'cfop_outbound_internal') }}" maxlength="10" placeholder="5102"></label>
       <label class="field col-3"><span>Saída para outro estado</span><input name="tax_defaults[cfop_outbound_interstate]" value="{{ data_get($taxDefaults,'cfop_outbound_interstate') }}" maxlength="10" placeholder="6102"></label>
       <label class="field col-3"><span>Entrada dentro do estado</span><input name="tax_defaults[cfop_inbound_internal]" value="{{ data_get($taxDefaults,'cfop_inbound_internal') }}" maxlength="10" placeholder="1102"></label>
       <label class="field col-3"><span>Entrada de outro estado</span><input name="tax_defaults[cfop_inbound_interstate]" value="{{ data_get($taxDefaults,'cfop_inbound_interstate') }}" maxlength="10" placeholder="2102"></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>ICMS / Simples Nacional</h3>
     <div class="editor-grid cols-12">
       <label class="field col-2"><span>CSOSN</span><input name="tax_defaults[icms_csosn]" value="{{ data_get($taxDefaults,'icms_csosn',data_get($taxDefaults,'icms_csosn_default')) }}" maxlength="4"></label>
       <label class="field col-2"><span>CST ICMS</span><input name="tax_defaults[icms_cst]" value="{{ data_get($taxDefaults,'icms_cst',data_get($taxDefaults,'icms_cst_default')) }}" maxlength="4"></label>
       <label class="field col-2"><span>CSOSN exportação</span><input name="tax_defaults[icms_csosn_export]" value="{{ data_get($taxDefaults,'icms_csosn_export') }}" maxlength="4"></label>
       <label class="field col-2"><span>CSOSN entrada</span><input name="tax_defaults[icms_csosn_inbound]" value="{{ data_get($taxDefaults,'icms_csosn_inbound') }}" maxlength="4"></label>
       <label class="field col-2"><span>CST ICMS entrada</span><input name="tax_defaults[icms_cst_inbound]" value="{{ data_get($taxDefaults,'icms_cst_inbound') }}" maxlength="4"></label>
       <label class="field col-2"><span>ICMS %</span><input type="number" step="0.0001" min="0" name="tax_defaults[icms_rate]" value="{{ data_get($taxDefaults,'icms_rate') }}"></label>
       <label class="field col-2"><span>Redução BC %</span><input type="number" step="0.0001" min="0" name="tax_defaults[base_reduction_rate]" value="{{ data_get($taxDefaults,'base_reduction_rate') }}"></label>
       <label class="field col-2"><span>Crédito Simples %</span><input type="number" step="0.0001" min="0" name="tax_defaults[simple_credit_rate]" value="{{ data_get($taxDefaults,'simple_credit_rate') }}"></label>

       <label class="field col-2"><span>Modalidade BC</span><input name="tax_defaults[mod_bc]" value="{{ data_get($taxDefaults,'mod_bc') }}"></label>
       <label class="field col-2"><span>Modalidade BC ST</span><input name="tax_defaults[mod_bc_st]" value="{{ data_get($taxDefaults,'mod_bc_st') }}"></label>
       <label class="field col-2"><span>ICMS ST %</span><input type="number" step="0.0001" min="0" name="tax_defaults[icms_st_rate]" value="{{ data_get($taxDefaults,'icms_st_rate') }}"></label>
       <label class="field col-2"><span>MVA %</span><input type="number" step="0.0001" min="0" name="tax_defaults[mva_rate]" value="{{ data_get($taxDefaults,'mva_rate') }}"></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>PIS / COFINS / IPI / ISS</h3>
     <div class="editor-grid cols-12">
       <label class="field col-2"><span>CST PIS</span><input name="tax_defaults[pis_cst]" value="{{ data_get($taxDefaults,'pis_cst',data_get($taxDefaults,'pis_cst_default')) }}" maxlength="4"></label>
       <label class="field col-2"><span>PIS %</span><input type="number" step="0.0001" min="0" name="tax_defaults[pis_rate]" value="{{ data_get($taxDefaults,'pis_rate') }}"></label>
       <label class="field col-2"><span>CST PIS entrada</span><input name="tax_defaults[pis_cst_inbound]" value="{{ data_get($taxDefaults,'pis_cst_inbound') }}" maxlength="4"></label>
       <label class="field col-2"><span>CST COFINS</span><input name="tax_defaults[cofins_cst]" value="{{ data_get($taxDefaults,'cofins_cst',data_get($taxDefaults,'cofins_cst_default')) }}" maxlength="4"></label>
       <label class="field col-2"><span>COFINS %</span><input type="number" step="0.0001" min="0" name="tax_defaults[cofins_rate]" value="{{ data_get($taxDefaults,'cofins_rate') }}"></label>
       <label class="field col-2"><span>CST COFINS entrada</span><input name="tax_defaults[cofins_cst_inbound]" value="{{ data_get($taxDefaults,'cofins_cst_inbound') }}" maxlength="4"></label>
       <label class="field col-2"><span>CST IPI</span><input name="tax_defaults[ipi_cst]" value="{{ data_get($taxDefaults,'ipi_cst',data_get($taxDefaults,'ipi_cst_default')) }}" maxlength="4"></label>
       <label class="field col-2"><span>IPI %</span><input type="number" step="0.0001" min="0" name="tax_defaults[ipi_rate]" value="{{ data_get($taxDefaults,'ipi_rate') }}"></label>
       <label class="field col-2"><span>CST IPI entrada</span><input name="tax_defaults[ipi_cst_inbound]" value="{{ data_get($taxDefaults,'ipi_cst_inbound') }}" maxlength="4"></label>

       <label class="field col-3"><span>Enquadramento IPI</span><input name="tax_defaults[ipi_enq]" value="{{ data_get($taxDefaults,'ipi_enq','999') }}" maxlength="10"></label>
       <label class="field col-3"><span>ISS %</span><input type="number" step="0.0001" min="0" name="tax_defaults[iss_rate]" value="{{ data_get($taxDefaults,'iss_rate',data_get($taxDefaults,'iss_rate_default')) }}"></label>
       <label class="field col-3"><span>Item lista de serviço</span><input name="tax_defaults[service_list_code]" value="{{ data_get($taxDefaults,'service_list_code') }}"></label>
       <label class="field col-3"><span>Qtd. tributável por unidade</span><input type="number" step="0.0001" min="0" name="tax_defaults[tax_quantity_factor]" value="{{ data_get($taxDefaults,'tax_quantity_factor',1) }}"></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>DIFAL / FCP</h3>
     <div class="editor-grid cols-12">
       <label class="field col-4"><span>ICMS interestadual %</span><input type="number" step="0.0001" min="0" name="tax_defaults[interstate_icms_rate]" value="{{ data_get($taxDefaults,'interstate_icms_rate') }}"></label>
       <label class="field col-4"><span>ICMS interno destino %</span><input type="number" step="0.0001" min="0" name="tax_defaults[internal_icms_rate]" value="{{ data_get($taxDefaults,'internal_icms_rate') }}"></label>
       <label class="field col-4"><span>FCP interestadual %</span><input type="number" step="0.0001" min="0" name="tax_defaults[fcp_interstate_rate]" value="{{ data_get($taxDefaults,'fcp_interstate_rate',data_get($taxDefaults,'fcp_rate_default')) }}"></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>Combustível / ANP</h3>
     <div class="editor-grid cols-12">
       <div class="field col-3"><span>Derivado de petróleo?</span>
         <label class="switch-field">
           <input type="hidden" name="tax_defaults[petroleum_derived]" value="0">
           <input type="checkbox" name="tax_defaults[petroleum_derived]" value="1" @checked(old('tax_defaults.petroleum_derived',data_get($taxDefaults,'petroleum_derived')))>
           <span class="switch-track"></span><strong data-switch-label>Sim</strong>
         </label>
       </div>
       <label class="field col-3"><span>Código ANP</span><input name="tax_defaults[anp_code]" value="{{ data_get($taxDefaults,'anp_code') }}"></label>
       <label class="field col-6"><span>Descrição ANP</span><input name="tax_defaults[anp_description]" value="{{ data_get($taxDefaults,'anp_description') }}"></label>
       <label class="field col-3"><span>% GLP</span><input type="number" step="0.0001" min="0" name="tax_defaults[glp_rate]" value="{{ data_get($taxDefaults,'glp_rate') }}"></label>
       <label class="field col-3"><span>% GNn</span><input type="number" step="0.0001" min="0" name="tax_defaults[gnn_rate]" value="{{ data_get($taxDefaults,'gnn_rate') }}"></label>
       <label class="field col-3"><span>% GNi</span><input type="number" step="0.0001" min="0" name="tax_defaults[gni_rate]" value="{{ data_get($taxDefaults,'gni_rate') }}"></label>
       <label class="field col-3"><span>Valor de partida</span><input type="number" step="0.0001" min="0" name="tax_defaults[starting_value]" value="{{ data_get($taxDefaults,'starting_value') }}"></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>IBS / CBS</h3>
     <div class="editor-grid cols-12">
       <label class="field col-3"><span>CST IBS</span><input name="tax_defaults[ibs_cst]" value="{{ data_get($taxDefaults,'ibs_cst',data_get($taxDefaults,'ibs_cst_default')) }}"></label>
       <label class="field col-3"><span>CST CBS</span><input name="tax_defaults[cbs_cst]" value="{{ data_get($taxDefaults,'cbs_cst',data_get($taxDefaults,'cbs_cst_default')) }}"></label>
       <label class="field col-6"><span>Classificação tributária</span><input name="tax_defaults[tax_classification_code]" value="{{ data_get($taxDefaults,'tax_classification_code') }}"></label>
     </div>
   </div>

   <div class="editor-panel">
     <label class="field"><span>Anotações para Nota Fiscal eletrônica</span><textarea name="nfe_notes" rows="3" placeholder="Informações adicionais para NF-e">{{ old('nfe_notes',$product->nfe_notes) }}</textarea></label>
   </div>

   <div class="editor-panel tax-group-panel">
     <label class="field"><span>Grupo tributário vinculado</span><input name="tax_group" value="{{ old('tax_group',$product->tax_group) }}" placeholder="Ex.: Revenda — Simples Nacional"></label>
   </div>
 </section>

 <section class="editor-tab-panel" data-tab-panel="product-media" hidden>
   <div class="editor-panel">
     <h3>Foto do produto</h3>
     <div class="product-media-layout">
       <div class="product-photo-preview" data-product-photo-preview>
         @if($product->image_path)
           <img src="{{ asset('storage/'.$product->image_path) }}" alt="{{ $product->name }}">
         @else
           @include('partials.icon',['name'=>'products','size'=>38])
           <span>Nenhuma foto cadastrada</span>
         @endif
       </div>
       <div class="product-photo-fields">
         <label class="field"><span>Selecionar imagem</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-product-image></label>
         <p class="editor-help">JPG, PNG ou WebP. Máximo de 4 MB.</p>
       </div>
     </div>
   </div>

   <div class="editor-panel">
     <h3>Integração</h3>
     <div class="editor-grid cols-12">
       <label class="field col-6"><span>Referência externa</span><input name="integration_reference" value="{{ old('integration_reference',$product->integration_reference) }}" placeholder="Código no sistema externo"></label>
       <label class="field col-6"><span>SKU de integração</span><input name="integration_sku" value="{{ old('integration_sku',$product->integration_sku) }}" placeholder="SKU utilizado na integração"></label>
     </div>
   </div>
 </section>

 <div class="editor-savebar">
   <button class="btn btn-success" type="submit">Salvar</button>
   <a class="btn btn-secondary" href="{{ route('products.index') }}">Cancelar</a>
 </div>
</form>
@endsection
