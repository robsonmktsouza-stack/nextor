@extends('layouts.app')
@section('title','Configurações')
@section('titleMeta','Central do Lumeron')
@section('content')

@php
$primaryTabs=[
  'general'=>['label'=>'Minha empresa','icon'=>'settings','members'=>['general','printing','catalog']],
  'operations'=>['label'=>'Vendas e estoque','icon'=>'sales','members'=>['operations','inventory','pdv']],
  'chart'=>['label'=>'Financeiro','icon'=>'money','members'=>['chart','accounts','payments','billing','accounting']],
  'fiscal'=>['label'=>'Notas fiscais','icon'=>'receipt','members'=>['fiscal','nfe','nfce','nfse','cte','tax']],
  'users'=>['label'=>'Equipe e acessos','icon'=>'customers','members'=>['users']],
  'system'=>['label'=>'Outras opções','icon'=>'settings','members'=>['system','integrations']],
];
$subTabs=[
  'general'=>[
    'general'=>['Dados da empresa','settings'],
    'printing'=>['Logotipo e impressão','receipt'],
    'catalog'=>['Produtos e serviços','products'],
  ],
  'operations'=>[
    'operations'=>['Vendas','sales'],
    'inventory'=>['Estoque','stock'],
    'pdv'=>['Frente de caixa (PDV)','pdv'],
  ],
  'chart'=>[
    'chart'=>['Categorias financeiras','layers'],
    'accounts'=>['Caixa e bancos','money'],
    'payments'=>['Formas de pagamento','receipt'],
    'billing'=>['Cobranças e PIX','money'],
    'accounting'=>['Contabilidade','services'],
  ],
  'fiscal'=>[
    'fiscal'=>['Informações fiscais','shield'],
    'nfe'=>['Nota de produto (NF-e)','receipt'],
    'nfce'=>['Nota do consumidor (NFC-e)','receipt'],
    'nfse'=>['Nota de serviço (NFS-e)','services'],
    'cte'=>['Transporte (CT-e / MDF-e)','stock'],
    'tax'=>['Impostos e tributação','layers'],
  ],
  'system'=>[
    'system'=>['Aparência e preferências','settings'],
    'integrations'=>['Integrações avançadas','layers'],
  ],
];

$activePrimary='general';
foreach($primaryTabs as $key=>$item){
  if(in_array($tab,$item['members'],true)){
    $activePrimary=$key;
    break;
  }
}
@endphp

<nav class="settings-main-tabs" aria-label="Áreas de configurações">
  @foreach($primaryTabs as $key=>$item)
    <a href="{{ route('settings.index',['tab'=>$item['members'][0]]) }}"
       class="{{ $activePrimary===$key?'active':'' }}"
       @if($activePrimary===$key) aria-current="page" @endif>
      @include('partials.icon',['name'=>$item['icon'],'size'=>15])
      <span>{{ $item['label'] }}</span>
    </a>
  @endforeach
</nav>

@if(isset($subTabs[$activePrimary]))
  <nav class="settings-subtabs" aria-label="Seção de configurações">
    @foreach($subTabs[$activePrimary] as $key=>$item)
      <a href="{{ route('settings.index',['tab'=>$key]) }}" class="{{ $tab===$key?'active':'' }}" @if($tab===$key) aria-current="page" @endif>
        @include('partials.icon',['name'=>$item[1],'size'=>13])
        <span>{{ $item[0] }}</span>
      </a>
    @endforeach
  </nav>
@endif

