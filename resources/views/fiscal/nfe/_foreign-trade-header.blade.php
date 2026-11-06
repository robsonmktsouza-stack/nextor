<details class="editor-panel" id="nfeForeignTradeHeader" @if($nfeOld('destination',$draft->destination)!=='foreign') hidden @endif>
  <summary>Saída do país na exportação direta</summary>
  <div class="editor-grid cols-12">
    <label class="field col-2"><span>UF da saída</span><input name="export_exit_uf" id="nfeExportExitUf" maxlength="2" value="{{ $nfeOld('export_exit_uf',data_get($draft->custom_fields,'foreign_trade.export_exit_uf')) }}"></label>
    <label class="field col-5"><span>Local de exportação</span><input name="export_exit_location" id="nfeExportExitLocation" maxlength="60" value="{{ $nfeOld('export_exit_location',data_get($draft->custom_fields,'foreign_trade.export_exit_location')) }}"></label>
    <label class="field col-5"><span>Local de despacho</span><input name="export_dispatch_location" id="nfeExportDispatchLocation" maxlength="60" value="{{ $nfeOld('export_dispatch_location',data_get($draft->custom_fields,'foreign_trade.export_dispatch_location')) }}"></label>
  </div>
</details>
