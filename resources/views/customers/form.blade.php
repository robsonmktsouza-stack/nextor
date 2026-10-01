@extends('layouts.app')
@section('title',$editing ? 'Editar cliente' : 'Novo cliente')

@section('content')
@php
$ufs=[
 'AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal',
 'ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul',
 'MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí',
 'RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima',
 'SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'
];
$deliveryAddresses=collect(old('delivery_addresses',$customer->exists ? $customer->deliveryAddresses->toArray() : []));
@endphp

<form class="customer-editor" method="post" action="{{ $editing ? route('customers.update',$customer) : route('customers.store') }}">
 @csrf
 @if($editing) @method('PUT') @endif

 <div class="editor-tabs" data-tabs>
   <button type="button" class="editor-tab active" data-tab-target="customer-data">Dados</button>
   <button type="button" class="editor-tab" data-tab-target="customer-address">Endereço</button>
   <button type="button" class="editor-tab" data-tab-target="customer-extra">Informações adicionais</button>
 </div>

 <section class="editor-tab-panel active" data-tab-panel="customer-data">
   <div class="customer-data-layout">
     <aside class="contact-types">
       <h3>Tipo de contato</h3>
       <label class="check-option"><input type="hidden" name="is_customer" value="0"><input type="checkbox" name="is_customer" value="1" @checked(old('is_customer',$customer->is_customer ?? true))><span>Cliente</span></label>
       <label class="check-option"><input type="hidden" name="is_supplier" value="0"><input type="checkbox" name="is_supplier" value="1" @checked(old('is_supplier',$customer->is_supplier))><span>Fornecedor</span></label>
       <label class="check-option"><input type="hidden" name="is_carrier" value="0"><input type="checkbox" name="is_carrier" value="1" @checked(old('is_carrier',$customer->is_carrier))><span>Transportador</span></label>
     </aside>

     <div class="editor-panel">
       <h3>Dados gerais</h3>
       <div class="editor-grid cols-12">
         <label class="field col-12"><span>Nome / Razão social *</span><input name="name" value="{{ old('name',$customer->name) }}" required autofocus></label>

         <label class="field col-5"><span>CPF / CNPJ</span>
           <div class="input-action-group">
             <input name="document" value="{{ old('document',$customer->document) }}" maxlength="20" data-cnpj-document>
             <button type="button" class="input-action-button" data-cnpj-autofill>@include('partials.icon',['name'=>'search','size'=>15]) <span>Autopreencher</span></button>
           </div>
         </label>
         <label class="field col-7"><span>Nome fantasia</span><input name="trade_name" value="{{ old('trade_name',$customer->trade_name) }}"></label>

         <label class="field col-6"><span>Nome do contato</span><input name="contact_name" value="{{ old('contact_name',$customer->contact_name) }}"></label>
         <label class="field col-6"><span>Telefone(s)</span><input name="phone" value="{{ old('phone',$customer->phone) }}" maxlength="25"></label>

         <label class="field col-12"><span>E-mail(s)</span><input name="email" type="email" value="{{ old('email',$customer->email) }}"></label>
       </div>
     </div>
   </div>
 </section>

 <section class="editor-tab-panel" data-tab-panel="customer-address" hidden>
   <div class="editor-panel">
     <div class="editor-grid cols-12" data-address-scope>
       <label class="field col-3"><span>CEP</span>
         <div class="input-action-group compact-action">
           <input name="zip_code" value="{{ old('zip_code',$customer->zip_code) }}" maxlength="10" data-cep-input>
           <button type="button" class="input-action-button" data-cep-search data-tooltip="Buscar CEP">@include('partials.icon',['name'=>'search','size'=>15])</button>
         </div>
       </label>
       <label class="field col-3"><span>Estado</span>
         <select name="state" data-uf-select>
           <option value="">Selecione</option>
           @foreach($ufs as $uf=>$label)<option value="{{ $uf }}" @selected(old('state',$customer->state)===$uf)>{{ $uf }} — {{ $label }}</option>@endforeach
         </select>
       </label>
       <label class="field col-6"><span>Cidade</span>
         <select name="city" data-city-select data-current-city="{{ old('city',$customer->city) }}">
           <option value="{{ old('city',$customer->city) }}">{{ old('city',$customer->city) ?: 'Selecione o estado' }}</option>
         </select>
       </label>

       <label class="field col-10"><span>Endereço</span><input name="address" value="{{ old('address',$customer->address) }}" data-address-input></label>
       <label class="field col-2"><span>Número</span><input name="address_number" value="{{ old('address_number',$customer->address_number) }}"></label>
       <label class="field col-4"><span>Complemento</span><input name="address_complement" value="{{ old('address_complement',$customer->address_complement) }}"></label>
       <label class="field col-8"><span>Bairro</span><input name="district" value="{{ old('district',$customer->district) }}" data-district-input></label>
     </div>
   </div>

   <div class="delivery-toolbar">
     <button type="button" class="btn btn-success" data-add-delivery>@include('partials.icon',['name'=>'plus','size'=>16]) Adicionar endereço de entrega</button>
   </div>

   <div class="delivery-addresses" data-delivery-list>
     @foreach($deliveryAddresses as $index=>$address)
       <div class="editor-panel delivery-card" data-delivery-card>
         <div class="delivery-card-title"><h3>Endereço de entrega</h3><button type="button" class="remove-delivery" data-remove-delivery aria-label="Remover endereço">@include('partials.icon',['name'=>'x','size'=>16])</button></div>
         <div class="editor-grid cols-12" data-address-scope>
           <label class="field col-3"><span>CEP</span><div class="input-action-group compact-action"><input name="delivery_addresses[{{ $index }}][zip_code]" value="{{ $address['zip_code'] ?? '' }}" data-cep-input><button type="button" class="input-action-button" data-cep-search>@include('partials.icon',['name'=>'search','size'=>15])</button></div></label>
           <label class="field col-3"><span>Estado</span><select name="delivery_addresses[{{ $index }}][state]" data-uf-select><option value="">Selecione</option>@foreach($ufs as $uf=>$label)<option value="{{ $uf }}" @selected(($address['state'] ?? '')===$uf)>{{ $uf }} — {{ $label }}</option>@endforeach</select></label>
           <label class="field col-6"><span>Cidade</span><select name="delivery_addresses[{{ $index }}][city]" data-city-select data-current-city="{{ $address['city'] ?? '' }}"><option value="{{ $address['city'] ?? '' }}">{{ $address['city'] ?? 'Selecione o estado' }}</option></select></label>

           <label class="field col-5"><span>Nome ou Razão social</span><input name="delivery_addresses[{{ $index }}][name]" value="{{ $address['name'] ?? '' }}"></label>
           <label class="field col-4"><span>CPF/CNPJ destinatário</span><input name="delivery_addresses[{{ $index }}][document]" value="{{ $address['document'] ?? '' }}"></label>
           <label class="field col-3"><span>Inscrição Estadual</span><input name="delivery_addresses[{{ $index }}][state_registration]" value="{{ $address['state_registration'] ?? '' }}"></label>

           <label class="field col-10"><span>Endereço</span><input name="delivery_addresses[{{ $index }}][address]" value="{{ $address['address'] ?? '' }}" data-address-input></label>
           <label class="field col-2"><span>Número</span><input name="delivery_addresses[{{ $index }}][address_number]" value="{{ $address['address_number'] ?? '' }}"></label>
           <label class="field col-6"><span>Complemento</span><input name="delivery_addresses[{{ $index }}][address_complement]" value="{{ $address['address_complement'] ?? '' }}"></label>
           <label class="field col-6"><span>Bairro</span><input name="delivery_addresses[{{ $index }}][district]" value="{{ $address['district'] ?? '' }}" data-district-input></label>
           <label class="field col-6"><span>E-mail(s)</span><input type="email" name="delivery_addresses[{{ $index }}][email]" value="{{ $address['email'] ?? '' }}"></label>
           <label class="field col-6"><span>Fone(s)</span><input name="delivery_addresses[{{ $index }}][phone]" value="{{ $address['phone'] ?? '' }}"></label>
         </div>
       </div>
     @endforeach
   </div>
 </section>

 <section class="editor-tab-panel" data-tab-panel="customer-extra" hidden>
   <div class="editor-panel">
     <div class="editor-grid cols-12">
       <div class="field col-3"><span>Cliente final?</span>
         <label class="switch-field"><input type="hidden" name="final_consumer" value="0"><input type="checkbox" name="final_consumer" value="1" @checked(old('final_consumer',$customer->final_consumer))><span class="switch-track"></span><strong data-switch-label>{{ old('final_consumer',$customer->final_consumer) ? 'Sim' : 'Não' }}</strong></label>
       </div>
       <label class="field col-4"><span>Indicador de IE</span>
         <select name="ie_indicator">
           <option value="non_contributor" @selected(old('ie_indicator',$customer->ie_indicator)==='non_contributor')>Não contribuinte</option>
           <option value="contributor" @selected(old('ie_indicator',$customer->ie_indicator)==='contributor')>Contribuinte ICMS</option>
           <option value="exempt" @selected(old('ie_indicator',$customer->ie_indicator)==='exempt')>Contribuinte isento</option>
         </select>
       </label>
       <label class="field col-5"><span>Inscrição Estadual</span><input name="state_registration" value="{{ old('state_registration',$customer->state_registration) }}"></label>

       <label class="field col-3"><span>IE Subst. Trib.</span><input name="substitute_state_registration" value="{{ old('substitute_state_registration',$customer->substitute_state_registration) }}"></label>
       <label class="field col-3"><span>Inscrição Municipal</span><input name="municipal_registration" value="{{ old('municipal_registration',$customer->municipal_registration) }}"></label>
       <label class="field col-3"><span>Suframa</span><input name="suframa" value="{{ old('suframa',$customer->suframa) }}"></label>
       <label class="field col-3"><span>Ente governamental</span>
         <select name="government_entity">
           <option value=""></option>
           <option value="union" @selected(old('government_entity',$customer->government_entity)==='union')>União</option>
           <option value="state" @selected(old('government_entity',$customer->government_entity)==='state')>Estado</option>
           <option value="federal_district" @selected(old('government_entity',$customer->government_entity)==='federal_district')>Distrito Federal</option>
           <option value="municipality" @selected(old('government_entity',$customer->government_entity)==='municipality')>Município</option>
           <option value="other" @selected(old('government_entity',$customer->government_entity)==='other')>Outros</option>
         </select>
       </label>

       <label class="field col-3"><span>RNTRC</span><input name="rntrc" value="{{ old('rntrc',$customer->rntrc) }}"></label>
       <label class="field col-3"><span>Tipo de transportador (MDF-e)</span>
         <select name="carrier_type">
           <option value="">Não informado</option>
           <option value="tac_independent" @selected(old('carrier_type',$customer->carrier_type)==='tac_independent')>TAC independente</option>
           <option value="tac_aggregated" @selected(old('carrier_type',$customer->carrier_type)==='tac_aggregated')>TAC agregado</option>
           <option value="ctc" @selected(old('carrier_type',$customer->carrier_type)==='ctc')>Empresa de transporte</option>
         </select>
       </label>
       <label class="field col-3"><span>CNH (motorista)</span><input name="driver_license" value="{{ old('driver_license',$customer->driver_license) }}"></label>
     </div>
   </div>

   <div class="editor-panel">
     <h3>Outras informações</h3>
     <div class="editor-grid cols-12">
       <label class="field col-4"><span>Data de nascimento</span><input type="date" name="birth_date" value="{{ old('birth_date',$customer->birth_date?->format('Y-m-d')) }}"></label>
       <label class="field col-8"><span>Palavras-chave</span><input name="keywords" value="{{ old('keywords',$customer->keywords) }}"></label>
       <label class="field col-4"><span>Data comemorativa</span><input type="date" name="celebration_date" value="{{ old('celebration_date',$customer->celebration_date?->format('Y-m-d')) }}"></label>
       <label class="field col-8"><span>Descrição curta da comemoração</span><input name="celebration_note" value="{{ old('celebration_note',$customer->celebration_note) }}"></label>
       <label class="field col-4"><span>Bases legais (LGPD)</span>
         <select name="lgpd_legal_basis">
           <option value="default" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='default')>Utilizar configuração padrão</option>
           <option value="consent" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='consent')>Consentimento</option>
           <option value="legal_obligation" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='legal_obligation')>Obrigação Legal</option>
           <option value="public_policy" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='public_policy')>Políticas Públicas</option>
           <option value="research" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='research')>Pesquisa e estudo</option>
           <option value="contract" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='contract')>Execução contratual</option>
           <option value="legal_claims" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='legal_claims')>Exercício Regular de Direito em Processo</option>
           <option value="life_protection" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='life_protection')>Proteção à vida</option>
           <option value="health" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='health')>Tutela da Saúde</option>
           <option value="credit_protection" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='credit_protection')>Proteção ao Crédito</option>
           <option value="legitimate_interest" @selected(old('lgpd_legal_basis',$customer->lgpd_legal_basis)==='legitimate_interest')>Legítimo Interesse</option>
         </select>
       </label>
     </div>
   </div>

   <div class="editor-panel notes-panel">
     <label class="field"><span>Observações</span><textarea name="notes" rows="4">{{ old('notes',$customer->notes) }}</textarea></label>
   </div>
 </section>

 <div class="editor-savebar">
   <button class="btn btn-success" type="submit">Salvar</button>
   <a class="btn btn-secondary" href="{{ route('customers.index') }}">Cancelar</a>
 </div>
