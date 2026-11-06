# Auditoria visual do Lumeron ERP — 26/10/2026

**Resultado: correções implementadas e verificadas nas telas e estados descritos abaixo.** Não foram identificadas divergências pendentes nos componentes comparados. As limitações de ambiente e de impressão estão explicitadas ao final.

Repositório: `robsonmktsouza-stack/nextor`. Branch: `feature/nfe55-motor-fiscal-completo`. Base comparada: `5e81321` (`5e81321bc7f2b2e1f4fc5771ca7bde3ae5080ac6`).

## Objetivo e método

Uniformizar componentes equivalentes entre os módulos, preservando a identidade do ERP, sua densidade de trabalho e os fluxos existentes. O trabalho combina inventário de Blade e CSS, revisão da cascata, remoção de estilos repetidos, testes dirigidos, verificações de sintaxe e uma matriz de navegação e comparação de estilos computados.

Foram varridos **108 arquivos de interface: 85 Blade e 23 CSS**. A revisão contextual dos relatórios de módulo e do núcleo enumera 101 arquivos distintos e **46 grupos de problemas**, identificados abaixo. Os efeitos das correções compartilhadas e as declarações individuais de CSS não são somados como novos problemas. Separadamente, o inventário automatizado abrange os **85 Blade** existentes e, na fotografia atual, **23 folhas de estilo**; suas listas de caminhos estão em `css-inventory.json`.

Os módulos abrangidos pelos inventários incluem Dashboard, Produtos, Clientes, Serviços, Estoque, Vendas, Orçamentos, Devoluções, Compras, Financeiro, PDV e caixas, Fiscal/NF-e/NFC-e, configurações fiscais, autenticação, licenciamento e administração disponíveis nas telas existentes. Não foram criadas telas para módulos inexistentes. A comprovação de cada tela efetivamente renderizada pertence à matriz final de navegador.

## Padrão visual compartilhado

`public/css/ui-tokens.css` concentra dimensões, fontes, cores e superfícies. Os layouts carregam os tokens antes dos componentes e `ui-refinement.css` depois das folhas dos módulos e da pilha de estilos. Assim, os componentes comuns recebem o mesmo acabamento; as folhas de módulo preservam grids, disposição dos editores e variantes funcionais.

| Componente | Padrão |
| --- | --- |
| Fonte da interface | Roboto, com alternativas Arial/Helvetica |
| Texto / rótulos / tabela / cabeçalho de tabela | 14px / 13px / 13px / 12px |
| Campos comuns | 38px; fonte 13,5px; raio 4px |
| Ações comuns / barra compacta | 38px / 34px, conforme a função |
| Botão de ícone / ícone comum | 32px / 16px |
| Cartões e diálogos | Raio 4px; bordas e superfícies dos tokens |
| Borda / foco | `#cbd6df` / `#2f83bf` |
| Perfil confortável | Campos 41px; linha de listagem 50px e cabeçalho 40px |
| Checkout do PDV | Identificação 42px/fonte 18px; entrada monetária destacada 56px/fonte 24px |

Foram mantidas as cores de ação do Lumeron, o destaque de resultados de busca e os estados de foco, desabilitado e somente leitura. Círculos de switches, indicadores, conteúdo térmico e a geometria específica de formulários densos não são tratados como novos estilos de campo ou botão.

## Problemas identificados e correções

Os 46 itens abaixo agrupam as correções; não representam uma contagem de telas ou de declarações CSS.

