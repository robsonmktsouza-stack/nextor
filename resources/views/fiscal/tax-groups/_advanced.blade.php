@php
  $ufs=['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];
  $municipalVariations=old('tax_config.municipal_variations',data_get($group->tax_config,'municipal_variations',[]));
  $stateVariations=old('tax_config.state_variations',data_get($group->tax_config,'state_variations',[]));
@endphp

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>CFOP e benefício fiscal</h2></div></div>
  <div class="editor-grid cols-12 settings-grid">
    <label class="field col-3"><span>Código de benefício fiscal na UF</span>
      <input maxlength="20" name="tax_config[fiscal_benefit_code]" value="{{ $val('fiscal_benefit_code') }}" placeholder="cBenef">
    </label>
    <label class="field col-3"><span>CFOP fora da UF</span>
      <input maxlength="4" inputmode="numeric" name="tax_config[cfop_interstate]" value="{{ $val('cfop_interstate') }}" placeholder="6xxx">
    </label>
    <div class="field col-6"><span>Comportamento interestadual</span>
      <label class="settings-switch"><input type="checkbox" name="tax_config[force_interstate_cfop]" value="1" @checked($val('force_interstate_cfop'))>
        <span><strong>Forçar CFOP fora da UF</strong></span>
      </label>
    </div>
  </div>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>FCP</h2></div></div>
  <div class="editor-grid cols-12 settings-grid">
    <label class="field col-3"><span>Alíquota de diferimento FCP (%)</span>
      <input type="number" name="tax_config[fcp_deferral_rate]" step="0.0001" min="0" max="100" value="{{ $val('fcp_deferral_rate') }}">
    </label>
    <div class="field col-6"><span>Tabela por UF / NCM</span>
      <a href="{{ route('fiscal.fcp.index') }}" class="btn btn-secondary">@include('partials.icon',['name'=>'layers','size'=>16]) Abrir tabela FCP</a>
    </div>
  </div>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>Tipos de cálculo — PIS / COFINS</h2></div></div>
  <div class="editor-grid cols-12 settings-grid">
    @foreach(['pis'=>'PIS','cofins'=>'COFINS'] as $prefix=>$label)
      <label class="field col-3"><span>Tipo de cálculo {{ $label }}</span>
        <select name="tax_config[{{ $prefix }}_calc_type]">
          <option value="">Não configurado</option>
          @foreach(['none'=>'Não usar','percentage'=>'Porcentagem','quantity'=>'Por quantidade'] as $key=>$description)
            <option value="{{ $key }}" @selected($val($prefix.'_calc_type')===$key)>{{ $description }}</option>
          @endforeach
        </select>
      </label>
      <label class="field col-3"><span>Tipo de cálculo ST — {{ $label }}</span>
        <select name="tax_config[{{ $prefix }}_st_calc_type]">
          <option value="">Não configurado</option>
          @foreach(['none'=>'Não usar','percentage'=>'Porcentagem','quantity'=>'Por quantidade'] as $key=>$description)
            <option value="{{ $key }}" @selected($val($prefix.'_st_calc_type')===$key)>{{ $description }}</option>
          @endforeach
        </select>
      </label>
      <label class="field col-3"><span>Alíquota ST {{ $label }} (%)</span>
        <input type="number" name="tax_config[{{ $prefix }}_st_rate]" step="0.0001" min="0" max="100" value="{{ $val($prefix.'_st_rate') }}">
      </label>
      <label class="field col-3"><span>Alíquota por quantidade {{ $label }}</span>
        <input type="number" name="tax_config[{{ $prefix }}_quantity_rate]" step="0.0001" min="0" max="100" value="{{ $val($prefix.'_quantity_rate') }}">
      </label>
    @endforeach
  </div>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>Diferimentos e reduções — IBS / CBS</h2></div></div>
  <div class="editor-grid cols-12 settings-grid">
    @foreach([
      ['cbs_deferral_rate','Diferimento CBS (%)'],
      ['ibs_uf_deferral_rate','Diferimento IBS UF (%)'],
      ['ibs_uf_reduction_rate','Redução IBS UF (%)'],
      ['ibs_municipal_deferral_rate','Diferimento IBS municipal (%)'],
      ['ibs_municipal_reduction_rate','Redução IBS municipal (%)'],
    ] as [$key,$label])
      <label class="field col-3"><span>{{ $label }}</span>
        <input type="number" name="tax_config[{{ $key }}]" step="0.0001" min="0" max="100" value="{{ $val($key) }}">
      </label>
    @endforeach
  </div>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>IBS municipal — exceções por município</h2></div></div>
  <div data-variation-list="municipal">
    @foreach($municipalVariations as $rowIndex=>$row)
      <div class="editor-grid cols-12 settings-grid" data-variation-row="municipal" style="margin-bottom:10px">
        <label class="field col-3"><span>Município (IBGE)</span><input name="tax_config[municipal_variations][{{ $rowIndex }}][city_ibge]" value="{{ $row['city_ibge'] ?? '' }}" maxlength="7" inputmode="numeric" required placeholder="7 dígitos"></label>
        <label class="field col-2"><span>IBS municipal (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[municipal_variations][{{ $rowIndex }}][rate]" value="{{ $row['rate'] ?? '' }}"></label>
        <label class="field col-2"><span>Diferimento (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[municipal_variations][{{ $rowIndex }}][deferral_rate]" value="{{ $row['deferral_rate'] ?? '' }}"></label>
        <label class="field col-2"><span>Redução (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[municipal_variations][{{ $rowIndex }}][reduction_rate]" value="{{ $row['reduction_rate'] ?? '' }}"></label>
        <div class="field col-3"><span>Ação</span><button class="btn btn-secondary" type="button" data-remove-variation>Remover município</button></div>
      </div>
    @endforeach
  </div>
  <button type="button" class="btn btn-secondary" data-add-variation="municipal">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar município</button>
  <template data-variation-template="municipal">
    <div class="editor-grid cols-12 settings-grid" data-variation-row="municipal" style="margin-bottom:10px">
      <label class="field col-3"><span>Município (IBGE)</span><input name="tax_config[municipal_variations][__INDEX__][city_ibge]" maxlength="7" inputmode="numeric" required placeholder="7 dígitos"></label>
      <label class="field col-2"><span>IBS municipal (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[municipal_variations][__INDEX__][rate]"></label>
      <label class="field col-2"><span>Diferimento (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[municipal_variations][__INDEX__][deferral_rate]"></label>
      <label class="field col-2"><span>Redução (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[municipal_variations][__INDEX__][reduction_rate]"></label>
      <div class="field col-3"><span>Ação</span><button class="btn btn-secondary" type="button" data-remove-variation>Remover município</button></div>
    </div>
  </template>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>Variações por estado</h2></div></div>
  <div data-variation-list="state">
    @foreach($stateVariations as $rowIndex=>$row)
      <div class="editor-grid cols-12 settings-grid" data-variation-row="state" style="margin-bottom:10px">
        <label class="field col-2"><span>Destino UF</span><select required name="tax_config[state_variations][{{ $rowIndex }}][uf]"><option value="">UF</option>
          @foreach($ufs as $uf)<option value="{{ $uf }}" @selected(($row['uf'] ?? '')===$uf)>{{ $uf }}</option>@endforeach
        </select></label>
        <label class="field col-2"><span>CFOP específico</span><input name="tax_config[state_variations][{{ $rowIndex }}][cfop]" maxlength="4" value="{{ $row['cfop'] ?? '' }}" placeholder="6xxx"></label>
        <label class="field col-2"><span>CBS (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][{{ $rowIndex }}][cbs_rate]" value="{{ $row['cbs_rate'] ?? '' }}"></label>
        <label class="field col-2"><span>IBS UF (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][{{ $rowIndex }}][ibs_uf_rate]" value="{{ $row['ibs_uf_rate'] ?? '' }}"></label>
        <label class="field col-2"><span>Diferimento IBS (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][{{ $rowIndex }}][ibs_uf_deferral_rate]" value="{{ $row['ibs_uf_deferral_rate'] ?? '' }}"></label>
        <label class="field col-2"><span>Redução IBS (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][{{ $rowIndex }}][ibs_uf_reduction_rate]" value="{{ $row['ibs_uf_reduction_rate'] ?? '' }}"></label>
        <div class="field col-2"><button type="button" class="btn btn-secondary" data-remove-variation>Remover</button></div>
      </div>
    @endforeach
  </div>
  <button type="button" class="btn btn-secondary" data-add-variation="state">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar variação por UF</button>
  <template data-variation-template="state">
    <div class="editor-grid cols-12 settings-grid" data-variation-row="state" style="margin-bottom:10px">
      <label class="field col-2"><span>Destino UF</span><select required name="tax_config[state_variations][__INDEX__][uf]"><option value="">UF</option>
        @foreach($ufs as $uf)<option value="{{ $uf }}">{{ $uf }}</option>@endforeach
      </select></label>
      <label class="field col-2"><span>CFOP específico</span><input name="tax_config[state_variations][__INDEX__][cfop]" maxlength="4" placeholder="6xxx"></label>
      <label class="field col-2"><span>CBS (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][__INDEX__][cbs_rate]"></label>
      <label class="field col-2"><span>IBS UF (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][__INDEX__][ibs_uf_rate]"></label>
      <label class="field col-2"><span>Diferimento IBS (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][__INDEX__][ibs_uf_deferral_rate]"></label>
      <label class="field col-2"><span>Redução IBS (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[state_variations][__INDEX__][ibs_uf_reduction_rate]"></label>
      <div class="field col-2"><button type="button" class="btn btn-secondary" data-remove-variation>Remover</button></div>
    </div>
  </template>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>Combustíveis — ANP</h2></div></div>
  <div class="editor-grid cols-12 settings-grid">
    <label class="field col-3"><span>Código ANP</span><input name="tax_config[anp_code]" maxlength="12" inputmode="numeric" value="{{ $val('anp_code') }}" placeholder="Código oficial"></label>
    <label class="field col-6"><span>Descrição conforme ANP</span><input name="tax_config[anp_description]" maxlength="190" value="{{ $val('anp_description') }}"></label>
    <label class="field col-3"><span>Índice mistura biodiesel (%)</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[biodiesel_mix_rate]" value="{{ $val('biodiesel_mix_rate') }}"></label>
    <label class="field col-3"><span>Indicador de origem</span><input name="tax_config[fuel_origin_indicator]" maxlength="2" value="{{ $val('fuel_origin_indicator') }}"></label>
    <label class="field col-3"><span>UF produtor / importador</span><select name="tax_config[fuel_origin_uf]"><option value="">Não informado</option>
      @foreach($ufs as $uf)<option value="{{ $uf }}" @selected($val('fuel_origin_uf')===$uf)>{{ $uf }}</option>@endforeach
    </select></label>
    <label class="field col-3"><span>Percentual originário para a UF (%)</span><input type="number" min="0" max="100" step="0.0001" name="tax_config[fuel_origin_rate]" value="{{ $val('fuel_origin_rate') }}"></label>
  </div>
</section>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head"><div><h2>Serviços — ISS</h2></div></div>
  <div class="editor-grid cols-12 settings-grid">
    <div class="field col-4"><span>Incentivo fiscal</span>
      <label class="settings-switch"><input type="checkbox" name="tax_config[iss_incentive]" value="1" @checked($val('iss_incentive'))>
        <span><strong>Possui incentivo fiscal</strong></span>
      </label>
    </div>
  </div>
</section>

<script>
document.addEventListener('click',function(event){
  const remove=event.target.closest('[data-remove-variation]');
  if(remove){
    remove.closest('[data-variation-row]')?.remove();
    return;
  }
  const add=event.target.closest('[data-add-variation]');
  if(!add)return;
  const type=add.getAttribute('data-add-variation');
  const list=document.querySelector('[data-variation-list="'+type+'"]');
  const template=document.querySelector('[data-variation-template="'+type+'"]');
  if(!list||!template)return;
  const limit=type==='state'?27:100;
  if(list.children.length>=limit)return;
  const next=parseInt(list.dataset.nextIndex||'0',10);
  const candidate=Math.max(next,...Array.from(list.querySelectorAll('[name]')).map(input=>{
    const match=input.name.match(/\[(\d+)\]/);
    return match?Number(match[1])+1:0;
  }));
  const wrapper=document.createElement('div');
  wrapper.innerHTML=template.innerHTML.replaceAll('__INDEX__',String(candidate));
  while(wrapper.firstElementChild)list.appendChild(wrapper.firstElementChild);
  list.dataset.nextIndex=String(candidate+1);
});
</script>