</form>

<template id="deliveryAddressTemplate">
 <div class="editor-panel delivery-card" data-delivery-card>
  <div class="delivery-card-title"><h3>Endereço de entrega</h3><button type="button" class="remove-delivery" data-remove-delivery aria-label="Remover endereço">@include('partials.icon',['name'=>'x','size'=>16])</button></div>
  <div class="editor-grid cols-12" data-address-scope>
   <label class="field col-3"><span>CEP</span><div class="input-action-group compact-action"><input name="delivery_addresses[__INDEX__][zip_code]" data-cep-input><button type="button" class="input-action-button" data-cep-search>@include('partials.icon',['name'=>'search','size'=>15])</button></div></label>
   <label class="field col-3"><span>Estado</span><select name="delivery_addresses[__INDEX__][state]" data-uf-select><option value="">Selecione</option>@foreach($ufs as $uf=>$label)<option value="{{ $uf }}">{{ $uf }} — {{ $label }}</option>@endforeach</select></label>
   <label class="field col-6"><span>Cidade</span><select name="delivery_addresses[__INDEX__][city]" data-city-select><option value="">Selecione o estado</option></select></label>
   <label class="field col-5"><span>Nome ou Razão social</span><input name="delivery_addresses[__INDEX__][name]"></label>
   <label class="field col-4"><span>CPF/CNPJ destinatário</span><input name="delivery_addresses[__INDEX__][document]"></label>
   <label class="field col-3"><span>Inscrição Estadual</span><input name="delivery_addresses[__INDEX__][state_registration]"></label>
   <label class="field col-10"><span>Endereço</span><input name="delivery_addresses[__INDEX__][address]" data-address-input></label>
   <label class="field col-2"><span>Número</span><input name="delivery_addresses[__INDEX__][address_number]"></label>
   <label class="field col-6"><span>Complemento</span><input name="delivery_addresses[__INDEX__][address_complement]"></label>
   <label class="field col-6"><span>Bairro</span><input name="delivery_addresses[__INDEX__][district]" data-district-input></label>
   <label class="field col-6"><span>E-mail(s)</span><input type="email" name="delivery_addresses[__INDEX__][email]"></label>
   <label class="field col-6"><span>Fone(s)</span><input name="delivery_addresses[__INDEX__][phone]"></label>
  </div>
 </div>
</template>
@endsection
