@extends('layouts.app')
@section('title',$editing ? 'Editar serviço' : 'Novo serviço')

@section('content')
<form class="service-editor" method="post" action="{{ $editing ? route('services.update',$service) : route('services.store') }}">
  @csrf
  @if($editing) @method('PUT') @endif
  <input type="hidden" name="is_active" value="{{ old('is_active',$service->is_active ?? true) ? 1 : 0 }}">

  <div class="editor-tabs service-tabs" data-tabs>
    <button type="button" class="editor-tab active" data-tab-target="service-data">Dados do serviço</button>
    <button type="button" class="editor-tab" data-tab-target="service-fiscal">Dados fiscais</button>
  </div>

  <section class="editor-tab-panel active" data-tab-panel="service-data">
    <div class="editor-panel">
      <div class="editor-grid cols-12">
        <label class="field col-12">
          <span>Nome *</span>
          <input name="name" value="{{ old('name',$service->name) }}" maxlength="190" required autofocus>
        </label>

        <label class="field col-6">
          <span>Preço de venda *</span>
          <input type="number" step="0.01" min="0" name="sale_price"
                 value="{{ old('sale_price',$service->sale_price ?? 0) }}" required data-number-kind="money">
        </label>

        <label class="field col-6">
          <span>Palavras-chave</span>
          <div class="input-icon-group">
            <span class="input-icon">@include('partials.icon',['name'=>'tag','size'=>17])</span>
            <input name="keywords" value="{{ old('keywords',$service->keywords) }}" maxlength="500">
          </div>
        </label>
      </div>
    </div>

    <div class="editor-panel notes-panel">
      <label class="field">
        <span>Anotações internas</span>
        <textarea name="notes" rows="4">{{ old('notes',$service->notes) }}</textarea>
      </label>
    </div>
  </section>

  <section class="editor-tab-panel" data-tab-panel="service-fiscal" hidden>
    <div class="editor-panel">
      <div class="editor-grid cols-12">
        <label class="field col-4">
          <span>Item lista serviço</span>
          <input name="service_list_item" value="{{ old('service_list_item',$service->service_list_item) }}"
                 maxlength="20" placeholder="Ex.: 14.01">
        </label>

        <label class="field col-4">
          <span>CNAE</span>
          <input name="cnae" value="{{ old('cnae',$service->cnae) }}" maxlength="12" placeholder="Ex.: 6201-5/01">
        </label>

        <label class="field col-4">
          <span>Código de tributação municipal</span>
          <input name="municipal_tax_code" value="{{ old('municipal_tax_code',$service->municipal_tax_code) }}" maxlength="40">
        </label>

        <label class="field col-4">
          <span>Código de tributação nacional</span>
          <input name="national_tax_code" value="{{ old('national_tax_code',$service->national_tax_code) }}" maxlength="40">
        </label>

        <label class="field col-4">
          <span>Código NBS</span>
          <input name="nbs" value="{{ old('nbs',$service->nbs) }}" maxlength="20">
        </label>
      </div>
    </div>

    <div class="editor-panel tax-group-panel">
      <label class="field">
        <span>Grupo tributário vinculado</span>
        <input name="tax_group" value="{{ old('tax_group',$service->tax_group) }}"
               maxlength="120" placeholder="Ex.: Serviços — Simples Nacional">
      </label>
    </div>
  </section>

  <div class="editor-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
    <a class="btn btn-secondary" href="{{ route('services.index') }}">Cancelar</a>
  </div>
</form>
@endsection
