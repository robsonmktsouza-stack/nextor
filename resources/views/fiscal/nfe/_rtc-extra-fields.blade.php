<details class="editor-panel {{ ($ruleMode ?? false) ? '' : 'nfe-rtc-extra-panel' }}">
  <summary>Diferimento e tributação regular IBS/CBS</summary>
  <div class="editor-grid cols-12 settings-grid">
    <label class="field col-3"><span>IBS estadual diferido %</span>
      <input @if($ruleMode) name="tax_parameters[ibs_uf_deferral_rate]" @else id="nfeExtraIbsUfDeferralRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['ibs_uf_deferral_rate']??'' }}">
    </label>
    <label class="field col-3"><span>IBS municipal diferido %</span>
      <input @if($ruleMode) name="tax_parameters[ibs_municipal_deferral_rate]" @else id="nfeExtraIbsMunicipalDeferralRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['ibs_municipal_deferral_rate']??'' }}">
    </label>
    <label class="field col-3"><span>CBS diferida %</span>
      <input @if($ruleMode) name="tax_parameters[cbs_deferral_rate]" @else id="nfeExtraCbsDeferralRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['cbs_deferral_rate']??'' }}">
    </label>
    <label class="field col-3"><span>CST da tributação regular</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_cst]" @else id="nfeExtraRtcRegularCst" @endif type="text" value="{{ $extraParameters['rtc_regular_cst']??'' }}">
    </label>
    <label class="field col-3"><span>Classe da tributação regular</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_class]" @else id="nfeExtraRtcRegularClass" @endif type="text" value="{{ $extraParameters['rtc_regular_class']??'' }}">
    </label>
    <label class="field col-3"><span>Base da tributação regular (R$)</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_base]" @else id="nfeExtraRtcRegularBase" @endif type="number" min="0" step="0.01" value="{{ $extraParameters['rtc_regular_base']??'' }}">
    </label>
    <label class="field col-3"><span>IBS UF efetivo regular %</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_ibs_uf_rate]" @else id="nfeExtraRtcRegularIbsUfRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['rtc_regular_ibs_uf_rate']??'' }}">
    </label>
    <label class="field col-3"><span>IBS municipal efetivo regular %</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_ibs_municipal_rate]" @else id="nfeExtraRtcRegularIbsMunicipalRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['rtc_regular_ibs_municipal_rate']??'' }}">
    </label>
    <label class="field col-3"><span>CBS efetiva regular %</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_cbs_rate]" @else id="nfeExtraRtcRegularCbsRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['rtc_regular_cbs_rate']??'' }}">
    </label>
    <label class="field col-3"><span>Fonte legal da tributação regular</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_legal_source]" @else id="nfeExtraRtcRegularLegalSource" @endif type="url" value="{{ $extraParameters['rtc_regular_legal_source']??'' }}">
    </label>
    <label class="field col-3"><span>Referência legal da tributação regular</span>
      <input @if($ruleMode) name="tax_parameters[rtc_regular_legal_reference]" @else id="nfeExtraRtcRegularLegalReference" @endif type="text" value="{{ $extraParameters['rtc_regular_legal_reference']??'' }}">
    </label>
  </div>
</details>
<details class="editor-panel {{ ($ruleMode ?? false) ? '' : 'nfe-rtc-extra-panel' }}">
  <summary>Imposto Seletivo</summary>
  <div class="editor-grid cols-12 settings-grid">
    <label class="field col-3"><span>CST IS</span>
      <input @if($ruleMode) name="tax_parameters[is_cst]" @else id="nfeExtraIsCst" @endif type="text" value="{{ $extraParameters['is_cst']??'' }}">
    </label>
    <label class="field col-3"><span>Classificação IS</span>
      <input @if($ruleMode) name="tax_parameters[is_class]" @else id="nfeExtraIsClass" @endif type="text" value="{{ $extraParameters['is_class']??'' }}">
    </label>
    <label class="field col-3"><span>Modo de cálculo IS</span>
      <select @if($ruleMode) name="tax_parameters[is_calculation_type]" @else id="nfeExtraIsCalculationType" @endif>
        <option value="" @selected(($extraParameters['is_calculation_type']??'')==='')>Não informado</option>
        <option value="percent" @selected(($extraParameters['is_calculation_type']??'')==='percent')>Percentual</option>
        <option value="quantity" @selected(($extraParameters['is_calculation_type']??'')==='quantity')>Por quantidade</option>
        <option value="combined" @selected(($extraParameters['is_calculation_type']??'')==='combined')>Percentual e quantidade</option>
      </select>
    </label>
    <label class="field col-3"><span>Base IS (R$)</span>
      <input @if($ruleMode) name="tax_parameters[is_base]" @else id="nfeExtraIsBase" @endif type="number" min="0" step="0.01" value="{{ $extraParameters['is_base']??'' }}">
    </label>
    <label class="field col-3"><span>IS percentual %</span>
      <input @if($ruleMode) name="tax_parameters[is_rate]" @else id="nfeExtraIsRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['is_rate']??'' }}">
    </label>
    <label class="field col-3"><span>Quantidade tributável IS</span>
      <input @if($ruleMode) name="tax_parameters[is_quantity]" @else id="nfeExtraIsQuantity" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['is_quantity']??'' }}">
    </label>
    <label class="field col-3"><span>IS por unidade (R$)</span>
      <input @if($ruleMode) name="tax_parameters[is_unit_rate]" @else id="nfeExtraIsUnitRate" @endif type="number" min="0" step="0.0001" value="{{ $extraParameters['is_unit_rate']??'' }}">
    </label>
    <label class="field col-3"><span>Unidade tributável IS</span>
      <input @if($ruleMode) name="tax_parameters[is_unit]" @else id="nfeExtraIsUnit" @endif type="text" value="{{ $extraParameters['is_unit']??'' }}">
    </label>
    <label class="field col-3"><span>Início da vigência IS</span>
      <input @if($ruleMode) name="tax_parameters[is_valid_from]" @else id="nfeExtraIsValidFrom" @endif type="date" value="{{ $extraParameters['is_valid_from']??'' }}">
    </label>
    <label class="field col-3"><span>Fim da vigência IS</span>
      <input @if($ruleMode) name="tax_parameters[is_valid_until]" @else id="nfeExtraIsValidUntil" @endif type="date" value="{{ $extraParameters['is_valid_until']??'' }}">
    </label>
    <label class="field col-3"><span>Fonte normativa IS</span>
      <input @if($ruleMode) name="tax_parameters[is_legal_source]" @else id="nfeExtraIsLegalSource" @endif type="url" value="{{ $extraParameters['is_legal_source']??'' }}">
    </label>
    <label class="field col-3"><span>Referência normativa IS</span>
      <input @if($ruleMode) name="tax_parameters[is_legal_reference]" @else id="nfeExtraIsLegalReference" @endif type="text" value="{{ $extraParameters['is_legal_reference']??'' }}">
    </label>
  </div>
</details>
