@extends('layouts.app')
@section('title','Configurações')
@section('titleMeta','Central do Nextor')
@section('content')

@php
$primaryTabs=[
  'general'=>[
    'label'=>'GERAL','icon'=>'settings',
    'members'=>['general','printing','catalog'],
  ],
  'chart'=>[
    'label'=>'PLANO DE CONTAS','icon'=>'layers',
    'members'=>['chart'],
  ],
  'accounts'=>[
    'label'=>'CONTAS CAIXA','icon'=>'money',
    'members'=>['accounts'],
  ],
  'operations'=>[
    'label'=>'OPERAÇÕES','icon'=>'sales',
    'members'=>['operations','inventory','pdv'],
  ],
  'payments'=>[
    'label'=>'FORMAS PGTO','icon'=>'receipt',
    'members'=>['payments'],
  ],
  'billing'=>[
    'label'=>'BOLETOS / COBRANÇAS','icon'=>'money',
    'members'=>['billing'],
  ],
  'fiscal'=>[
    'label'=>'FISCAL','icon'=>'shield',
    'members'=>['fiscal','nfe','nfce','nfse','cte'],
  ],
  'tax'=>[
    'label'=>'DADOS TRIBUTÁRIOS','icon'=>'layers',
    'members'=>['tax'],
  ],
  'accounting'=>[
    'label'=>'CONTÁBIL','icon'=>'services',
    'members'=>['accounting'],
  ],
  'users'=>[
    'label'=>'USUÁRIOS','icon'=>'customers',
    'members'=>['users'],
  ],
  'integrations'=>[
    'label'=>'API / INTEGRAÇÕES','icon'=>'layers',
    'members'=>['integrations'],
  ],
  'system'=>[
    'label'=>'SISTEMA','icon'=>'settings',
    'members'=>['system'],
  ],
];