<main class="settings-page">
@if($tab==='general')
  <form method="post" action="{{ route('settings.company.update') }}" enctype="multipart/form-data" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Dados da empresa</h2></div>
        
      </div>

      <div class="editor-grid cols-12 settings-grid" data-address-scope>
        <label class="field col-4"><span>CNPJ / CPF</span>
          <div class="input-action-group">
            <input name="document" value="{{ old('document',$company->document) }}" maxlength="20" data-cnpj-document>
            <button type="button" class="input-action-button" data-cnpj-autofill data-tooltip="Consultar CNPJ">@include('partials.icon',['name'=>'search','size'=>15])</button>
          </div>
        </label>
        <label class="field col-8"><span>Razão social / Nome</span><input name="legal_name" value="{{ old('legal_name',$company->legal_name) }}" maxlength="190"></label>
        <label class="field col-6"><span>Nome fantasia</span><input name="trade_name" value="{{ old('trade_name',$company->trade_name) }}" maxlength="190"></label>
        <label class="field col-3"><span>Inscrição Estadual</span><input name="state_registration" value="{{ old('state_registration',$company->state_registration) }}"></label>
        <label class="field col-3"><span>Inscrição Municipal</span><input name="municipal_registration" value="{{ old('municipal_registration',$company->municipal_registration) }}"></label>

        <label class="field col-4"><span>CNAE principal</span><input name="cnae_main" value="{{ old('cnae_main',$company->cnae_main) }}"></label>
        <label class="field col-4"><span>Telefone</span><input name="phone" value="{{ old('phone',$company->phone) }}"></label>
        <label class="field col-4"><span>E-mail</span><input type="email" name="email" value="{{ old('email',$company->email) }}"></label>

        <label class="field col-3"><span>CEP</span>
          <div class="input-action-group">
            <input name="zip_code" value="{{ old('zip_code',$company->zip_code) }}" data-cep-input>
            <button type="button" class="input-action-button" data-cep-search data-tooltip="Consultar CEP">@include('partials.icon',['name'=>'search','size'=>15])</button>
          </div>
        </label>
        <label class="field col-2"><span>UF</span>
          <select name="state" data-uf-select>
            <option value="">UF</option>
            @foreach(['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $uf)
              <option value="{{ $uf }}" @selected(old('state',$company->state)===$uf)>{{ $uf }}</option>
            @endforeach
          </select>
        </label>
        <label class="field col-5"><span>Cidade</span>
          <select name="city" data-city-select data-current-city="{{ old('city',$company->city) }}"><option value="{{ old('city',$company->city) }}">{{ old('city',$company->city) ?: 'Selecione o estado' }}</option></select>
        </label>
        <label class="field col-2"><span>Código do município (IBGE)</span><input name="city_ibge_code" value="{{ old('city_ibge_code',$company->city_ibge_code) }}"></label>

        <label class="field col-8"><span>Endereço</span><input name="address" value="{{ old('address',$company->address) }}" data-address-input></label>
        <label class="field col-2"><span>Número</span><input name="address_number" value="{{ old('address_number',$company->address_number) }}"></label>
        <label class="field col-2"><span>Bairro</span><input name="district" value="{{ old('district',$company->district) }}" data-district-input></label>
        <label class="field col-8"><span>Complemento</span><input name="address_complement" value="{{ old('address_complement',$company->address_complement) }}"></label>
        <label class="field col-4"><span>Fuso horário da empresa</span><input name="timezone" value="{{ old('timezone',$company->timezone ?: 'America/Sao_Paulo') }}" required></label>
      </div>
    </section>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Regime tributário da empresa</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4"><span>Regime tributário</span>
          <select name="tax_regime">
            <option value="">Selecione</option>
            @foreach(['simples_nacional'=>'Simples Nacional','lucro_presumido'=>'Lucro Presumido','lucro_real'=>'Lucro Real','mei'=>'MEI','outro'=>'Outro'] as $key=>$label)
              <option value="{{ $key }}" @selected(old('tax_regime',$company->tax_regime)===$key)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <label class="field col-2"><span>Regime da nota (CRT)</span><select name="crt">
          <option value="">Selecione</option>
          @foreach(['1'=>'Simples Nacional','2'=>'Simples - sublimite','3'=>'Regime normal','4'=>'MEI'] as $code=>$name)
            <option value="{{ $code }}" @selected((string)old('crt',$company->crt)===(string)$code)>{{ $code }} — {{ $name }}</option>
          @endforeach
        </select></label>
        <label class="field col-3"><span>Alíquota do Simples Nacional (%)</span><input type="number" step="0.0001" min="0" max="100" name="simple_rate" value="{{ old('simple_rate',$company->simple_rate) }}"></label>
        <label class="field col-3"><span>Atividade principal</span>
          <select name="main_activity">
            <option value="">Selecione</option>
            <option value="commerce" @selected(old('main_activity',$company->main_activity)==='commerce')>Comércio</option>
            <option value="service" @selected(old('main_activity',$company->main_activity)==='service')>Serviços</option>
            <option value="industry" @selected(old('main_activity',$company->main_activity)==='industry')>Indústria</option>
            <option value="mixed" @selected(old('main_activity',$company->main_activity)==='mixed')>Mista</option>
          </select>
        </label>
      </div>
    </section>

  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
  </div>
</form>

@elseif($tab==='printing')
  <form method="post" action="{{ route('settings.printing.update') }}" enctype="multipart/form-data" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Logotipo e impressão</h2></div>
        
      </div>
      <div class="settings-brand-grid">
        <div class="settings-logo-box">
          @if($company->logo_path)
            <img src="{{ route('company.logo') }}" alt="">
          @else
            <div class="settings-logo-empty">@include('partials.icon',['name'=>'products','size'=>30])<span>Sem logomarca</span></div>
          @endif
          <label class="field"><span>Nova logomarca</span><input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp"></label>
        </div>
        <div class="settings-print-fields">
          <label class="field"><span>Cabeçalho de impressão</span><textarea name="print_header" rows="5">{{ old('print_header',$company->print_header) }}</textarea></label>
          <label class="field"><span>Rodapé de impressão</span><textarea name="print_footer" rows="5">{{ old('print_footer',$company->print_footer) }}</textarea></label>
          <label class="settings-switch settings-switch-inline">
            <input type="checkbox" name="show_currency_prefix" value="1" @checked(old('show_currency_prefix',$company->show_currency_prefix))>
            <span><strong>Mostrar R$ nas impressões</strong><small>Exibe o prefixo monetário em relatórios e comprovantes.</small></span>
          </label>
        </div>
      </div>
    </section>
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Impressão fiscal</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4">
          <span>Tamanho do papel da nota do consumidor</span>
          <select name="nfce_paper">
            <option value="80" @selected((string)old('nfce_paper',$printing['nfce_paper'])==='80')>Bobina 80 mm</option>
            <option value="58" @selected((string)old('nfce_paper',$printing['nfce_paper'])==='58')>Bobina 58 mm</option>
            <option value="a4" @selected((string)old('nfce_paper',$printing['nfce_paper'])==='a4')>A4</option>
          </select>
        </label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
  </div>
</form>

@elseif($tab==='catalog')
  <form method="post" action="{{ route('settings.group.update','catalog') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Produtos e serviços</h2></div>
        
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Unidade padrão</span><input name="product_unit" value="{{ $catalog['product_unit'] }}" maxlength="12" placeholder="UN"></label>
        <label class="field col-5"><span>Uso padrão do produto</span>
          <select name="product_usage_type">
            @foreach(['resale'=>'Revenda','consumption'=>'Uso e consumo','raw_material'=>'Matéria-prima','fixed_asset'=>'Ativo imobilizado','packaging'=>'Embalagem','other'=>'Outro'] as $key=>$label)
              <option value="{{ $key }}" @selected($catalog['product_usage_type']===$key)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <label class="field col-4"><span>Estoque mínimo padrão</span><input type="number" step="0.001" min="0" name="product_minimum_stock" value="{{ $catalog['product_minimum_stock'] }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="product_control_stock" value="1" @checked($catalog['product_control_stock'])><span><strong>Controlar estoque por padrão</strong><small>Novos produtos já iniciam com controle de saldo habilitado.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="new_products_active" value="1" @checked($catalog['new_products_active'])><span><strong>Novos produtos ativos</strong><small>Define o status inicial de novos produtos.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="new_services_active" value="1" @checked($catalog['new_services_active'])><span><strong>Novos serviços ativos</strong><small>Define o status inicial de novos serviços.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
  </div>
</form>

@elseif($tab==='chart')
  <section class="editor-panel settings-panel">
    <div class="settings-panel-head"><div><h2>Categorias de receitas e despesas</h2></div></div>
    <form method="post" action="{{ route('finance.categories.store') }}" class="settings-inline-form">
      @csrf
      <label class="field"><span>Nome</span><input name="name" maxlength="120" required placeholder="Ex.: Receita de vendas"></label>
      <label class="field"><span>Tipo</span><select name="type"><option value="income">Receita</option><option value="expense">Despesa</option></select></label>
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar categoria</button>
    </form>
    <div class="settings-split-tables">
      @foreach(['income'=>'Receitas','expense'=>'Despesas'] as $kind=>$label)
        <div>
          <h3>{{ $label }}</h3>
          <table class="cms-table">
            <thead><tr><th>Categoria</th><th>Status</th><th class="action-cell"></th></tr></thead>
            <tbody>
            @forelse($categories->where('type',$kind) as $category)
              <tr>
                <td>{{ $category->name }}</td>
                <td><span class="status {{ $category->is_active?'status-ok':'status-muted' }}">{{ $category->is_active?'Ativa':'Inativa' }}</span></td>
                <td class="action-cell"><form method="post" action="{{ route('finance.categories.toggle',$category) }}">@csrf<button class="btn-icon">@include('partials.icon',['name'=>$category->is_active?'x':'check','size'=>15])</button></form></td>
              </tr>
            @empty<tr><td colspan="3" class="empty-cell">Nenhuma categoria.</td></tr>@endforelse
            </tbody>
          </table>
        </div>
      @endforeach
    </div>
  </section>

@elseif($tab==='accounts')
  <section class="editor-panel settings-panel">
    <div class="settings-panel-head"><div><h2>Caixa e contas bancárias</h2></div></div>
    <form method="post" action="{{ route('finance.accounts.store') }}" class="settings-inline-form settings-inline-4">
      @csrf
      <label class="field"><span>Nome</span><input name="name" maxlength="120" required></label>
      <label class="field"><span>Tipo</span><select name="type"><option value="cash">Caixa</option><option value="bank">Banco</option><option value="digital">Conta digital</option><option value="other">Outra</option></select></label>
      <label class="field"><span>Saldo inicial</span><input type="number" data-number-kind="money" name="opening_balance" step="0.01" min="0" value="0.00" required></label>
      <button class="btn btn-primary">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar conta</button>
    </form>
    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Conta</th><th>Tipo</th><th>Saldo atual</th><th>Status</th><th class="action-cell"></th></tr></thead>
        <tbody>
        @foreach($accounts as $account)
          <tr>
            <td><strong>{{ $account->name }}</strong></td>
            <td>{{ match($account->type){'bank'=>'Banco','digital'=>'Conta digital','other'=>'Outra',default=>'Caixa'} }}</td>
            <td class="price-strong">R$ {{ number_format((float)$account->current_balance,2,',','.') }}</td>
            <td><span class="status {{ $account->is_active?'status-ok':'status-muted' }}">{{ $account->is_active?'Ativa':'Inativa' }}</span></td>
            <td class="action-cell"><form method="post" action="{{ route('finance.accounts.toggle',$account) }}">@csrf<button class="btn-icon">@include('partials.icon',['name'=>$account->is_active?'x':'check','size'=>15])</button></form></td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </section>

@elseif($tab==='operations')
  <form method="post" action="{{ route('settings.group.update','operations') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Preferências de vendas</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Validade padrão do orçamento</span><input type="number" name="quote_valid_days" value="{{ $operations['quote_valid_days'] }}" min="0"><small>Dias</small></label>
        <label class="field col-3"><span>Vencimento padrão</span><input type="number" name="default_due_days" value="{{ $operations['default_due_days'] }}" min="0"><small>Dias após a venda</small></label>
        <label class="field col-6"><span>Conta usada por padrão</span><select name="default_financial_account_id"><option value="">Sem padrão</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string)$operations['default_financial_account_id']===(string)$a->id)>{{ $a->name }}</option>@endforeach</select></label>
        <label class="field col-6"><span>Categoria padrão de receita</span><select name="default_income_category_id"><option value="">Sem padrão</option>@foreach($categories->where('type','income') as $c)<option value="{{ $c->id }}" @selected((string)$operations['default_income_category_id']===(string)$c->id)>{{ $c->name }}</option>@endforeach</select></label>
        <label class="field col-6"><span>Categoria padrão de despesa</span><select name="default_expense_category_id"><option value="">Sem padrão</option>@foreach($categories->where('type','expense') as $c)<option value="{{ $c->id }}" @selected((string)$operations['default_expense_category_id']===(string)$c->id)>{{ $c->name }}</option>@endforeach</select></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="default_final_consumer" value="1" @checked($operations['default_final_consumer'])><span><strong>Consumidor final por padrão</strong><small>Novas vendas iniciam marcadas como consumidor final.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_finance_sale" value="1" @checked($operations['auto_finance_sale'])><span><strong>Registrar valores das vendas no financeiro</strong><small>Registra automaticamente os valores e parcelas da venda no financeiro.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="allow_partial_return" value="1" @checked($operations['allow_partial_return'])><span><strong>Permitir devolução parcial</strong><small>Permite devolver parte da quantidade vendida.</small></span></label>
      </div>
    </section>

    <div class="editor-savebar settings-savebar">
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
    </div>
  </form>