| ID | Área | Problema e correção |
| --- | --- | --- |
| VA-01 | Compras | Consolidação de grids e seletores repetidos; aparência comum delegada ao CSS central. |
| VA-02 | Compras | Campos de 32/33/35px passam ao padrão compartilhado de 38px. |
| VA-03 | Detalhes de compra | Ações usam variantes comuns; tabela usa os componentes de listagem do ERP. |
| VA-04 | Compras responsivas | Nomes longos podem quebrar linha; ações e rolagem ficam contidas na tabela. |
| VA-05 | Acesso e recuperação de senha | Campos e botões consomem os tokens; marca e estrutura do acesso preservadas. |
| VA-06 | Licenciamento e erros | As duas telas reutilizam a mesma folha e os mesmos tokens de interface. |
| VA-07 | Vendas e configurações | Estilos de disposição saem do Blade para uma folha estrutural. |
| VA-08 | Dashboard | Ação de nova venda adota a variante comum de criação. |
| VF-01 | Editor NF-e | Removido o acabamento verde independente de cabeçalho, abas, campos, rodapé e ações. |
| VF-02 | Campos NF-e | Retiradas dimensões e fontes locais que substituíam os componentes compartilhados. |
| VF-03 | CSS NF-e | Removidas declarações repetidas ou substituídas na mesma condição de mídia. |
| VF-04 | Cadastro rápido | Removidas duas implementações concorrentes de janela; permanece uma estrutura com rolagem interna. |
| VF-05 | Cadastro rápido | A ação de novo cadastro usa o botão secundário comum, preservando ID e eventos. |
| VF-06 | Busca assistida | Resultados usam altura mínima e tipografia compartilhadas; posição e navegação permanecem. |
| VF-07 | Compras fiscais | Seletor de manifestação passa de 30px/raio3/fonte11 ao padrão de campo comum. |
| VF-08 | Ações fiscais | Ícones usam 32px e foram retiradas larguras mínimas antigas que limitavam a tabela. |
| VF-09 | Configurações fiscais | Extraídos 27 atributos de estilo e o bloco de catálogo para uma folha estrutural. |
| VF-10 | Filtros fiscais | Botão de filtro usa a mesma altura dos campos vizinhos. |
| VF-11 | Cabeçalho do item NF-e | Totais herdam a cor do cabeçalho, preservando contraste. |
| VP-01 | Cadastro de caixas | Campos, rótulos e ações usam os componentes comuns. |
| VP-02 | Caixas responsivos | Grid de quatro colunas reduz para duas e uma coluna conforme a largura. |
| VP-03 | Histórico de caixas | Histórico utiliza layout, tabela e paginação existentes do ERP. |
| VP-04 | Relatório de caixa | Extraídos estilos; controles de tela padronizados e impressão A4 preservada. |
| VP-05 | Modal de relatório | Ações, fechamento e raio usam os tamanhos comuns, mantendo quebra responsiva. |
| VP-06 | CSS PDV | Removidas 72 declarações substituídas por regras posteriores equivalentes no mesmo contexto. |
| VP-07 | Acabamento PDV | Removidas 40 declarações substituídas; dimensões e cores consomem tokens. |
| VP-08 | Abertura/cancelamento de caixa | Campos de operação usam os tokens e o seletor não recebe borda duplicada. |
| VP-09 | Modais PDV | Fechamento e ações comuns usam as dimensões compartilhadas; teclado e checkout mantêm sua função. |
| VP-10 | Configurações PDV | Cartões, rótulos e textos auxiliares usam raio, borda e fontes compartilhados. |
| VP-11 | Disposição PDV | Estilos inline de ajuda e menu passam a classes nomeadas. |
| VP-12 | Recibo na tela | Barra de ações usa componentes comuns e quebra linha sem alterar a largura térmica impressa. |
| VP-13 | Ações do recibo | Voltar, imprimir e ações fiscais usam as variantes comuns. |
| VP-14 | Modal de recibo | Removido caractere maior-que solto no conteúdo. |
| VP-15 | Seletores e janelas PDV | Ajustados fonte/borda de seletores e borda de janelas após medição do navegador; reconferidos na matriz final. |
| VP-16 | Ações e filtros do PDV | Ações comuns usam fonte 13,5px; filtros/paginação 34px e cores compartilhadas. |
| VP-17 | Entradas do checkout | Busca de cliente 38px; identificação 42px; entradas monetárias destacadas 56px e fonte 24px, por função. |
| VP-18 | Cabeçalhos e operações PDV | Cabeçalhos comuns seguem a mesma paleta/título de 15px; ações e auxiliares legíveis em 13,5px/12px. |
| VC-01 | Tokens compartilhados | Centralizadas dimensões, tipografia e paleta; módulos consomem as variáveis. |
| VC-02 | Densidade confortável | Removido contrato duplicado que anulava a preferência de campos com 41px. |
| VC-03 | Switches nativos | Checkbox invisível limitado ao próprio rótulo, removendo overflow; clique e foco preservados. |
| VC-04 | Campos compostos | Prefixos e ações compartilham altura, borda e raio do campo sem bordas internas duplicadas. |
| VC-05 | Barras de ações | Grupos quebram linha na largura disponível, mantendo ações fiscais acessíveis. |
| VC-06 | Confirmações | Diálogo de confirmação utiliza a mesma janela, cabeçalho, rodapé e paleta das ações comuns. |
| VC-07 | Portais e ordem de estilos | Estilo compartilhado alcança conteúdo fora de app-root e é carregado após os estilos locais. |
| VC-08 | Cascata tipográfica | Separada hierarquia semântica das métricas dos componentes, retirando substituições locais. |
| VC-09 | Interruptores booleanos | Produtos, recebidas e permissões usam track34×18, thumb14, cores e sombra; clique/teclado preservados. |