$subTabs=[
  'general'=>[
    'general'=>['Dados da empresa','settings'],
    'printing'=>['Impressão e identidade','receipt'],
    'catalog'=>['Produtos e serviços','products'],
  ],
  'operations'=>[
    'operations'=>['Vendas e operações','sales'],
    'inventory'=>['Estoque','stock'],
    'pdv'=>['PDV','pdv'],
  ],
  'fiscal'=>[
    'fiscal'=>['Geral fiscal','shield'],
    'nfe'=>['NF-e','receipt'],
    'nfce'=>['NFC-e','receipt'],
    'nfse'=>['NFS-e','services'],
    'cte'=>['CT-e / MDF-e','stock'],
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

<nav class="settings-main-tabs" aria-label="Configurações">
  @foreach($primaryTabs as $key=>$item)
    <a href="{{ route('settings.index',['tab'=>$key]) }}"
       class="{{ $activePrimary===$key?'active':'' }}">
      @include('partials.icon',['name'=>$item['icon'],'size'=>13])
      <span>{{ $item['label'] }}</span>
    </a>
  @endforeach
</nav>

@if(isset($subTabs[$activePrimary]))
  <nav class="settings-subtabs" aria-label="Seção de configurações">
    @foreach($subTabs[$activePrimary] as $key=>$item)
      <a href="{{ route('settings.index',['tab'=>$key]) }}" class="{{ $tab===$key?'active':'' }}">
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
        <div><h2>Dados gerais da empresa</h2><p>Identificação usada em vendas, financeiro, documentos e impressões.</p></div>
        
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
        <label class="field col-2"><span>Cód. IBGE</span><input name="city_ibge_code" value="{{ old('city_ibge_code',$company->city_ibge_code) }}"></label>

        <label class="field col-8"><span>Endereço</span><input name="address" value="{{ old('address',$company->address) }}" data-address-input></label>
        <label class="field col-2"><span>Número</span><input name="address_number" value="{{ old('address_number',$company->address_number) }}"></label>
        <label class="field col-2"><span>Bairro</span><input name="district" value="{{ old('district',$company->district) }}" data-district-input></label>
        <label class="field col-8"><span>Complemento</span><input name="address_complement" value="{{ old('address_complement',$company->address_complement) }}"></label>
        <label class="field col-4"><span>Fuso horário</span><input name="timezone" value="{{ old('timezone',$company->timezone ?: 'America/Sao_Paulo') }}" required></label>
      </div>
    </section>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Dados tributários da empresa</h2><p>Cadastro-base para os módulos fiscais e de serviços.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4"><span>Regime tributário</span>
          <select name="tax_regime">
            <option value="">Selecione</option>
            @foreach(['simples_nacional'=>'Simples Nacional','lucro_presumido'=>'Lucro Presumido','lucro_real'=>'Lucro Real','mei'=>'MEI','outro'=>'Outro'] as $key=>$label)
              <option value="{{ $key }}" @selected(old('tax_regime',$company->tax_regime)===$key)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <label class="field col-2"><span>CRT</span><input name="crt" value="{{ old('crt',$company->crt) }}" maxlength="4" placeholder="Ex.: 1"></label>
        <label class="field col-3"><span>Alíquota Simples (%)</span><input type="number" step="0.0001" min="0" max="100" name="simple_rate" value="{{ old('simple_rate',$company->simple_rate) }}"></label>
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
        <div><h2>Impressão e identidade</h2><p>Identidade usada em recibos, comprovantes, pedidos e relatórios.</p></div>
        
      </div>
      <div class="settings-brand-grid">
        <div class="settings-logo-box">
          @if($company->logo_path)
            <img src="{{ asset('storage/'.$company->logo_path) }}" alt="Logomarca da empresa">
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
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
  </div>
</form>

@elseif($tab==='catalog')
  <form method="post" action="{{ route('settings.group.update','catalog') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Produtos e serviços</h2><p>Padrões aplicados aos novos cadastros do catálogo.</p></div>
        
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
    <div class="settings-panel-head"><div><h2>Plano de contas</h2><p>Categorias usadas nas receitas, despesas e relatórios financeiros.</p></div></div>
    <form method="post" action="{{ route('finance.categories.store') }}" class="settings-inline-form">
      @csrf
      <label class="field"><span>Nome</span><input name="name" maxlength="120" required placeholder="Ex.: Receita de vendas"></label>
      <label class="field"><span>Tipo</span><select name="type"><option value="income">Receita</option><option value="expense">Despesa</option></select></label>
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar</button>
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
    <div class="settings-panel-head"><div><h2>Contas financeiras</h2><p>Caixas, bancos e contas digitais usados nas movimentações.</p></div></div>
    <form method="post" action="{{ route('finance.accounts.store') }}" class="settings-inline-form settings-inline-4">
      @csrf
      <label class="field"><span>Nome</span><input name="name" maxlength="120" required></label>
      <label class="field"><span>Tipo</span><select name="type"><option value="cash">Caixa</option><option value="bank">Banco</option><option value="digital">Conta digital</option><option value="other">Outra</option></select></label>
      <label class="field"><span>Saldo inicial</span><input type="number" data-number-kind="money" name="opening_balance" step="0.01" min="0" value="0.00" required></label>
      <button class="btn btn-primary">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar</button>
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
      <div class="settings-panel-head"><div><h2>Operações de vendas e estoque</h2><p>Padrões usados no fluxo comercial do Nextor.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Validade padrão do orçamento</span><input type="number" name="quote_valid_days" value="{{ $operations['quote_valid_days'] }}" min="0"><small>Dias</small></label>
        <label class="field col-3"><span>Vencimento padrão</span><input type="number" name="default_due_days" value="{{ $operations['default_due_days'] }}" min="0"><small>Dias após a venda</small></label>
        <label class="field col-6"><span>Conta financeira padrão</span><select name="default_financial_account_id"><option value="">Sem padrão</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string)$operations['default_financial_account_id']===(string)$a->id)>{{ $a->name }}</option>@endforeach</select></label>
        <label class="field col-6"><span>Categoria padrão de receita</span><select name="default_income_category_id"><option value="">Sem padrão</option>@foreach($categories->where('type','income') as $c)<option value="{{ $c->id }}" @selected((string)$operations['default_income_category_id']===(string)$c->id)>{{ $c->name }}</option>@endforeach</select></label>
        <label class="field col-6"><span>Categoria padrão de despesa</span><select name="default_expense_category_id"><option value="">Sem padrão</option>@foreach($categories->where('type','expense') as $c)<option value="{{ $c->id }}" @selected((string)$operations['default_expense_category_id']===(string)$c->id)>{{ $c->name }}</option>@endforeach</select></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="default_final_consumer" value="1" @checked($operations['default_final_consumer'])><span><strong>Consumidor final por padrão</strong><small>Novas vendas iniciam marcadas como consumidor final.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_finance_sale" value="1" @checked($operations['auto_finance_sale'])><span><strong>Gerar financeiro da venda</strong><small>Integra vendas e parcelas ao financeiro.</small></span></label>
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
        <div><h2>Estoque</h2><p>Regras gerais de saldo, alertas e movimentações.</p></div>
        
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Casas decimais da quantidade</span>
          <select name="stock_decimal_places">
            @foreach(['0','1','2','3'] as $places)<option value="{{ $places }}" @selected((string)$inventory['stock_decimal_places']===$places)>{{ $places }}</option>@endforeach
          </select>
        </label>
        <label class="field col-9"><span>Motivo padrão para ajuste</span><input name="default_adjustment_reason" value="{{ $inventory['default_adjustment_reason'] }}" maxlength="255" placeholder="Ex.: Ajuste manual de estoque"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="allow_negative_stock" value="1" @checked($inventory['allow_negative_stock'])><span><strong>Permitir estoque negativo</strong><small>Autoriza venda mesmo quando o saldo controlado for insuficiente.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="minimum_stock_alerts" value="1" @checked($inventory['minimum_stock_alerts'])><span><strong>Alertar estoque mínimo</strong><small>Habilita avisos quando o produto atingir o limite configurado.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>15]) Salvar</button>
  </div>