@elseif($tab==='inventory')
  <form method="post" action="{{ route('settings.group.update','inventory') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Estoque</h2></div>
        
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Casas decimais nas quantidades</span>
          <select name="stock_decimal_places">
            @foreach(['0','1','2','3'] as $places)<option value="{{ $places }}" @selected((string)$inventory['stock_decimal_places']===$places)>{{ $places }}</option>@endforeach
          </select>
        </label>
        <label class="field col-9"><span>Motivo padrão para ajuste</span><input name="default_adjustment_reason" value="{{ $inventory['default_adjustment_reason'] }}" maxlength="255" placeholder="Ex.: Ajuste manual de estoque"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="allow_negative_stock" value="1" @checked($inventory['allow_negative_stock'])><span><strong>Permitir estoque negativo</strong><small>Autoriza venda mesmo quando o saldo controlado for insuficiente.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="minimum_stock_alerts" value="1" @checked($inventory['minimum_stock_alerts'])><span><strong>Avisar quando o estoque estiver baixo</strong><small>Habilita avisos quando o produto atingir o limite configurado.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
  </div>
</form>

@elseif($tab==='payments')
  <section class="editor-panel settings-panel">
    <div class="settings-panel-head"><div><h2>Formas de pagamento</h2></div></div>
    <form method="post" action="{{ route('settings.payment-methods.store') }}" class="settings-payment-form">
      @csrf
      <label class="field"><span>Código</span><input name="code" required placeholder="Ex.: vale"></label>
      <label class="field"><span>Nome</span><input name="name" required placeholder="Vale"></label>
      <label class="field"><span>Tipo</span><select name="kind"><option value="cash">Dinheiro</option><option value="pix">PIX</option><option value="credit_card">Cartão de crédito</option><option value="debit_card">Cartão de débito</option><option value="bank_slip">Boleto</option><option value="transfer">Transferência</option><option value="other">Outro</option></select></label>
      <label class="field"><span>Conta padrão</span><select name="financial_account_id"><option value="">Nenhuma</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
      <label class="field"><span>Taxa percentual (%)</span><input type="number" step="0.0001" min="0" name="fee_percent" value="0"></label>
      <label class="field"><span>Taxa fixa (R$)</span><input type="number" step="0.01" min="0" name="fee_fixed" value="0"></label>
      <label class="field"><span>Receber após</span><input type="number" min="0" name="settlement_days" value="0"><small>Dias</small></label>
      <label class="field"><span>Ordem de exibição</span><input type="number" min="0" name="sort_order" value="100"></label>
      <label class="check-line"><input type="checkbox" name="is_active" value="1" checked><span>Ativa</span></label>
      <label class="check-line"><input type="checkbox" name="pdv_enabled" value="1" checked><span>Disponível no PDV</span></label>
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar forma de pagamento</button>
    </form>

    <div class="settings-payment-list">
      @foreach($paymentMethods as $method)
        <details class="settings-edit-row">
          <summary>
            <div><strong>{{ $method->name }}</strong><span>{{ $method->code }} · {{ $method->financialAccount?->name ?? 'sem conta padrão' }}</span></div>
            <div><span class="status {{ $method->is_active?'status-ok':'status-muted' }}">{{ $method->is_active?'Ativa':'Inativa' }}</span>@include('partials.icon',['name'=>'down','size'=>15])</div>
          </summary>
          <form method="post" action="{{ route('settings.payment-methods.update',$method) }}" class="settings-payment-form">
            @csrf @method('PUT')
            <label class="field"><span>Código</span><input name="code" value="{{ $method->code }}" required></label>
            <label class="field"><span>Nome</span><input name="name" value="{{ $method->name }}" required></label>
            <label class="field"><span>Tipo</span><select name="kind">@foreach(['cash'=>'Dinheiro','pix'=>'PIX','credit_card'=>'Cartão de crédito','debit_card'=>'Cartão de débito','card'=>'Cartão (antigo — classificar)','bank_slip'=>'Boleto','transfer'=>'Transferência','other'=>'Outro'] as $k=>$v)<option value="{{ $k }}" @selected($method->kind===$k)>{{ $v }}</option>@endforeach</select></label>
            <label class="field"><span>Conta padrão</span><select name="financial_account_id"><option value="">Nenhuma</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($method->financial_account_id===$a->id)>{{ $a->name }}</option>@endforeach</select></label>
            <label class="field"><span>Taxa percentual (%)</span><input type="number" step="0.0001" min="0" name="fee_percent" value="{{ $method->fee_percent }}"></label>
            <label class="field"><span>Taxa fixa (R$)</span><input type="number" step="0.01" min="0" name="fee_fixed" value="{{ $method->fee_fixed }}"></label>
            <label class="field"><span>Receber após</span><input type="number" min="0" name="settlement_days" value="{{ $method->settlement_days }}"></label>
            <label class="field"><span>Ordem de exibição</span><input type="number" min="0" name="sort_order" value="{{ $method->sort_order }}"></label>
            <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($method->is_active)><span>Ativa</span></label>
            <label class="check-line"><input type="checkbox" name="pdv_enabled" value="1" @checked($method->pdv_enabled)><span>PDV</span></label>
            <button class="btn btn-primary">Salvar</button>
          </form>
        </details>
      @endforeach
    </div>
  </section>