Os itens VC registram problemas do núcleo compartilhado; seus efeitos nos módulos não foram somados novamente. Em particular, VC-07 trata do alcance dos estilos fora de app-root e da ordem de carregamento; VF-04 trata das duas implementações concorrentes da janela de cadastro rápido. São causas distintas.

## Inventário de CSS

Fotografia gerada em `css-inventory.json`; deve ser regenerada depois das últimas correções de navegador:

| Métrica | Base | Final |
| --- | ---: | ---: |
| Folhas de estilo | 17 | 23 |
| Regras CSS | 4009 | 3637 |
| Declarações CSS | 12615 | 11474 |
| Declarações !important | 495 | 429 |
| Grupos de regras exatamente duplicadas | 12 | 0 |
| Conflitos no mesmo seletor/contexto | 368 | 0 |
| Blocos CSS locais nas telas | 5 | 0 |
| Atributos de estilo nas telas | 45 | 0 |
| Avisos de leitura | 0 | 0 |

A revisão identificou camadas históricas sobrepostas no mesmo seletor e contexto; não eram variantes responsivas. Foram removidas 458 declarações dominadas e 111 regras vazias, preservando a declaração vencedora e sua prioridade. Os 293 grupos existentes no momento dessa limpeza foram individualmente classificados e reduzidos a zero; o comparativo inicial tinha 368. As 116 verificações de sintaxe dos valores vencedores via `CSS.supports` passaram. As provas por seletor/propriedade estão em `cascade-review.json` e `css-syntax-support.json`. Tokens globais possuem um único proprietário, sem redefinições duplicadas.

## Arquivos e preservação de funcionalidades

Na fotografia da comparação com a base usada para este relatório há **53 arquivos de aplicação/teste alterados ou criados**: 20 CSS, 30 Blade e 3 testes. Documentação e ferramentas de auditoria são listadas à parte. As listas completas dos arquivos examinados constam nos três relatórios de módulo; `core.json` documenta os nove achados do núcleo compartilhado.

Folhas de estilo alteradas/criadas:

- `public/css/commercial-workspace.css`
- `public/css/desktop-polish.css`
- `public/css/erp-typography.css`
- `public/css/erp.css`
- `public/css/fiscal-purchases.css`
- `public/css/fiscal-workspace.css`
- `public/css/login.css`
- `public/css/nfe-form-workflow.css`
- `public/css/pdv-cash-admin.css`
- `public/css/pdv-cash-document.css`
- `public/css/pdv-cash-report-modal.css`
- `public/css/pdv-lumeron.css`
- `public/css/pdv-receipt-screen.css`
- `public/css/pdv-settings.css`
- `public/css/pdv.css`
- `public/css/purchases.css`
- `public/css/quick-catalog.css`
- `public/css/ui-refinement.css`
- `public/css/ui-tokens.css`
- `public/css/user-message-page.css`

Views e layouts alterados:

- `resources/views/auth/login.blade.php`
- `resources/views/auth/password-request.blade.php`
- `resources/views/auth/password-reset.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/errors/license-blocked.blade.php`
- `resources/views/errors/message.blade.php`
- `resources/views/fiscal/catalog/index.blade.php`
- `resources/views/fiscal/fcp/index.blade.php`
- `resources/views/fiscal/index.blade.php`
- `resources/views/fiscal/nfce/inutilizations.blade.php`
- `resources/views/fiscal/nfe/inutilizations.blade.php`
- `resources/views/fiscal/rules/_fields.blade.php`
- `resources/views/fiscal/rules/index.blade.php`
- `resources/views/fiscal/show.blade.php`
- `resources/views/fiscal/tax-groups/_advanced.blade.php`
- `resources/views/fiscal/tax-groups/form.blade.php`
- `resources/views/fiscal/tax-groups/index.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/pdv.blade.php`
- `resources/views/partials/quick-catalog.blade.php`
- `resources/views/pdv/cash-history.blade.php`
- `resources/views/pdv/cash-registers.blade.php`
- `resources/views/pdv/cash-report.blade.php`
- `resources/views/pdv/index.blade.php`
- `resources/views/pdv/partials/cash-report-modal.blade.php`
- `resources/views/pdv/partials/receipt-modal.blade.php`
- `resources/views/pdv/receipt.blade.php`
- `resources/views/purchases/show.blade.php`
- `resources/views/sales/create.blade.php`
- `resources/views/settings/index.blade.php`

Teste ajustado:

- `tests/Feature/ErpTypographyTest.php`
- `tests/Feature/NfeEditorTest.php`
- `tests/Feature/UiRefinementTest.php`

`NfeEditorTest` deixa de exigir a antiga aparência verde e verifica os tokens comuns, preservando as verificações dos campos e do comportamento existente. `ErpTypographyTest` e `UiRefinementTest` acompanham a divisão centralizada entre tipografia, tokens e componentes. As ferramentas em `tools/visual-audit/` registram inventário e navegação; fixtures e roteador servem exclusivamente ao ambiente isolado da auditoria.

A verificação `syntax.json` não identificou alterações em `app`, `config`, `database`, `routes`, `bootstrap` ou `public/js`. Não foram modificados cálculos, regras tributárias/financeiras, assinatura, filas, ACBr, numeração ou documentos autorizados.

Os templates dedicados de DANFE NFC-e, espera de impressão, orçamento PDF e recibo financeiro permanecem sem alterações. No PDV, os wrappers de tela de recibo e relatório foram modificados para organizar ações; as regras específicas de impressão térmica/A4 foram preservadas. Isso não substitui a verificação do PDF real indicado nas pendências.

## Verificações já executadas