</form>

@elseif($tab==='payments')
  <section class="editor-panel settings-panel">
    <div class="settings-panel-head"><div><h2>Formas de pagamento</h2><p>Opções compartilhadas entre vendas, financeiro e PDV.</p></div></div>
    <form method="post" action="{{ route('settings.payment-methods.store') }}" class="settings-payment-form">
      @csrf
      <label class="field"><span>Código</span><input name="code" required placeholder="ex.: voucher"></label>
      <label class="field"><span>Nome</span><input name="name" required placeholder="Vale / voucher"></label>
      <label class="field"><span>Tipo</span><select name="kind"><option value="cash">Dinheiro</option><option value="pix">PIX</option><option value="card">Cartão</option><option value="bank_slip">Boleto</option><option value="transfer">Transferência</option><option value="other">Outro</option></select></label>
      <label class="field"><span>Conta padrão</span><select name="financial_account_id"><option value="">Nenhuma</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
      <label class="field"><span>Taxa %</span><input type="number" step="0.0001" min="0" name="fee_percent" value="0"></label>
      <label class="field"><span>Taxa fixa</span><input type="number" step="0.01" min="0" name="fee_fixed" value="0"></label>
      <label class="field"><span>Crédito em</span><input type="number" min="0" name="settlement_days" value="0"><small>Dias</small></label>
      <label class="field"><span>Ordem</span><input type="number" min="0" name="sort_order" value="100"></label>
      <label class="check-line"><input type="checkbox" name="is_active" value="1" checked><span>Ativa</span></label>
      <label class="check-line"><input type="checkbox" name="pdv_enabled" value="1" checked><span>Disponível no PDV</span></label>
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Criar</button>
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
            <label class="field"><span>Tipo</span><select name="kind">@foreach(['cash'=>'Dinheiro','pix'=>'PIX','card'=>'Cartão','bank_slip'=>'Boleto','transfer'=>'Transferência','other'=>'Outro'] as $k=>$v)<option value="{{ $k }}" @selected($method->kind===$k)>{{ $v }}</option>@endforeach</select></label>
            <label class="field"><span>Conta padrão</span><select name="financial_account_id"><option value="">Nenhuma</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($method->financial_account_id===$a->id)>{{ $a->name }}</option>@endforeach</select></label>
            <label class="field"><span>Taxa %</span><input type="number" step="0.0001" min="0" name="fee_percent" value="{{ $method->fee_percent }}"></label>
            <label class="field"><span>Taxa fixa</span><input type="number" step="0.01" min="0" name="fee_fixed" value="{{ $method->fee_fixed }}"></label>
            <label class="field"><span>Crédito em</span><input type="number" min="0" name="settlement_days" value="{{ $method->settlement_days }}"></label>
            <label class="field"><span>Ordem</span><input type="number" min="0" name="sort_order" value="{{ $method->sort_order }}"></label>
            <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($method->is_active)><span>Ativa</span></label>
            <label class="check-line"><input type="checkbox" name="pdv_enabled" value="1" @checked($method->pdv_enabled)><span>PDV</span></label>
            <button class="btn btn-primary">Salvar</button>
          </form>
        </details>
      @endforeach
    </div>
  </section>

