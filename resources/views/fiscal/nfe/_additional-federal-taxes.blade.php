@php
  $additionalFederalRuleMode = (bool) ($ruleMode ?? false);
  $additionalFederalParameters = is_array($federalParameters ?? null) ? $federalParameters : [];
@endphp
<div class="editor-grid cols-12 settings-grid {{ $additionalFederalRuleMode ? '' : 'nfe-item-additional-federal-grid' }}">
  @foreach(['pis'=>'PIS','cofins'=>'COFINS'] as $additionalFederalTax=>$additionalFederalLabel)
    @php
      $additionalFederalPrefix = $additionalFederalTax.'_st_';
      $additionalFederalId = 'nfeTax'.ucfirst($additionalFederalTax).'St';
      $additionalFederalMode = $additionalFederalParameters[$additionalFederalPrefix.'calculation_type'] ?? $additionalFederalParameters[$additionalFederalPrefix.'calc_type'] ?? '';
      if ($additionalFederalMode === 'percentage') $additionalFederalMode = 'percent';
    @endphp
    <label class="field col-2"><span>Cálculo {{ $additionalFederalLabel }}-ST</span>
      <select @if($additionalFederalRuleMode) name="tax_parameters[{{ $additionalFederalPrefix }}calculation_type]" @else id="{{ $additionalFederalId }}Mode" @endif>
        <option value="" @selected($additionalFederalMode==='')>Não informado</option>
        <option value="percent" @selected($additionalFederalMode==='percent')>Percentual</option>
        <option value="quantity" @selected($additionalFederalMode==='quantity')>Por quantidade</option>
      </select>
    </label>
    @foreach(['base'=>['Base','Base','0.01'], 'rate'=>['Alíquota %','Rate','0.0001'], 'quantity'=>['Quantidade','Quantity','0.0001'], 'unit_rate'=>['Por unidade (R$)','UnitRate','0.0001']] as $additionalFederalField=>$additionalFederalSpec)
      <label class="field col-2"><span>{{ $additionalFederalLabel }}-ST · {{ $additionalFederalSpec[0] }}</span>
        <input @if($additionalFederalRuleMode) name="tax_parameters[{{ $additionalFederalPrefix.$additionalFederalField }}]" @else id="{{ $additionalFederalId.$additionalFederalSpec[1] }}" @endif
          type="number" min="0" step="{{ $additionalFederalSpec[2] }}" value="{{ $additionalFederalParameters[$additionalFederalPrefix.$additionalFederalField] ?? '' }}">
      </label>
    @endforeach
    <label class="field col-2"><span>Somar {{ $additionalFederalLabel }}-ST ao total</span>
      <select @if($additionalFederalRuleMode) name="tax_parameters[{{ $additionalFederalPrefix }}add_to_total]" @else id="{{ $additionalFederalId }}Add" @endif>
        <option value="" @selected((string)($additionalFederalParameters[$additionalFederalPrefix.'add_to_total']??'')==='')>Não informado</option>
        <option value="0" @selected((string)($additionalFederalParameters[$additionalFederalPrefix.'add_to_total']??'')==='0')>Não</option>
        <option value="1" @selected((string)($additionalFederalParameters[$additionalFederalPrefix.'add_to_total']??'')==='1')>Sim</option>
      </select>
    </label>
  @endforeach
  <label class="field col-3"><span>Mercadoria devolvida %</span>
    <input @if($additionalFederalRuleMode) name="tax_parameters[ipi_returned_percentage]" @else id="nfeTaxIpiReturnedPercentage" @endif
      type="number" min="0.01" max="100" step="0.01" value="{{ $additionalFederalParameters['ipi_returned_percentage'] ?? '' }}">
  </label>
  <label class="field col-3"><span>IPI original do item inteiro (R$)</span>
    <input @if($additionalFederalRuleMode) name="tax_parameters[ipi_returned_original_amount]" @else id="nfeTaxIpiReturnedOriginal" @endif
      type="number" min="0" step="0.01" value="{{ $additionalFederalParameters['ipi_returned_original_amount'] ?? '' }}">
  </label>
</div>
