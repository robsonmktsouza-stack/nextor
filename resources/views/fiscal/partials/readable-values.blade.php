@php($fiscalRows=\App\Support\FiscalPresentation::rows((array)$values))
@if(count($fiscalRows))
<dl class="fiscal-data-list">
  @foreach($fiscalRows as $fiscalRow)
    <div><dt>{{ $fiscalRow['label'] }}</dt><dd>{{ $fiscalRow['value'] }}</dd></div>
  @endforeach
</dl>
@else
<p>Consulte os dados cadastrados e o fundamento fiscal desta configuração.</p>
@endif