@elseif($tab==='pdv')
  @push('styles')
    <link rel="stylesheet" href="{{ asset('css/pdv-settings.css') }}?v={{ filemtime(public_path('css/pdv-settings.css')) }}">
  @endpush
  <form method="post" action="{{ route('settings.group.update','pdv') }}" class="settings-editor pdv-settings-editor">
    @csrf
    <section class="editor-panel settings-panel pdv-settings-panel">
      <div class="settings-panel-head">
        <div>
          <h2>Frente de caixa (PDV)</h2>
        </div>
      </div>

      <div class="editor-grid cols-12 settings-grid pdv-settings-general">
        <label class="field col-6">
          <span>Forma de pagamento padrão</span>
          <select name="default_payment_method">
            <option value="">Sem padrão</option>
            @foreach($paymentMethods->where('is_active',true)->where('pdv_enabled',true) as $m)
              <option value="{{ $m->code }}" @selected($pdv['default_payment_method']===$m->code)>{{ $m->name }}</option>
            @endforeach
          </select>
        </label>
        <label class="field col-3">
          <span>Largura do cupom</span>
          <select name="receipt_width">
            <option value="58" @selected((string)$pdv['receipt_width']==='58')>58 mm</option>
            <option value="80" @selected((string)$pdv['receipt_width']==='80')>80 mm</option>
          </select>
        </label>
        <label class="field col-3">
          <span>Cópias</span>
          <input type="number" min="1" max="5" name="receipt_copies" value="{{ $pdv['receipt_copies'] }}">
        </label>
      </div>

      <div class="pdv-settings-section">
        <div class="pdv-settings-section-head">
          <h3>Atendimento e vendas</h3>
          <p>Configure o comportamento do PDV durante o atendimento.</p>
        </div>
        <div class="pdv-settings-options-grid">
          <label class="settings-switch">
            <input type="checkbox" name="require_customer" value="1" @checked($pdv['require_customer'])>
            <span><strong>Exigir cliente</strong><small>Impede concluir a venda sem identificar o cliente.</small></span>
          </label>
          <label class="settings-switch">
            <input type="checkbox" name="allow_discount" value="1" @checked($pdv['allow_discount'])>
            <span><strong>Permitir descontos</strong><small>Habilita descontos nos itens do PDV.</small></span>
          </label>
          <div class="pdv-settings-limit">
            <label class="field" for="pdvDiscountApprovalPercent">
              <span>Limite de desconto sem supervisor (%)</span>
              <input id="pdvDiscountApprovalPercent" name="discount_approval_percent"
                     type="number" step="0.01" min="0" max="100"
                     value="{{ $pdv['discount_approval_percent'] ?? 10 }}">
            </label>
            <p>Acima desse percentual em qualquer item, exige autorização.</p>
          </div>
          <label class="settings-switch">
            <input type="checkbox" name="ask_consumer_document" value="1" @checked($pdv['ask_consumer_document'])>
            <span><strong>Perguntar CPF/CNPJ na nota</strong><small>Consulta o consumidor antes de finalizar a venda.</small></span>
          </label>
          <label class="settings-switch">
            <input type="checkbox" name="allow_split_payment" value="1" @checked($pdv['allow_split_payment'])>
            <span><strong>Permitir pagamento dividido</strong><small>Combina dinheiro, PIX, cartão e outras formas.</small></span>
          </label>
          <label class="settings-switch">
            <input type="checkbox" name="show_stock" value="1" @checked($pdv['show_stock'])>
            <span><strong>Mostrar estoque</strong><small>Exibe a quantidade disponível na busca de produtos.</small></span>
          </label>
        </div>
      </div>

      <div class="pdv-settings-section">
        <div class="pdv-settings-section-head">
          <h3>Caixa e segurança</h3>
          <p>Controle a responsabilidade pelos turnos e pelas movimentações em dinheiro.</p>
        </div>
        <div class="pdv-settings-options-grid">
          <label class="settings-switch">
            <input type="checkbox" name="require_cash_opening" value="1" @checked($pdv['require_cash_opening'])>
            <span><strong>Exigir abertura de caixa</strong><small>Obriga um turno aberto antes de concluir vendas, com identificação do operador.</small></span>
          </label>
          <label class="settings-switch">
            <input type="checkbox" name="allow_cash_movements" value="1" @checked($pdv['allow_cash_movements'])>
            <span><strong>Permitir movimentações de caixa</strong><small>Habilita sangrias, suprimentos e reembolsos.</small></span>
          </label>
          <div class="pdv-settings-limit">
            <label class="field" for="pdvSupervisorLimit">
              <span>Aprovação de supervisor a partir de (R$)</span>
              <input id="pdvSupervisorLimit" type="number" step="0.01" min="0" max="99999999" name="supervisor_movement_min"
                     value="{{ $pdv['supervisor_movement_min'] ?? 200 }}">
            </label>
            <p>Para retiradas e reembolsos nesse valor ou acima.</p>
          </div>
        </div>
      </div>

      <div class="pdv-settings-section">
        <div class="pdv-settings-section-head">
          <h3>Emissão fiscal</h3>
          <p>Defina como o PDV deve preparar a NFC-e após a venda.</p>
        </div>
        <div class="pdv-settings-options-grid">
          <label class="settings-switch">
            <input type="checkbox" name="auto_nfce" value="1" @checked($pdv['auto_nfce'])>
            <span><strong>Transmitir NFC-e automaticamente</strong><small>Se desativado, o documento fica preparado para revisão e emissão manual.</small></span>
          </label>
        </div>
      </div>
    </section>

    <div class="editor-savebar settings-savebar">
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
    </div>
  </form>