@elseif($tab==='pdv')
  <form method="post" action="{{ route('settings.group.update','pdv') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>PDV</h2><p>Comportamento padrão do ponto de venda.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-6"><span>Forma de pagamento padrão</span><select name="default_payment_method"><option value="">Sem padrão</option>@foreach($paymentMethods->where('is_active',true)->where('pdv_enabled',true) as $m)<option value="{{ $m->code }}" @selected($pdv['default_payment_method']===$m->code)>{{ $m->name }}</option>@endforeach</select></label>
        <label class="field col-3"><span>Largura do cupom</span><select name="receipt_width"><option value="58" @selected((string)$pdv['receipt_width']==='58')>58 mm</option><option value="80" @selected((string)$pdv['receipt_width']==='80')>80 mm</option></select></label>
        <label class="field col-3"><span>Cópias</span><input type="number" min="1" max="5" name="receipt_copies" value="{{ $pdv['receipt_copies'] }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="require_customer" value="1" @checked($pdv['require_customer'])><span><strong>Exigir cliente</strong><small>Impede concluir o PDV sem cliente selecionado.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="allow_discount" value="1" @checked($pdv['allow_discount'])><span><strong>Permitir desconto</strong><small>Libera desconto por item no PDV.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="require_cash_opening" value="1" @checked($pdv['require_cash_opening'])><span><strong>Exigir abertura de caixa</strong><small>Preparação para controle de turnos de caixa.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="show_stock" value="1" @checked($pdv['show_stock'])><span><strong>Mostrar estoque</strong><small>Exibe saldo disponível na busca de produtos.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_nfce" value="1" @checked($pdv['auto_nfce'])><span><strong>NFC-e automática</strong><small>Preferência para emissão após concluir venda quando o emissor estiver habilitado.</small></span></label>
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
      <div class="settings-panel-head"><div><h2>Cobranças, boleto e PIX</h2><p>Parâmetros para provedores financeiros e geração futura de cobranças.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4"><span>Provedor</span><input name="provider" value="{{ $billing['provider'] }}" placeholder="Ex.: banco / gateway"></label>
        <label class="field col-4"><span>Conta financeira padrão</span><select name="default_financial_account_id"><option value="">Nenhuma</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string)$billing['default_financial_account_id']===(string)$a->id)>{{ $a->name }}</option>@endforeach</select></label>
        <label class="field col-4"><span>Chave PIX</span><input name="pix_key" value="{{ $billing['pix_key'] }}"></label>
        <label class="field col-3"><span>Vencimento padrão</span><input type="number" min="0" name="default_due_days" value="{{ $billing['default_due_days'] }}"><small>Dias</small></label>
        <label class="field col-3"><span>Multa (%)</span><input type="number" step="0.0001" min="0" name="fine_percent" value="{{ $billing['fine_percent'] }}"></label>
        <label class="field col-3"><span>Juros mensal (%)</span><input type="number" step="0.0001" min="0" name="interest_monthly_percent" value="{{ $billing['interest_monthly_percent'] }}"></label>
        <label class="field col-6"><span>API key</span><input type="password" name="api_key" placeholder="{{ $billing['api_key'] ? 'Credencial já configurada' : 'Informe a credencial' }}"></label>
        <label class="field col-6"><span>API secret</span><input type="password" name="api_secret" placeholder="{{ $billing['api_secret'] ? 'Credencial já configurada' : 'Informe a credencial' }}"></label>
        <label class="field col-12"><span>Instruções padrão</span><textarea name="instructions" rows="4">{{ $billing['instructions'] }}</textarea></label>
      </div>
      <label class="settings-switch settings-switch-single"><input type="checkbox" name="enabled" value="1" @checked($billing['enabled'])><span><strong>Habilitar integração de cobranças</strong><small>Ativa o cadastro do provedor; a emissão depende do conector implementado.</small></span></label>
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
        <div class="settings-panel-head"><div><h2>Configuração fiscal geral</h2><p>Base compartilhada por NF-e, NFC-e, NFS-e, CT-e e MDF-e.</p></div></div>
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-4"><span>Ambiente padrão</span><select name="default_environment"><option value="homologation" @selected($fiscal['default_environment']==='homologation')>Homologação</option><option value="production" @selected($fiscal['default_environment']==='production')>Produção</option></select></label>
          <label class="field col-4"><span>E-mail do contador</span><input type="email" name="accountant_email" value="{{ $fiscal['accountant_email'] }}"></label>
          <label class="field col-4"><span>Perfil tributário</span><input name="tax_profile" value="{{ $fiscal['tax_profile'] }}" placeholder="Ex.: padrão Simples Nacional"></label>
        </div>
        <div class="settings-switch-grid">
          <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($fiscal['enabled'])><span><strong>Habilitar recursos fiscais</strong><small>Libera o uso das configurações fiscais pelos emissores integrados.</small></span></label>
          <label class="settings-switch"><input type="checkbox" name="send_xml_email" value="1" @checked($fiscal['send_xml_email'])><span><strong>Enviar XML por e-mail</strong><small>Preferência padrão para documentos autorizados.</small></span></label>
          <label class="settings-switch"><input type="checkbox" name="keep_xml_copy" value="1" @checked($fiscal['keep_xml_copy'])><span><strong>Guardar cópia dos XMLs</strong><small>Preserva documentos autorizados no armazenamento do sistema.</small></span></label>
        </div>
      </section>
    
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Certificado digital A1</h2><p>Arquivo privado usado pelos emissores fiscais quando necessário.</p></div></div>
      @if($company->certificate_path)
        <div class="certificate-status ok">
          @include('partials.icon',['name'=>'shield','size'=>24])
          <div>
            <strong>Certificado configurado</strong>
            <span>
              @if($company->certificate_expires_at)
                Válido até {{ $company->certificate_expires_at->format('d/m/Y') }}
              @else
                Validade não identificada
              @endif
            </span>
          </div>
          <form method="post" action="{{ route('settings.certificate.remove') }}" data-confirm-submit="Remover o certificado fiscal armazenado?">@csrf @method('DELETE')<button class="btn btn-danger-outline">Remover</button></form>
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
  </div>

