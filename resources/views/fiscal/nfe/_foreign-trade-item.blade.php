<details class="editor-panel nfe-item-section" data-nfe-item-panel="item-foreign" id="nfeItemForeignTrade" hidden>
  <summary class="nfe-item-section-heading"><span class="nfe-section-label"><strong>Importação direta</strong><small>Declarações e tributos documentados</small></span><span class="nfe-section-caret" aria-hidden="true"></span></summary>
  <div class="nfe-item-section-content">
    <div class="editor-grid cols-12">
      <label class="field col-3"><span>Base do II</span><input id="nfeImportTaxBase" type="number" min="0" step="0.01"></label>
      <label class="field col-3"><span>Alíquota do II %</span><input id="nfeImportTaxRate" type="number" min="0" step="0.0001"></label>
      <label class="field col-3"><span>II documentado para conferência</span><input id="nfeImportTaxAmount" type="number" min="0" step="0.01"></label>
      <label class="field col-3"><span>Despesas aduaneiras</span><input id="nfeImportCustomsExpenses" type="number" min="0" step="0.01"></label>
      <label class="field col-3"><span>IOF documentado</span><input id="nfeImportIof" type="number" min="0" step="0.01"></label>
    </div>
    <details><summary>Compor despesas aduaneiras</summary><p>O II calculado integra o total da NF-e. Inclua despesas aduaneiras e IOF em “Outras despesas” do item quando compuserem o valor da nota, conforme a documentação fiscal aprovada. Os campos acima discriminam esses valores no grupo de importação.</p></details>
    <div id="nfeImportDeclarations"></div>
    <button type="button" class="btn btn-secondary" id="nfeAddImportDeclaration">Adicionar declaração de importação</button>
  </div>
</details>