@elseif($tab==='billing')
  <form method="post" action="{{ route('settings.group.update','billing') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Cobranças e PIX</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4"><span>Conta usada por padrão</span><select name="default_financial_account_id"><option value="">Nenhuma</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string)$billing['default_financial_account_id']===(string)$a->id)>{{ $a->name }}</option>@endforeach</select></label>
        <label class="field col-4"><span>Chave PIX</span><input name="pix_key" value="{{ $billing['pix_key'] }}"></label>
        <label class="field col-4"><span>Vencimento padrão</span><input type="number" min="0" name="default_due_days" value="{{ $billing['default_due_days'] }}"><small>Dias</small></label>
        <label class="field col-3"><span>Multa (%)</span><input type="number" step="0.0001" min="0" name="fine_percent" value="{{ $billing['fine_percent'] }}"></label>
        <label class="field col-3"><span>Juros mensal (%)</span><input type="number" step="0.0001" min="0" name="interest_monthly_percent" value="{{ $billing['interest_monthly_percent'] }}"></label>
        <label class="field col-12"><span>Instruções padrão</span><textarea name="instructions" rows="4">{{ $billing['instructions'] }}</textarea></label>
      </div>
      <label class="settings-switch settings-switch-single"><input type="checkbox" name="enabled" value="1" @checked($billing['enabled'])><span><strong>Ativar cobranças</strong><small>Ativa cálculo de multa e juros e geração de PIX copia e cola nas contas a receber.</small></span></label>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='fiscal')
  <div class="settings-stack">
    <form method="post" action="{{ route('settings.group.update','fiscal') }}" class="settings-editor">
      @csrf
      <section class="editor-panel settings-panel">
        <div class="settings-panel-head"><div><h2>Emissão de notas fiscais</h2></div></div>
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-4"><span>Ambiente de emissão</span><select name="default_environment"><option value="homologation" @selected($fiscal['default_environment']==='homologation')>Homologação</option><option value="production" @selected($fiscal['default_environment']==='production')>Produção</option></select></label>
          <label class="field col-4"><span>E-mail do contador</span><input type="email" name="accountant_email" value="{{ $fiscal['accountant_email'] }}"></label>
          <label class="field col-4"><span>Perfil tributário</span><input name="tax_profile" value="{{ $fiscal['tax_profile'] }}" placeholder="Ex.: padrão Simples Nacional"></label>
        </div>
        <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>'accounting']) }}">Contador e autorização de acesso ao XML da NF-e</a>
        <div class="settings-switch-grid">
          <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($fiscal['enabled'])><span><strong>Mostrar opções de notas fiscais</strong></span></label>
          <label class="settings-switch"><input type="checkbox" name="send_xml_email" value="1" @checked($fiscal['send_xml_email'])><span><strong>Enviar XML por e-mail</strong><small>Preferência padrão para documentos autorizados.</small></span></label>
          <label class="settings-switch"><input type="checkbox" name="keep_xml_copy" value="1" @checked($fiscal['keep_xml_copy'])><span><strong>Guardar cópia dos XMLs</strong><small>Preserva documentos autorizados no armazenamento do sistema.</small></span></label>
        </div>
      </section>
    
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Certificado digital da empresa (A1)</h2></div></div>
      @if($company->certificate_path)
        <div class="certificate-status ok">
          @include('partials.icon',['name'=>'shield','size'=>24])
          <div>
            <strong>Certificado configurado</strong>
            <span>
              @if($company->certificate_expires_at)
                Válido até {{ $company->certificate_expires_at->format(\App\Models\AppSetting::dateFormat()) }}
              @else
                Validade não identificada
              @endif
            </span>
          </div>
          <form method="post" action="{{ route('settings.certificate.remove') }}" data-confirm-submit="Remover o certificado A1? A emissão fiscal dependerá do cadastro de um novo certificado.">@csrf @method('DELETE')<button class="btn btn-danger-outline">Remover</button></form>
        </div>
      @else
        <div class="certificate-status"><div><strong>Nenhum certificado A1 configurado</strong><span>Envie um arquivo .pfx ou .p12.</span></div></div>
      @endif
      <form method="post" action="{{ route('settings.certificate.upload') }}" enctype="multipart/form-data" class="settings-certificate-form">
        @csrf
        <label class="field"><span>Certificado A1</span><input type="file" name="certificate" accept=".pfx,.p12" required></label>
        <label class="field"><span>Senha</span><input type="password" name="certificate_password" required></label>
        <button class="btn btn-success" type="submit">Salvar certificado</button>
      </form>
    </section>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Últimos documentos fiscais</h2></div>
      </div>
      <div class="table-scroll">
        <table class="cms-table">
          <thead><tr><th>Tipo</th><th>Origem</th><th>Ambiente</th><th>Emissão</th><th>Série</th><th>Número</th><th>Status</th><th>Cancelamento</th><th>Preparado em</th></tr></thead>
          <tbody>
          @forelse($fiscalJobs as $job)
            <tr>
              <td><strong>{{ match($job->document_type){'nfe'=>'NF-e','nfce'=>'NFC-e','nfse'=>'NFS-e','cte'=>'CT-e','mdfe'=>'MDF-e',default=>'Documento fiscal'} }}</strong></td>
              <td>@if($job->sale)<a class="table-link" href="{{ route('sales.show',$job->sale) }}">Venda #{{ str_pad((string)$job->sale_id,5,'0',STR_PAD_LEFT) }}</a>@else—@endif</td>
              <td>{{ $job->environment==='production'?'Produção':'Homologação' }}</td>
              <td>{{ $job->emission_mode==='offline'?'Contingência':'Normal' }}</td>
              <td>{{ $job->series ?? '—' }}</td>
              <td>{{ $job->document_number ?? '—' }}</td>
              <td><span class="status {{ match($job->status){'authorized'=>'status-ok','rejected','failed','error'=>'status-danger','cancelled'=>'status-muted',default=>'status-blue'} }}">{{ match($job->status){'draft'=>'Rascunho','prepared'=>'Preparada','queued'=>'Aguardando envio','processing'=>'Em processamento','pending'=>'Aguardando confirmação','authorized'=>'Autorizada','rejected'=>'Rejeitada','cancelled'=>'Cancelada','offline_signed'=>'Contingência assinada','offline_print_pending'=>'Impressão pendente','offline_sending'=>'Transmitindo contingência','failed','error'=>'Falha',default=>'A verificar'} }}</span></td>
              <td>{{ $job->cancelled_at ? 'Cancelada' : match($job->cancellation_status){'pending'=>'Solicitado','processing'=>'Em processamento','authorized'=>'Confirmado','rejected'=>'Rejeitado','uncertain'=>'Conferência necessária','prepared'=>'Não enviado',default=>'—'} }}</td>
              <td>{{ $job->prepared_at?->format(\App\Models\AppSetting::dateFormat().' H:i') ?? '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="9" class="empty-cell">Nenhum documento fiscal preparado ainda.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      
    </section>
  </div>

@elseif($tab==='tax')
  <form method="post" action="{{ route('settings.group.update','tax') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Impostos e códigos fiscais</h2></div>
        <div class="row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <a class="btn btn-secondary" href="{{ route('fiscal.catalog.index') }}">
            @include('partials.icon',['name'=>'layers','size'=>15]) Catálogo fiscal
          </a>
          <a class="btn btn-secondary" href="{{ route('fiscal.tax-groups.index') }}">
            @include('partials.icon',['name'=>'layers','size'=>15]) Grupos de tributação
          </a>
          <a class="btn btn-secondary" href="{{ route('fiscal.tax-rules.index') }}">
            @include('partials.icon',['name'=>'tag','size'=>15]) Regras por produto / NCM
          </a>
        </div>
        
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-2"><span>Origem da mercadoria (ICMS)</span><input name="icms_origin_default" value="{{ $tax['icms_origin_default'] }}" maxlength="2" placeholder="0"></label>
        <label class="field col-2"><span>CSOSN padrão</span><input name="icms_csosn_default" value="{{ $tax['icms_csosn_default'] }}" maxlength="4"></label>
        <label class="field col-2"><span>CST ICMS padrão</span><input name="icms_cst_default" value="{{ $tax['icms_cst_default'] }}" maxlength="4"></label>
        <label class="field col-2"><span>CST PIS padrão</span><input name="pis_cst_default" value="{{ $tax['pis_cst_default'] }}" maxlength="4"></label>
        <label class="field col-2"><span>CST COFINS padrão</span><input name="cofins_cst_default" value="{{ $tax['cofins_cst_default'] }}" maxlength="4"></label>
        <label class="field col-2"><span>CST IPI padrão</span><input name="ipi_cst_default" value="{{ $tax['ipi_cst_default'] }}" maxlength="4"></label>

        <label class="field col-3"><span>ISS padrão (%)</span><input type="number" step="0.0001" min="0" max="100" name="iss_rate_default" value="{{ $tax['iss_rate_default'] }}"></label>
        <label class="field col-3"><span>Crédito Simples (%)</span><input type="number" step="0.0001" min="0" max="100" name="simple_credit_rate" value="{{ $tax['simple_credit_rate'] }}"></label>
        <label class="field col-3"><span>FCP padrão (%)</span><input type="number" step="0.0001" min="0" max="100" name="fcp_rate_default" value="{{ $tax['fcp_rate_default'] }}"></label>
        <label class="field col-3"><span>Classificação tributária</span><input name="tax_classification_code" value="{{ $tax['tax_classification_code'] }}"></label>

        <label class="field col-3"><span>CST IBS padrão</span><input name="ibs_cst_default" value="{{ $tax['ibs_cst_default'] }}"></label>
        <label class="field col-3"><span>CST CBS padrão</span><input name="cbs_cst_default" value="{{ $tax['cbs_cst_default'] }}"></label>
        <label class="field col-12"><span>Observações tributárias internas</span><textarea name="notes" rows="4">{{ $tax['notes'] }}</textarea></label>
      </div>
      
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='nfe')
  <form method="post" action="{{ route('settings.group.update','nfe') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Nota fiscal de produtos (NF-e)</h2></div></div>
      <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>'accounting']) }}">Contador e autorização de acesso ao XML da NF-e</a>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Ambiente</span><select name="environment"><option value="homologation" @selected($nfe['environment']==='homologation')>Homologação</option><option value="production" @selected($nfe['environment']==='production')>Produção</option></select></label>
        <label class="field col-2"><span>Série de homologação</span><input type="number" min="0" max="999" name="series" value="{{ $nfe['series'] }}"></label>
        <label class="field col-3"><span>Próximo número de homologação</span><input type="number" min="1" max="999999999" name="next_number" value="{{ $nfe['next_number'] }}"></label>
        <label class="field col-4"><span>CFOP padrão</span><input name="default_cfop" value="{{ $nfe['default_cfop'] }}"></label>
        <label class="field col-3"><span>Série de produção</span><input type="number" min="0" max="999" name="production_series" value="{{ $nfe['production_series'] }}"></label>
        <label class="field col-3"><span>Próximo número de produção</span><input type="number" min="1" max="999999999" name="production_next_number" value="{{ $nfe['production_next_number'] }}"></label>
        <label class="field col-12"><span>Tipo de operação usado por padrão</span><input name="default_nature" value="{{ $nfe['default_nature'] }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($nfe['enabled'])><span><strong>NF-e habilitada</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="production_enabled" value="1" @checked($nfe['production_enabled'])><span><strong>Permitir emissão em produção</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_from_sale" value="1" @checked($nfe['auto_from_sale'])><span><strong>Preparar NF-e após venda</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="send_email" value="1" @checked($nfe['send_email'])><span><strong>Enviar ao cliente</strong><small>Preferência de envio do XML/DANFE após autorização.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="print_danfe" value="1" @checked($nfe['print_danfe'])><span><strong>Imprimir DANFE</strong><small>Preferência padrão após autorização.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='nfce')
  <form method="post" action="{{ route('settings.group.update','nfce') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Nota fiscal do consumidor (NFC-e)</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Ambiente para novas emissões</span><select name="environment"><option value="homologation" @selected($nfce['environment']==='homologation')>Homologação</option><option value="production" @selected($nfce['environment']==='production')>Produção</option></select></label>
        <label class="field col-3"><span>CFOP padrão</span><input name="default_cfop" value="{{ $nfce['default_cfop'] ?? '' }}" maxlength="4" placeholder="Ex.: 5102"></label>
      </div>
      <div class="settings-panel-head"><div><h3>Parâmetros de homologação</h3></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-2"><span>Série de homologação</span><input type="number" min="0" max="999" name="series" value="{{ $nfce['series'] }}"></label>
        <label class="field col-3"><span>Próximo número de homologação</span><input type="number" min="1" max="999999999" name="next_number" value="{{ $nfce['next_number'] }}"></label>
        <label class="field col-2"><span>Identificador CSC de homologação</span><input name="csc_id" maxlength="6" value="{{ $nfce['csc_id'] }}"></label>
        <label class="field col-5"><span>Código de segurança (CSC) de homologação</span><input type="password" autocomplete="new-password" name="csc_token" placeholder="{{ $nfce['csc_token'] ? 'CSC de homologação configurado' : 'Informe o CSC de homologação' }}"></label>
      </div>
      <div class="settings-panel-head"><div><h3>Parâmetros de produção</h3>
        </div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-2"><span>Série de produção</span><input type="number" min="0" max="999" name="production_series" value="{{ $nfce['production_series'] ?? '' }}" placeholder="Ex.: 1"></label>
        <label class="field col-3"><span>Próximo número em produção</span><input type="number" min="1" max="999999999" name="production_next_number" value="{{ $nfce['production_next_number'] ?? '' }}" placeholder="Conforme histórico de produção"></label>
        <label class="field col-2"><span>Identificador CSC de produção</span><input name="production_csc_id" maxlength="6" value="{{ $nfce['production_csc_id'] ?? '' }}" placeholder="Identificador fornecido pela SEFAZ"></label>
        <label class="field col-5"><span>CSC de produção</span><input type="password" autocomplete="new-password" name="production_csc_token" placeholder="{{ !empty($nfce['production_csc_token']) ? 'CSC de produção configurado' : 'Informe o CSC de produção' }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($nfce['enabled'])><span><strong>NFC-e habilitada</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="production_enabled" value="1" @checked($nfce['production_enabled'])><span><strong>Permitir emissão em produção</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="advanced_operations_production_approved" value="1" @checked($nfce['advanced_operations_production_approved'] ?? false)><span><strong>Permitir eventos e contingência em produção</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_from_pdv" value="1" @checked($nfce['auto_from_pdv'])><span><strong>Transmitir NFC-e automaticamente pelo PDV</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="print_danfe" value="1" @checked($nfce['print_danfe'])><span><strong>Imprimir DANFE NFC-e</strong></span></label>
      </div>
      
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='nfse')
  <form method="post" action="{{ route('settings.group.update','nfse') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Nota fiscal de serviços (NFS-e)</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Ambiente</span><select name="environment"><option value="homologation" @selected($nfse['environment']==='homologation')>Homologação</option><option value="production" @selected($nfse['environment']==='production')>Produção</option></select></label>
        <label class="field col-4"><span>Padrão usado pela prefeitura</span><input name="provider" value="{{ $nfse['provider'] }}" placeholder="Ex.: Nacional / prefeitura / provedor"></label>
        <label class="field col-3"><span>Código do município</span><input name="municipality_code" value="{{ $nfse['municipality_code'] }}"></label>
        <label class="field col-2"><span>Série RPS</span><input name="series" value="{{ $nfse['series'] }}"></label>
        <label class="field col-3"><span>Próximo número do RPS</span><input type="number" min="1" name="next_rps" value="{{ $nfse['next_rps'] }}"></label>
        <label class="field col-5"><span>Código tributário do serviço</span><input name="default_service_tax_code" value="{{ $nfse['default_service_tax_code'] }}"></label>
        <label class="field col-4"><span>Usuário da prefeitura</span><input name="municipal_login" value="{{ $nfse['municipal_login'] }}"></label>
        <label class="field col-6"><span>Senha ou chave de acesso da prefeitura</span><input type="password" name="municipal_password" placeholder="{{ $nfse['municipal_password'] ? 'Credencial já configurada' : 'Informe somente quando necessário' }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($nfse['enabled'])><span><strong>NFS-e habilitada</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="production_enabled" value="1" @checked($nfse['production_enabled'])><span><strong>Permitir emissão em produção</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_from_sale" value="1" @checked($nfse['auto_from_sale'])><span><strong>Integrar com vendas de serviço</strong><small>Preferência para preparar NFS-e a partir da venda.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="withhold_iss_default" value="1" @checked($nfse['withhold_iss_default'])><span><strong>ISS retido por padrão</strong><small>Preferência inicial; o documento ainda deve respeitar a operação real.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='cte')
  <form method="post" action="{{ route('settings.group.update','cte') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Documentos de transporte (CT-e e MDF-e)</h2></div></div>
      <div class="settings-subsection"><h3>CT-e</h3>
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-3"><span>Ambiente</span><select name="cte_environment"><option value="homologation" @selected($cte['cte_environment']==='homologation')>Homologação</option><option value="production" @selected($cte['cte_environment']==='production')>Produção</option></select></label>
          <label class="field col-2"><span>Série</span><input type="number" min="0" max="999" name="cte_series" value="{{ $cte['cte_series'] }}"></label>
          <label class="field col-3"><span>Próximo número</span><input type="number" min="1" name="cte_next_number" value="{{ $cte['cte_next_number'] }}"></label>
          <label class="field col-2"><span>RNTRC</span><input name="rntrc" value="{{ $cte['rntrc'] }}"></label>
          <label class="field col-2"><span>CFOP padrão</span><input name="default_cfop" value="{{ $cte['default_cfop'] }}"></label>
        </div>
        <div class="settings-switch-grid"><label class="settings-switch"><input type="checkbox" name="cte_enabled" value="1" @checked($cte['cte_enabled'])><span><strong>CT-e habilitado</strong></span></label><label class="settings-switch"><input type="checkbox" name="cte_production_enabled" value="1" @checked($cte['cte_production_enabled'])><span><strong>Permitir produção</strong></span></label></div>
      </div>
      <div class="settings-subsection"><h3>MDF-e</h3>
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-3"><span>Ambiente</span><select name="mdfe_environment"><option value="homologation" @selected($cte['mdfe_environment']==='homologation')>Homologação</option><option value="production" @selected($cte['mdfe_environment']==='production')>Produção</option></select></label>
          <label class="field col-2"><span>Série</span><input type="number" min="0" max="999" name="mdfe_series" value="{{ $cte['mdfe_series'] }}"></label>
          <label class="field col-3"><span>Próximo número</span><input type="number" min="1" name="mdfe_next_number" value="{{ $cte['mdfe_next_number'] }}"></label>
        </div>
        <div class="settings-switch-grid"><label class="settings-switch"><input type="checkbox" name="mdfe_enabled" value="1" @checked($cte['mdfe_enabled'])><span><strong>MDF-e habilitado</strong></span></label><label class="settings-switch"><input type="checkbox" name="mdfe_production_enabled" value="1" @checked($cte['mdfe_production_enabled'])><span><strong>Permitir produção</strong></span></label></div>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='accounting')
  <form method="post" action="{{ route('settings.group.update','accounting') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>'nfe']) }}">Configuração da NF-e</a>
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-6"><span>Escritório contábil</span><input name="office_name" value="{{ old('office_name',$accounting['office_name']) }}"></label>
        <label class="field col-6"><span>Contador responsável</span><input name="accountant_name" value="{{ old('accountant_name',$accounting['accountant_name']) }}"></label>
        <label class="field col-3"><span>CPF / CNPJ do contador</span><input name="accountant_document" value="{{ old('accountant_document',$accounting['accountant_document']) }}" inputmode="numeric" maxlength="20" aria-describedby="accountantXmlHelp"></label>
        <label class="field col-3"><span>CRC</span><input name="crc" value="{{ old('crc',$accounting['crc']) }}"></label>
        <label class="field col-3"><span>E-mail</span><input type="email" name="email" value="{{ old('email',$accounting['email']) }}"></label>
        <label class="field col-3"><span>Telefone</span><input name="phone" value="{{ $accounting['phone'] }}"></label>
        <label class="field col-6"><span>Sistema contábil de destino</span><input name="accounting_system" value="{{ $accounting['accounting_system'] }}" placeholder="Ex.: Domínio, Alterdata, outro"></label>
        <div class="field col-6"><span>Formato do arquivo</span><div class="inline-note"><strong>CSV</strong> · arquivo para importação no sistema contábil.</div></div>
        <label class="field col-12"><span>Observações</span><textarea name="notes" rows="4">{{ $accounting['notes'] }}</textarea></label>
      </div>
      <div class="settings-switch-grid">
        <input type="hidden" name="accountant_xml_authorized" value="0">
        <label class="settings-switch"><input type="checkbox" name="accountant_xml_authorized" value="1" @checked(old('accountant_xml_authorized',$accounting['accountant_xml_authorized']))><span><strong>Autorizar o contador a acessar o XML completo da NF-e</strong><small id="accountantXmlHelp">O CPF/CNPJ informado terá acesso aos dados completos das novas NF-e. Informe um documento válido para autorizar.</small></span></label>
        <input type="hidden" name="nfe_ba_sefaz_xml_authorized" value="0">
        <label class="settings-switch"><input type="checkbox" name="nfe_ba_sefaz_xml_authorized" value="1" @checked(old('nfe_ba_sefaz_xml_authorized',$accounting['nfe_ba_sefaz_xml_authorized']))><span><strong>Autorizar acesso da SEFAZ-BA quando não houver contador autorizado</strong><small>Nas NF-e da Bahia, autoriza o CNPJ 13.937.073/0001-56 a acessar o XML completo.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="cost_center_enabled" value="1" @checked($accounting['cost_center_enabled'])><span><strong>Usar centros de custo</strong><small>Prepara classificações financeiras para detalhamento contábil.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="automatic_monthly_export" value="1" @checked($accounting['automatic_monthly_export'])><span><strong>Exportação mensal automática</strong><small>Gera automaticamente o CSV do mês anterior no primeiro dia de cada mês.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