| Verificação | Resultado real | Evidência |
| --- | --- | --- |
| Suíte completa integrada em `7a6e5eb` | 1.249 testes: 1.247 passaram, 2 ignorados; 8.616 asserções; zero falhas/erros | `full-suite.json`, `full-suite.txt` |
| Regressão dirigida após os primeiros ajustes dos switches | 13 testes, 383 asserções; zero falhas/erros | `post-switch-tests.txt` |
| Comercial, acesso, configurações e apresentação | 27 testes, 250 asserções; zero falhas/erros | `admin-commercial.json`, `admin-commercial-tests.txt` |
| Fiscal, editor NF-e, cadastro rápido e compras fiscais | 63 testes, 588 asserções; zero falhas/erros | `fiscal.json`; log e JUnit em `/workspace/scratch/visual-fiscal-tests.*` |
| PDV, caixa, segurança de turno e checkout fiscal | 61 testes, 497 asserções; passaram | `pdv.json`, `pdv-tests.txt` |
| Reutilização de recibo e configurações NFC-e | 18 testes: 17 passaram, 1 ignorado; 222 asserções | `pdv.json`, `pdv-tests.txt` |
| Sintaxe PHP | 450 arquivos; sem falhas | `syntax.json`, `syntax-evidence.txt` |
| Compilação Blade e sintaxe PHP gerada | 85 views; sem falhas | `syntax.json`, `syntax-evidence.txt` |
| Sintaxe JavaScript | 17 arquivos com `node --check`; sem falhas | `syntax.json`, `syntax-evidence.txt` |
| Parser de CSS e diff | Sem blocos abertos; `git diff --check` passou | `css-inventory.json`, relatórios por módulo |

Os grupos de testes acima são evidências individuais; não foram somados como uma suíte única para evitar duplicar execuções. O runtime PHP dessas verificações foi **8.5.12**, em `composer:2`; não foi executada uma suíte separada no PHP 8.3 do Laragon. JavaScript foi verificado com Node.js 24.19.0.

Os dois testes ignorados foram `NFCePrintSettingsTest::test_original_danfe_can_render_real_pdf_with_local_chrome_when_installed` e `QuoteCommercialDocumentTest::test_real_chromium_generates_valid_pdf_when_installed`: o container do PHPUnit não possui Chromium. O navegador desta auditoria roda separadamente; sua existência não torna esses testes de geração de PDF aprovados.

## Atualização da instalação

Esta alteração de interface não exige migrations, seeders, novas dependências ou mudança na configuração fiscal. Na instalação já configurada, depois da revisão da branch:

```bat
cd C:\laragon\www\nextor
git switch feature/nfe55-motor-fiscal-completo
git pull --ff-only origin feature/nfe55-motor-fiscal-completo
php artisan optimize:clear
```

Recarregue o navegador para buscar os ativos atualizados. Os layouts utilizam a versão dos arquivos para os estilos locais. Não houve merge em `main` nem mudança de configuração de produção neste trabalho. A DLL, o certificado A1 e o INI do ACBr existentes no Windows não foram substituídos.

## Validação visual no navegador

Chromium 154 real, em ambiente Laravel isolado com SQLite e dados sintéticos. As quatro resoluções foram **1920×1080, 1366×768, 1024×768 e 390×844**.

| Rodada | Cobertura | Resultado |
| --- | --- | --- |
| Matriz principal em `7a6e5eb` | 96 variantes de rota, 514 estados e 10.322 componentes | Sem erro HTTP/JavaScript, overflow documental, controles cortados, entradas fora de seus grupos, papéis desconhecidos ou divergência nas medidas comparadas |
| Complemento de duas telas | 54 estados; NFC-e autorizada somente leitura e comprovante no histórico do PDV | Mesmas verificações aprovadas; documentos exclusivamente sintéticos |
| Regressão das alterações finais dos switches | 9 variantes de rota, 116 estados, incluindo Produtos, recebidas e permissões | Sem desvios nos componentes comparados; três implementações de switch usam os mesmos tokens |
| Estados dos campos | 11 casos entre campos simples, prefixados, numéricos, com ação e autenticação | Foco, borda, sombra, fonte, padding, somente leitura e desabilitado aprovados |
| Densidade confortável | 5 estados | Campos 41px, linha 50px e cabeçalho 40px aprovados |
| Teclado | Clique no rótulo, Space e foco | Alternância e foco visível preservados; sem submissão |