@elseif($tab==='tax')
  <form method="post" action="{{ route('settings.group.update','tax') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head">
        <div><h2>Dados tributários</h2><p>Padrões de preenchimento para produtos, serviços e documentos. Estes valores não substituem a análise da operação.</p></div>
        
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-2"><span>Origem ICMS</span><input name="icms_origin_default" value="{{ $tax['icms_origin_default'] }}" maxlength="2" placeholder="0"></label>
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
      <div class="settings-warning">Os campos desta aba são apenas padrões de cadastro. CFOP, CST/CSOSN, IBS/CBS, retenções e demais tratamentos devem ser definidos conforme cada operação e legislação aplicável.</div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='nfe')
  <form method="post" action="{{ route('settings.group.update','nfe') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>NF-e — modelo 55</h2><p>Numeração e preferências do emissor de mercadorias.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Ambiente</span><select name="environment"><option value="homologation" @selected($nfe['environment']==='homologation')>Homologação</option><option value="production" @selected($nfe['environment']==='production')>Produção</option></select></label>
        <label class="field col-2"><span>Série</span><input type="number" min="0" max="999" name="series" value="{{ $nfe['series'] }}"></label>
        <label class="field col-3"><span>Próximo número</span><input type="number" min="1" name="next_number" value="{{ $nfe['next_number'] }}"></label>
        <label class="field col-4"><span>CFOP padrão</span><input name="default_cfop" value="{{ $nfe['default_cfop'] }}"></label>
        <label class="field col-12"><span>Natureza da operação padrão</span><input name="default_nature" value="{{ $nfe['default_nature'] }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($nfe['enabled'])><span><strong>NF-e habilitada</strong><small>Disponibiliza esta configuração ao emissor.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_from_sale" value="1" @checked($nfe['auto_from_sale'])><span><strong>Preparar NF-e após venda</strong><small>Preferência para integração venda → nota.</small></span></label>
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
      <div class="settings-panel-head"><div><h2>NFC-e — modelo 65</h2><p>Configuração do documento consumidor integrado ao PDV.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Ambiente</span><select name="environment"><option value="homologation" @selected($nfce['environment']==='homologation')>Homologação</option><option value="production" @selected($nfce['environment']==='production')>Produção</option></select></label>
        <label class="field col-2"><span>Série</span><input type="number" min="0" max="999" name="series" value="{{ $nfce['series'] }}"></label>
        <label class="field col-3"><span>Próximo número</span><input type="number" min="1" name="next_number" value="{{ $nfce['next_number'] }}"></label>
        <label class="field col-2"><span>ID CSC</span><input name="csc_id" value="{{ $nfce['csc_id'] }}"></label>
        <label class="field col-6"><span>CSC / Token</span><input type="password" name="csc_token" placeholder="{{ $nfce['csc_token'] ? 'CSC já configurado' : 'Informe o CSC' }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($nfce['enabled'])><span><strong>NFC-e habilitada</strong><small>Disponibiliza esta configuração ao emissor.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="auto_from_pdv" value="1" @checked($nfce['auto_from_pdv'])><span><strong>Integrar ao PDV</strong><small>Preferência para emissão a partir da venda do PDV.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="print_danfe" value="1" @checked($nfce['print_danfe'])><span><strong>Imprimir DANFE NFC-e</strong><small>Preferência de impressão do comprovante.</small></span></label>
      </div>
      <div class="settings-warning">A configuração do CSC é separada do certificado A1. O Nextor guarda o token criptografado.</div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='nfse')
  <form method="post" action="{{ route('settings.group.update','nfse') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>NFS-e</h2><p>Parâmetros para padrão nacional ou integração municipal.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Ambiente</span><select name="environment"><option value="homologation" @selected($nfse['environment']==='homologation')>Homologação</option><option value="production" @selected($nfse['environment']==='production')>Produção</option></select></label>
        <label class="field col-4"><span>Provedor / padrão</span><input name="provider" value="{{ $nfse['provider'] }}" placeholder="Ex.: Nacional / prefeitura / provedor"></label>
        <label class="field col-3"><span>Código do município</span><input name="municipality_code" value="{{ $nfse['municipality_code'] }}"></label>
        <label class="field col-2"><span>Série RPS</span><input name="series" value="{{ $nfse['series'] }}"></label>
        <label class="field col-3"><span>Próximo RPS</span><input type="number" min="1" name="next_rps" value="{{ $nfse['next_rps'] }}"></label>
        <label class="field col-5"><span>Código tributário de serviço padrão</span><input name="default_service_tax_code" value="{{ $nfse['default_service_tax_code'] }}"></label>
        <label class="field col-4"><span>Login municipal</span><input name="municipal_login" value="{{ $nfse['municipal_login'] }}"></label>
        <label class="field col-6"><span>Senha / token municipal</span><input type="password" name="municipal_password" placeholder="{{ $nfse['municipal_password'] ? 'Credencial já configurada' : 'Informe somente quando necessário' }}"></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="enabled" value="1" @checked($nfse['enabled'])><span><strong>NFS-e habilitada</strong><small>Disponibiliza os parâmetros ao emissor de serviços.</small></span></label>
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
      <div class="settings-panel-head"><div><h2>CT-e e MDF-e</h2><p>Numeração e dados-base para operações de transporte.</p></div></div>
      <div class="settings-subsection"><h3>CT-e</h3>
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-3"><span>Ambiente</span><select name="cte_environment"><option value="homologation" @selected($cte['cte_environment']==='homologation')>Homologação</option><option value="production" @selected($cte['cte_environment']==='production')>Produção</option></select></label>
          <label class="field col-2"><span>Série</span><input type="number" min="0" max="999" name="cte_series" value="{{ $cte['cte_series'] }}"></label>
          <label class="field col-3"><span>Próximo número</span><input type="number" min="1" name="cte_next_number" value="{{ $cte['cte_next_number'] }}"></label>
          <label class="field col-2"><span>RNTRC</span><input name="rntrc" value="{{ $cte['rntrc'] }}"></label>
          <label class="field col-2"><span>CFOP padrão</span><input name="default_cfop" value="{{ $cte['default_cfop'] }}"></label>
        </div>
        <label class="settings-switch settings-switch-single"><input type="checkbox" name="cte_enabled" value="1" @checked($cte['cte_enabled'])><span><strong>CT-e habilitado</strong><small>Disponibiliza estes parâmetros ao módulo de transporte.</small></span></label>
      </div>
      <div class="settings-subsection"><h3>MDF-e</h3>
        <div class="editor-grid cols-12 settings-grid">
          <label class="field col-3"><span>Ambiente</span><select name="mdfe_environment"><option value="homologation" @selected($cte['mdfe_environment']==='homologation')>Homologação</option><option value="production" @selected($cte['mdfe_environment']==='production')>Produção</option></select></label>
          <label class="field col-2"><span>Série</span><input type="number" min="0" max="999" name="mdfe_series" value="{{ $cte['mdfe_series'] }}"></label>
          <label class="field col-3"><span>Próximo número</span><input type="number" min="1" name="mdfe_next_number" value="{{ $cte['mdfe_next_number'] }}"></label>
        </div>
        <label class="settings-switch settings-switch-single"><input type="checkbox" name="mdfe_enabled" value="1" @checked($cte['mdfe_enabled'])><span><strong>MDF-e habilitado</strong><small>Disponibiliza estes parâmetros ao módulo de manifesto.</small></span></label>
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
        <div><h2>Contábil</h2><p>Dados do escritório e preferências para integração e exportação contábil.</p></div>
        
      </div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-6"><span>Escritório contábil</span><input name="office_name" value="{{ $accounting['office_name'] }}"></label>
        <label class="field col-6"><span>Contador responsável</span><input name="accountant_name" value="{{ $accounting['accountant_name'] }}"></label>
        <label class="field col-3"><span>CPF / CNPJ</span><input name="accountant_document" value="{{ $accounting['accountant_document'] }}"></label>
        <label class="field col-3"><span>CRC</span><input name="crc" value="{{ $accounting['crc'] }}"></label>
        <label class="field col-3"><span>E-mail</span><input type="email" name="email" value="{{ $accounting['email'] }}"></label>
        <label class="field col-3"><span>Telefone</span><input name="phone" value="{{ $accounting['phone'] }}"></label>
        <label class="field col-6"><span>Sistema contábil</span><input name="accounting_system" value="{{ $accounting['accounting_system'] }}" placeholder="Ex.: Domínio, Alterdata, outro"></label>
        <label class="field col-6"><span>Formato de exportação</span><input name="export_format" value="{{ $accounting['export_format'] }}" placeholder="Ex.: CSV, layout próprio"></label>
        <label class="field col-12"><span>Observações</span><textarea name="notes" rows="4">{{ $accounting['notes'] }}</textarea></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="cost_center_enabled" value="1" @checked($accounting['cost_center_enabled'])><span><strong>Usar centros de custo</strong><small>Prepara classificações financeiras para detalhamento contábil.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="automatic_monthly_export" value="1" @checked($accounting['automatic_monthly_export'])><span><strong>Exportação mensal automática</strong><small>Preferência para o fluxo quando o exportador contábil estiver conectado.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='users')
  <section class="editor-panel settings-panel">
    <div class="settings-panel-head"><div><h2>Usuários e acesso</h2><p>Cadastro, função, status e permissões por área.</p></div></div>
    <form method="post" action="{{ route('settings.users.store') }}" class="settings-user-create">
      @csrf
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Nome</span><input name="name" required></label>
        <label class="field col-3"><span>E-mail</span><input type="email" name="email" required></label>
        <label class="field col-3"><span>Senha inicial</span><input type="password" name="password" minlength="8" required></label>
        <label class="field col-3"><span>Função</span><select name="role"><option value="admin">Administrador</option><option value="manager">Gerente</option><option value="finance">Financeiro</option><option value="sales">Vendas</option><option value="operator">Operador</option></select></label>
      </div>
      <div class="permission-grid">@foreach($permissions as $key=>$label)<label><input type="checkbox" name="permissions[]" value="{{ $key }}"><span>{{ $label }}</span></label>@endforeach</div>
      <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Criar usuário</button>
    </form>

    <div class="settings-user-list">
      @foreach($users as $user)
        <details class="settings-edit-row">
          <summary><div><strong>{{ $user->name }}</strong><span>{{ $user->email }} · {{ ucfirst($user->role ?? 'admin') }}</span></div><div><span class="status {{ $user->is_active?'status-ok':'status-muted' }}">{{ $user->is_active?'Ativo':'Inativo' }}</span>@include('partials.icon',['name'=>'down','size'=>15])</div></summary>
          <form method="post" action="{{ route('settings.users.update',$user) }}">
            @csrf @method('PUT')
            <div class="editor-grid cols-12 settings-grid">
              <label class="field col-3"><span>Nome</span><input name="name" value="{{ $user->name }}" required></label>
              <label class="field col-3"><span>E-mail</span><input type="email" name="email" value="{{ $user->email }}" required></label>
              <label class="field col-3"><span>Nova senha</span><input type="password" name="password" minlength="8" placeholder="Manter atual"></label>
              <label class="field col-3"><span>Função</span><select name="role">@foreach(['admin'=>'Administrador','manager'=>'Gerente','finance'=>'Financeiro','sales'=>'Vendas','operator'=>'Operador'] as $k=>$v)<option value="{{ $k }}" @selected(($user->role ?? 'admin')===$k)>{{ $v }}</option>@endforeach</select></label>
            </div>
            <div class="permission-grid">@foreach($permissions as $key=>$label)<label><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(($user->role ?? 'admin')==='admin' || in_array($key,$user->permissions ?? [],true))><span>{{ $label }}</span></label>@endforeach</div>
            <div class="settings-row-actions"><label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($user->is_active)><span>Usuário ativo</span></label><button class="btn btn-primary">Salvar usuário</button></div>
          </form>
        </details>
      @endforeach
    </div>
  </section>