<section class="editor-panel settings-panel settings-accounting-export">
  <div class="settings-panel-head">
    <div><h2>Enviar dados para a contabilidade</h2></div>
  </div>
  <form method="post" action="{{ route('settings.accounting.export') }}" class="settings-inline-form">
    @csrf
    <label class="field"><span>Mês de referência</span><input type="month" name="month" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" required></label>
    <div></div>
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'download','size'=>15]) Gerar arquivo contábil</button>
  </form>
  @if(count($accountingExports))
    <div class="settings-export-history">
      <strong>Arquivos gerados recentemente</strong>
      @foreach(array_slice($accountingExports,0,6) as $export)
        <span>{{ $export['name'] }} · {{ number_format($export['size']/1024,1,',','.') }} KB</span>
      @endforeach
    </div>
  @endif
</section>

@elseif($tab==='users')
  <div class="settings-users-page">
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Adicionar pessoa à equipe</h2></div>
      </div>

      <form method="post" action="{{ route('settings.users.store') }}" class="settings-user-form" autocomplete="off">
        @csrf
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-3"><span>Nome *</span><input name="name" value="{{ old('name') }}" required autocomplete="off"></label>
          <label class="field col-3"><span>Usuário de acesso *</span><input name="username" value="{{ old('username') }}" required maxlength="50" minlength="3" pattern="[a-z][a-z0-9._-]{2,49}" autocomplete="off" placeholder="Ex.: autonunes"></label>
          <label class="field col-3"><span>E-mail *</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="off"></label>
          <label class="field col-3"><span>Senha inicial *</span><input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
          <label class="field col-3"><span>Função</span>
            <select name="role">
              <option value="admin" @selected(old('role')==='admin')>Administrador</option>
              <option value="manager" @selected(old('role')==='manager')>Gerente</option>
              <option value="finance" @selected(old('role')==='finance')>Financeiro</option>
              <option value="sales" @selected(old('role')==='sales')>Vendas</option>
              <option value="operator" @selected(old('role')==='operator')>Operador</option>
            </select>
          </label>
        </div>

        <div class="settings-permissions-block">
          <h3>O que esta pessoa pode acessar?</h3>
          <p>Administrador possui acesso completo. Para os demais perfis, marque as áreas liberadas.</p>
          <div class="settings-permissions-toolbar">
            <button type="button" class="btn btn-light btn-sm" data-permission-all>Permitir todas</button>
            <button type="button" class="btn btn-light btn-sm" data-permission-none>Desmarcar todas</button>
          </div>
          <div class="settings-permission-grid">
            @foreach($permissions as $key=>$label)
              <label class="permission-switch">
                <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key,old('permissions',[]),true))>
                <span class="permission-switch-track"></span>
                <span class="permission-switch-label">{{ $label }}</span>
              </label>
            @endforeach
          </div>
        </div>

        <div class="settings-user-form-actions">
          <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Criar usuário</button>
        </div>
      </form>
    </section>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head settings-users-heading">
        <div><h2>Usuários cadastrados</h2><p>{{ $users->count() }} {{ $users->count()===1?'usuário cadastrado':'usuários cadastrados' }}.</p></div>
      </div>

      <div class="settings-user-list">
        @forelse($users as $user)
          <details class="settings-user-row" @if(session('edited_user_id')==$user->id) open @endif>
            <summary>
              <div class="settings-user-summary-main">
                <span class="settings-user-avatar">{{ strtoupper(mb_substr($user->name,0,1)) }}</span>
                <span class="settings-user-summary-text">
                  <strong>{{ $user->name }}</strong>
                  <small>{{ $user->username ? '@'.$user->username.' · ' : '' }}{{ $user->email }} · {{ match($user->role ?? 'admin'){ 'manager'=>'Gerente','finance'=>'Financeiro','sales'=>'Vendas','operator'=>'Operador',default=>'Administrador' } }}</small>
                </span>
              </div>
              <div class="settings-user-summary-status">
                <span class="status {{ $user->is_active?'status-ok':'status-muted' }}">{{ $user->is_active?'Ativo':'Inativo' }}</span>
                @include('partials.icon',['name'=>'down','size'=>15])
              </div>
            </summary>

            <form method="post" action="{{ route('settings.users.update',$user) }}" class="settings-user-edit-form" autocomplete="off">
              @csrf
              @method('PUT')

              <div class="editor-grid cols-12 settings-grid">
                <label class="field col-3"><span>Nome *</span><input name="name" value="{{ $user->name }}" required autocomplete="off"></label>
                <label class="field col-3"><span>Usuário de acesso *</span><input name="username" value="{{ $user->username }}" required maxlength="50" minlength="3" pattern="[a-z][a-z0-9._-]{2,49}" autocomplete="off" placeholder="Defina um usuário"></label>
                <label class="field col-3"><span>E-mail *</span><input type="email" name="email" value="{{ $user->email }}" required autocomplete="off"></label>
                <label class="field col-3"><span>Nova senha</span><input type="password" name="password" minlength="8" placeholder="Deixe vazio para manter" autocomplete="new-password"></label>
                <label class="field col-3"><span>Função</span>
                  <select name="role">
                    @foreach(['admin'=>'Administrador','manager'=>'Gerente','finance'=>'Financeiro','sales'=>'Vendas','operator'=>'Operador'] as $k=>$v)
                      <option value="{{ $k }}" @selected(($user->role ?? 'admin')===$k)>{{ $v }}</option>
                    @endforeach
                  </select>
                </label>
              </div>

              <div class="settings-permissions-block compact">
                <h3>O que esta pessoa pode acessar?</h3>
                <div class="settings-permissions-toolbar">
                  <button type="button" class="btn btn-light btn-sm" data-permission-all>Permitir todas</button>
                  <button type="button" class="btn btn-light btn-sm" data-permission-none>Desmarcar todas</button>
                </div>
                <div class="settings-permission-grid">
                  @foreach($permissions as $key=>$label)
                    <label class="permission-switch">
                      <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(($user->role ?? 'admin')==='admin' || in_array($key,$user->permissions ?? [],true))>
                      <span class="permission-switch-track"></span>
                      <span class="permission-switch-label">{{ $label }}</span>
                    </label>
                  @endforeach
                </div>
              </div>

              <div class="settings-user-edit-actions">
                <label class="switch-field settings-user-active">
                  <input type="hidden" name="is_active" value="0">
                  <input type="checkbox" name="is_active" value="1" @checked($user->is_active)>
                  <span class="switch-track"></span>
                  <strong>Usuário ativo</strong>
                </label>
                <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar usuário</button>
              </div>
            </form>
          </details>
        @empty
          <div class="empty-state compact">Nenhum usuário cadastrado.</div>
        @endforelse
      </div>
    </section>
  </div>