Consolidado das rodadas: **684 observações, 550 estados únicos, 97 variantes de rota e 13.939 observações de componentes**. A inspeção manual foi feita por amostragem de capturas; a navegação e a comparação de estilos cobriram a matriz registrada. [browser.json](browser.json) contém métricas, hashes e limites; [browser-matrix.csv](browser-matrix.csv) enumera as telas e os estados. As [12 capturas](screenshots/) incluem antes/depois, formulários, modais, menu fiscal e comprovante.

A matriz principal registrou o mesmo SHA-256 de CSS no início e no fim. Depois dela, apenas os estilos dos interruptores foram ajustados e reconferidos nas rotas afetadas, evitando repetir toda a matriz. Os hashes de todas as tabelas foram idênticos antes/depois da navegação principal: nenhuma escrita no banco durante as consultas.

As asserções exigem a rota, o formulário, a aba e o modal esperados realmente visíveis; capturas de uma listagem não contam como validação de seu editor. Menus abertos, select customizado, barras de ação e modais foram medidos. Campos compostos são comparados pelo contorno externo, sem exigir borda no input interno. Tabelas largas mantêm rolagem local; a página não cresce horizontalmente.

A inspeção com `CSS.getPlatformFontsForNode` comprovou **Roboto renderizada** no corpo, no campo de produto e no título NF-e. Atalhos usam a fonte monoespaçada própria. Os componentes equivalentes usam a mesma família e as mesmas medidas.

Foram renderizados **81 dos 85 Blade próprios** e uma view de paginação do fornecedor, contada separadamente. Os quatro arquivos restantes estão identificados nas limitações abaixo. `files.json` lista todos os 108 arquivos de interface examinados e os 53 arquivos de aplicação/teste modificados. Ferramentas e instruções para repetir a validação estão em [tools/visual-audit/README.md](../../../tools/visual-audit/README.md).

As variantes preservadas têm função distinta: botões compactos de barra34px, ações regulares38px, entradas destacadas do caixa 42/56px, campos multilinha e ações que crescem quando o texto quebra no celular. O editor principal NF-e ocupa a tela inteira no celular e pode ter raio 0; seus submodais conservam raio 4. Segmentos internos de campos/botões agrupados não recebem uma segunda borda ou um segundo arredondamento.

## Limitações e verificações externas

- `resources/views/finance/settings.blade.php` não possui tela ativa; a rota redireciona para o plano de contas. Foi examinada estaticamente.
- `resources/views/fiscal/nfce-danfe.blade.php`, `nfce-danfe-wait.blade.php` e `resources/views/sales/quote-pdf.blade.php` foram preservados e examinados estaticamente, sem nova validação visual do PDF final dependente dos arquivos/retornos fiscais ou do engine de impressão. Dois testes opcionais de PDF foram ignorados conforme registrado.
- Não houve execução no Windows/PHP8.3, acesso ao Laragon, certificado, ACBr ou nova transmissão à SEFAZ. Esta auditoria não representa nova homologação fiscal nem prova de impressão física.
- As comparações visuais se referem aos dados sintéticos, estados, navegador e resoluções registrados; combinações futuras de conteúdo e outros navegadores não foram exercitados. Nenhuma divergência conhecida permanece nos casos medidos.

## Publicação e revisão

Commits de implementação, todos na branch solicitada:

- `ef6a5e9`: componentes compartilhados e remoção dos acabamentos concorrentes.
- `7a6e5eb`: estados, tipografia, menus responsivos e limpeza da cascata histórica.
- `d2eb35e`: aparência comum dos switches em cadastros e recebidas.
- `8474fda`: permissões integradas aos mesmos tokens dos switches.

A documentação, o inventário e as ferramentas desta pasta acompanham a entrega. [PR22 para revisão](https://github.com/robsonmktsouza-stack/nextor/pull/22), sem merge automático. Nenhuma alteração em `main`/`develop` nem implantação de produção foi realizada.