@elseif($tab==='integrations')
  <form method="post" action="{{ route('settings.group.update','integrations') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>E-mail / SMTP</h2><p>Servidor usado para mensagens enviadas pelo Nextor.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-4"><span>Host SMTP</span><input name="smtp_host" value="{{ $integrations['smtp_host'] }}"></label>
        <label class="field col-2"><span>Porta</span><input type="number" min="1" max="65535" name="smtp_port" value="{{ $integrations['smtp_port'] }}"></label>
        <label class="field col-2"><span>Criptografia</span><select name="smtp_encryption"><option value="">Nenhuma</option><option value="tls" @selected($integrations['smtp_encryption']==='tls')>TLS</option><option value="ssl" @selected($integrations['smtp_encryption']==='ssl')>SSL</option></select></label>
        <label class="field col-4"><span>Usuário</span><input name="smtp_username" value="{{ $integrations['smtp_username'] }}"></label>
        <label class="field col-4"><span>Senha</span><input type="password" name="smtp_password" placeholder="{{ $integrations['smtp_password'] ? 'Senha já configurada' : '' }}"></label>
        <label class="field col-4"><span>E-mail remetente</span><input type="email" name="smtp_from_address" value="{{ $integrations['smtp_from_address'] }}"></label>
        <label class="field col-4"><span>Nome remetente</span><input name="smtp_from_name" value="{{ $integrations['smtp_from_name'] }}"></label>
      </div>
      <label class="settings-switch settings-switch-single"><input type="checkbox" name="smtp_enabled" value="1" @checked($integrations['smtp_enabled'])><span><strong>Usar SMTP configurado</strong><small>Credenciais ficam armazenadas de forma criptografada.</small></span></label>
    </section>

    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>API, webhook e contabilidade</h2><p>Pontos de integração externa do Nextor.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-6"><span>Webhook</span><input type="url" name="webhook_url" value="{{ $integrations['webhook_url'] }}" placeholder="https://..."></label>
        <label class="field col-6"><span>Segredo do webhook</span><input type="password" name="webhook_secret" placeholder="{{ $integrations['webhook_secret'] ? 'Segredo já configurado' : '' }}"></label>
        <label class="field col-6"><span>Integração contábil</span><input name="accounting_integration" value="{{ $integrations['accounting_integration'] }}" placeholder="Ex.: Domínio, Alterdata, arquivo..."></label>
      </div>
      <label class="settings-switch settings-switch-single"><input type="checkbox" name="api_enabled" value="1" @checked($integrations['api_enabled'])><span><strong>API habilitada</strong><small>Preferência de acesso; os endpoints e permissões continuam controlados pela aplicação.</small></span></label>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>