@elseif($tab==='integrations')
  <form method="post" action="{{ route('settings.group.update','integrations') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Envio de e-mails</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4"><span>Servidor SMTP</span><input name="smtp_host" value="{{ $integrations['smtp_host'] }}"></label>
        <label class="field col-2"><span>Porta</span><input type="number" min="1" max="65535" name="smtp_port" value="{{ $integrations['smtp_port'] }}"></label>
        <label class="field col-2"><span>Criptografia</span><select name="smtp_encryption"><option value="">Nenhuma</option><option value="tls" @selected($integrations['smtp_encryption']==='tls')>TLS</option><option value="ssl" @selected($integrations['smtp_encryption']==='ssl')>SSL</option></select></label>
        <label class="field col-4"><span>Usuário</span><input name="smtp_username" value="{{ $integrations['smtp_username'] }}"></label>
        <label class="field col-4"><span>Senha</span><input type="password" name="smtp_password" placeholder="{{ $integrations['smtp_password'] ? 'Senha já configurada' : '' }}"></label>
        <label class="field col-4"><span>E-mail usado para enviar</span><input type="email" name="smtp_from_address" value="{{ $integrations['smtp_from_address'] }}"></label>
        <label class="field col-4"><span>Nome exibido no envio</span><input name="smtp_from_name" value="{{ $integrations['smtp_from_name'] }}"></label>
      </div>
      <label class="settings-switch settings-switch-single"><input type="checkbox" name="smtp_enabled" value="1" @checked($integrations['smtp_enabled'])><span><strong>Ativar envio pelo servidor configurado</strong></span></label>
    </section>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Integrações para desenvolvedores</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-6"><span>Webhook</span><input type="url" name="webhook_url" value="{{ $integrations['webhook_url'] }}" placeholder="https://..."></label>
        <label class="field col-6"><span>Segredo do webhook</span><input type="password" name="webhook_secret" placeholder="{{ $integrations['webhook_secret'] ? 'Segredo já configurado' : '' }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="api_enabled" value="1" @checked($integrations['api_enabled'])><span><strong>Permitir acesso pela API</strong></span></label>
        <label class="settings-switch"><input type="checkbox" name="webhook_enabled" value="1" @checked($integrations['webhook_enabled'])><span><strong>Enviar avisos automáticos para integrações (webhook)</strong></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

