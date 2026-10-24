@php
$quickLicense=(array)request()->attributes->get('lumeron_commercial_license',[]);
$quickCanWrite=!(bool)($quickLicense['readonly']??false);
$quickCanProducts=$quickCanWrite && auth()->user()->canAccess('products')
    && \App\Support\CommercialModuleAccess::visible($quickLicense,'products');
$quickCanCustomers=$quickCanWrite && auth()->user()->canAccess('customers');
@endphp
<dialog id="erpCatalogDialog" class="erp-dialog erp-catalog-dialog" aria-labelledby="erpCatalogTitle"
 data-catalog-products="{{ $quickCanProducts?'1':'0' }}" data-catalog-customers="{{ $quickCanCustomers?'1':'0' }}"
 data-search-url="{{ url('/quick-catalog') }}" data-store-url="{{ url('/quick-catalog') }}">
  <header class="dialog-header erp-catalog-header">
    <div>
      <h2 id="erpCatalogTitle">Selecionar cadastro</h2>
      <p id="erpCatalogSubtitle">Pesquise para selecionar ou cadastrar.</p>
    </div>
    <button type="button" class="close-dialog erp-catalog-close" data-catalog-close aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</button>
  </header>
  <div class="erp-catalog-body">
    <section id="erpCatalogPicker" class="erp-catalog-picker">
      <label class="field erp-catalog-field">
        <span>Nome ou código</span>
        <input id="erpCatalogSearch" type="search" autocomplete="off" placeholder="Digite para pesquisar...">
      </label>
      <div id="erpCatalogResults" class="erp-catalog-results" role="listbox" aria-label="Resultados"></div>
      <div class="erp-catalog-picker-actions">
        <button type="button" class="erp-catalog-new" id="erpCatalogNew">
          @include('partials.icon',['name'=>'plus','size'=>15]) <span id="erpCatalogNewLabel">Novo cadastro</span>
        </button>
      </div>
    </section>
    <form id="erpCatalogCreate" class="erp-catalog-form" hidden>
      <div id="erpCatalogFields" class="erp-catalog-fields"></div>
      <div class="erp-catalog-error" role="alert" id="erpCatalogError" hidden></div>
    </form>
  </div>
  <footer class="dialog-footer erp-catalog-footer">
    <button type="button" class="btn btn-secondary" id="erpCatalogBack" hidden>Voltar à pesquisa</button>
    <button type="button" class="btn btn-secondary" data-catalog-close>Cancelar</button>
    <button type="submit" class="btn btn-success" id="erpCatalogSave" form="erpCatalogCreate" hidden>Salvar cadastro</button>
  </footer>
</dialog>