@elseif($tab==='system')
  <form method="post" action="{{ route('settings.group.update','system') }}" class="settings-editor">
    @csrf
    <section class="editor-panel settings-panel">
      <div class="settings-panel-head"><div><h2>Sistema</h2><p>Preferências de uso e comportamento da interface.</p></div></div>
      <div class="editor-grid cols-12 settings-grid">
        <label class="field col-3"><span>Linhas por página</span><select name="rows_per_page">@foreach(['10','25','50','100'] as $n)<option value="{{ $n }}" @selected((string)$system['rows_per_page']===$n)>{{ $n }}</option>@endforeach</select></label>
        <label class="field col-3"><span>Busca dinâmica</span><input type="number" min="120" max="1000" name="search_delay" value="{{ $system['search_delay'] }}"><small>Espera em milissegundos</small></label>
        <label class="field col-3"><span>Formato de data</span><select name="date_format"><option value="d/m/Y" @selected($system['date_format']==='d/m/Y')>DD/MM/AAAA</option><option value="Y-m-d" @selected($system['date_format']==='Y-m-d')>AAAA-MM-DD</option></select></label>
      </div>
      <div class="settings-switch-grid">
        <label class="settings-switch"><input type="checkbox" name="compact_mode" value="1" @checked($system['compact_mode'])><span><strong>Interface compacta</strong><small>Mantém tabelas e controles com densidade de ERP desktop.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="show_tutorials" value="1" @checked($system['show_tutorials'])><span><strong>Exibir tutoriais</strong><small>Mostra atalhos e ajuda contextual.</small></span></label>
        <label class="settings-switch"><input type="checkbox" name="confirm_destructive_actions" value="1" @checked($system['confirm_destructive_actions'])><span><strong>Confirmar ações destrutivas</strong><small>Exige confirmação antes de cancelar ou excluir.</small></span></label>
      </div>
    </section>
  
  <div class="editor-savebar settings-savebar">
    <button class="btn btn-success" type="submit">Salvar</button>
  </div>
</form>
@endif
</main>
@endsection