<section class="editor-panel settings-panel">
  <div class="settings-panel-head">
    <div><h2>Acesso de integração (API)</h2></div>
  </div>
  <div class="settings-api-grid">
    <div>
      <strong>Status</strong>
      <span class="status {{ $integrations['api_enabled']?'status-ok':'status-muted' }}">{{ $integrations['api_enabled']?'Ativa':'Desativada' }}</span>
    </div>
    <details>
      <summary>Endereços para integração</summary>
      <div><strong>Produtos</strong><code>/api/lumeron/products</code></div>
      <div><strong>Clientes</strong><code>/api/lumeron/customers</code></div>
      <div><strong>Vendas</strong><code>/api/lumeron/sales</code></div>
    </details>
  </div>

  @if(session('api_token_plain'))
    <label class="field settings-api-token">
      <span>Nova chave de acesso — copie agora</span>
      <textarea readonly rows="2" data-copy-source="nextor-api-token">{{ session('api_token_plain') }}</textarea>
    </label>
    <button type="button" class="btn btn-secondary" data-copy-target="nextor-api-token">@include('partials.icon',['name'=>'copy','size'=>15]) Copiar chave</button>
  @else
    <div class="inline-note">{{ $apiTokenConfigured ? 'Há uma chave configurada. Ela não é exibida novamente.' : 'Nenhuma chave de acesso configurada.' }}</div>
  @endif

  <div class="settings-api-actions">
    <button class="btn btn-secondary" type="submit" form="nextor-api-token-form">{{ $apiTokenConfigured ? 'Substituir chave' : 'Gerar chave' }}</button>
  </div>

  <div class="settings-webhook-stats">
    <span>Pendentes: <strong>{{ (int)($webhookStats['pending'] ?? 0)+(int)($webhookStats['retry'] ?? 0) }}</strong></span>
    <span>Entregues: <strong>{{ (int)($webhookStats['delivered'] ?? 0) }}</strong></span>
    <span>Falhas: <strong>{{ (int)($webhookStats['failed'] ?? 0) }}</strong></span>
  </div>
</section>

<form id="nextor-api-token-form" method="post" action="{{ route('settings.api-token.regenerate') }}"
  @if($apiTokenConfigured) data-confirm-submit="Substituir a chave de acesso? As integrações que usam a chave atual precisarão ser atualizadas." @endif hidden>
  @csrf
</form>

@elseif($tab==='system')
  <form method="post" action="{{ route('settings.group.update','system') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Aparência e preferências</h2></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Linhas por página</span><select name="rows_per_page">@foreach(['10','25','50','100'] as $n)<option value="{{ $n }}" @selected((string)$system['rows_per_page']===$n)>{{ $n }}</option>@endforeach</select></label>
        <label class="field col-3"><span>Tempo de espera da pesquisa</span><input type="number" min="120" max="1000" name="search_delay" value="{{ $system['search_delay'] }}"><small>Em milissegundos (300 equivale a 0,3 segundo).</small></label>
        <label class="field col-3"><span>Formato de data</span><select name="date_format"><option value="d/m/Y" @selected($system['date_format']==='d/m/Y')>DD/MM/AAAA</option><option value="Y-m-d" @selected($system['date_format']==='Y-m-d')>AAAA-MM-DD</option></select></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="compact_mode" value="1" @checked($system['compact_mode'])><span><strong>Exibir mais informações na tela</strong><small>Exibe linhas e controles mais próximos, para caber mais informação.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="show_tutorials" value="1" @checked($system['show_tutorials'])><span><strong>Mostrar dicas e atalhos</strong><small>Exibe dicas e atalhos durante o uso.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="confirm_destructive_actions" value="1" @checked($system['confirm_destructive_actions'])><span><strong>Pedir confirmação antes de excluir ou cancelar</strong><small>Ajuda a evitar exclusões ou cancelamentos por engano.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>
@endif
</main>
@endsection
