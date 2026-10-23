@php
$quickLicense=(array)request()->attributes->get('lumeron_commercial_license',[]);
$quickCanWrite=!(bool)($quickLicense['readonly']??false);
$quickCanProducts=$quickCanWrite && auth()->user()->canAccess('products')
    && \App\Support\CommercialModuleAccess::visible($quickLicense,'products');
$quickCanCustomers=$quickCanWrite && auth()->user()->canAccess('customers');
@endphp
<dialog id="erpCatalogDialog" class="erp-catalog-dialog" aria-labelledby="erpCatalogTitle" data-catalog-products="{{ $quickCanProducts?'1':'0' }}" data-catalog-customers="{{ $quickCanCustomers?'1':'0' }}"
 data-search-url="{{ url('/quick-catalog') }}" data-store-url="{{ url('/quick-catalog') }}">
  <div class="erp-catalog-header">
    <div><h2 id="erpCatalogTitle">Selecionar</h2><p id="erpCatalogSubtitle"></p></div>
    <button type="button" class="erp-catalog-close" data-catalog-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </div>
  <section class="erp-catalog-main">
    <div id="erpCatalogPicker" class="erp-catalog-picker">
      <label class="erp-catalog-field"><span>Pesquisar por nome ou código</span>
        <input id="erpCatalogSearch" type="search" autocomplete="off" placeholder="Digite para pesquisar...">
      </label>
      <div id="erpCatalogResults" class="erp-catalog-results" role="listbox" aria-label="Resultados"></div>
      <button type="button" class="erp-catalog-new" id="erpCatalogNew">
        @include('partials.icon',['name'=>'plus','size'=>16]) <span id="erpCatalogNewLabel">Cadastrar novo</span>
      </button>
    </div>
    <form id="erpCatalogCreate" class="erp-catalog-form" novalidate hidden>
      <p class="erp-catalog-hint" id="erpCatalogHint"></p>
      <div id="erpCatalogFields" class="erp-catalog-fields"></div>
      <div class="erp-catalog-error" role="alert" id="erpCatalogError" hidden></div>
      <div class="erp-catalog-footer">
        <button type="button" class="btn btn-secondary" id="erpCatalogBack">Voltar</button>
        <button type="submit" class="btn btn-success" id="erpCatalogSave">@include('partials.icon',['name'=>'check','size'=>15]) Salvar cadastro</button>
      </div>
    </form>
  </section>
</dialog>
