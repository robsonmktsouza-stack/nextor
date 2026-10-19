# Handoff entre chats e agentes — Lumeron ERP

Este documento registra o último trabalho concluído. Verifique sempre o código da branch ativa e `git status -sb` antes de continuar.

## Entrega mais recente — 19/10/2026 — PDV, retorno da venda e NFC-e SEFAZ-BA

**Pedido:** impedir que o fechamento de venda no PDV abra o módulo Fiscal/NFC-e; revisar os campos e controles da frente de caixa frente às exigências da SEFAZ-BA e modelo 65.

**Implementado na branch `feature/acbr-nfe-bootstrap`:**
- `resources/views/pdv/receipt.blade.php`: mantém o comprovante **interno e não fiscal**, mas volta SEMPRE ao `pdv.index` depois de imprimir ou cancelar a impressão. Não navega automaticamente para `fiscal.show`. Oferece DANFE autorizado somente com XML correspondente e DANFE de contingência assinada em duas vias (sem declarar autorização).
- `resources/views/pdv/index.blade.php`: ao voltar da venda, mostra notificação com situação fiscal real (preparada, processando, pendente, rejeitada, contingência, autorizada apenas com protocolo/caminho de XML válido). O painel Operações / Última venda e o histórico permitem imprimir DANFE autorizado ou contingência assinada quando existe o XML correto. Formulário de pagamento eletrônico explica campos de cartão/PIX e depende de ação explícita.
- `app/Http/Controllers/PdvController.php`: estados offline diferenciados no caixa e URLs de DANFE no histórico somente quando o XML compatível foi arquivado.
- `app/Services/SalesService.php`: a NFC-e automática, quando habilitada, é enfileirada em **database** após a venda, mesmo que a conexão Laravel padrão seja sync. ACBr nunca é chamado dentro do HTTP; se não houver worker a nota permanecerá pendente.
- `app/Services/Fiscal/NFCePreflightService.php`: pagamento com cartão exige integração `1`/ `2`; cartão integrado exige CNPJ da adquirente e autorização. Confere CNPJ transacional/UF, CNPJ de adquirente/beneficiário, UF válida e bandeira. PIX com integração informada deve ter código válido.
- `app/Services/Fiscal/NFCeIniBuilder.php`: corrigido erro de semântica fiscal: CNPJ da adquirente vai em `CNPJ`, CNPJ/UF onde ocorreu a transação em `CNPJPag/UFPag`, beneficiário em `CNPJReceb` e terminal em `idTermPag`. PIX aceita metadados informados. Nunca gera `tpIntegra=2` inventado. `verProc` atualizado para Lumeron.
- `public/js/pdv.js`: cartão/PIX usam janela de metadados eletrônicos, com bloqueio de dados incompletos, tipo de integração explícito, CNPJ+UF em conjunto, UF válida e adquirente/autorização para cartão integrado.
- `tests/Feature/PdvFiscalCheckoutFlowTest.php`: criado e expandido para cobrir retorno ao PDV, separação comprovante vs DANFE, contingência de duas vias, enfileiramento, campos INI e validações.
- `docs/fiscal/pdv-nfce-bahia.md` e `docs/ai/ARCHITECTURE_AND_DECISIONS.md`: fluxo, requisitos, fontes oficiais e pendências registrados.

**Fontes consultadas:** MOC NF-e/NFC-e v7.0 e NT 2025.001 no Portal Nacional; requisitos NFC-e e RICMS/BA (art. 107-G); modelo oficial de INI `[pagNNN]` do ACBrLib. Detalhes/URLs no checklist fiscal.

**Validações realizadas:** consulta de código por GitHub, 14 verificações estáticas sem falha (destino do recibo, status fiscal, campos XML, contingência, fila, pagamentos e formulários) e **compilação sintática JavaScript via V8 = PASS**. Duas seções `@php`/`@endphp` no PDV corretamente pareadas. **Não foram executados PHP/Laravel/PHPUnit, transação ACBr real, servidor Laragon ou homologação SEFAZ-BA**; portanto, a emissão não foi certificada ou liberada para produção.

**Rodar localmente no Laragon:**
```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
php artisan test --filter=PdvFiscalCheckoutFlowTest
php artisan test --filter=NFCeFiscalLifecycleTest
```
Conferir worker da fila `fiscal` (conexão `database`) e testar em **homologação**, nunca produção sem autorização. Cenários: venda dinheiro/PIX/cartão, identificação opcional, pagamento dividido/troco, impressão/cancelamento de impressão com retorno ao caixa, autorização/rejeição/consulta, cartão TEF/POS, correção sem duplicar estoque/financeiro, contingência assinada (2 vias). **Pendência importante:** confirmar regras vigentes de PIX e presença obrigatória de grupo de cartão na BA em homologação, assim como os ajustes de IBS/CBS do perfil tributário/NT vigentes.

## Entrega anterior — 19/10/2026 — configurações simples e acessíveis

**Pedido:** estudar eGestor e outros ERPs brasileiros e simplificar a nomenclatura/organização de Configurações e botões do Lumeron, sem aparência excessivamente técnica.

**Pesquisa:** documentação pública oficial de eGestor, Omie, Bling e Conta Azul. **Não houve acesso ao painel privado/autenticado dos concorrentes**, cópia de telas, nem reprodução de código. Referências e convenções: `docs/ux/erp-settings-language.md`.

**Implementado em `feature/acbr-nfe-bootstrap`:**
- `resources/views/settings/index.blade.php`: 12 entradas técnicas reorganizadas em **6 grupos** — Minha empresa, Vendas e estoque, Financeiro, Notas fiscais, Equipe e acessos, Outras opções. Subabas reúnem os **20 `tab` originais**. Os links de entrada apontam ao primeiro tab existente de cada grupo; os aliases de rota, formulários POST, `name`, `id` e `data-*` foram preservados. Pequena descrição por grupo, marcador de página ativa para acessibilidade e dezenas de rótulos mais claros.
- `public/css/desktop-polish.css`: descrição da seção com espaçamento de sistema desktop; subabas roláveis horizontalmente em resolução estreita, com foco visível e indicador ativo.
- `tests/Feature/SettingsClarityTest.php` (novo): assegura agrupamento, acessibilidade, cobertura das 20 abas, continuidade dos campos/rotas e terminologia mais compreensível.
- `docs/ai/ARCHITECTURE_AND_DECISIONS.md`: decisão D02-B. `docs/ux/erp-settings-language.md`: glossário/guia de nomenclatura e referências.

**Verificação estática realizada via leitura do GitHub:** 6 grupos, 20 abas sem repetição, **todas as abas de `SettingsController::TABS` presentes**; os grupos usam abas existentes como URL; arquivo CSS com 111 pares de chaves equilibradas; campos importantes (CSC/CSOSN) ainda presentes. **Testes PHPUnit e navegador não foram executados neste ambiente**, pois o Laragon do usuário não é acessível daqui. Não há evidência de implantação em produção; apenas commits no GitHub.

**Validar no Laragon:**
```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
php artisan test --filter=SettingsClarityTest
php artisan test --filter=UiRefinementTest
```

Abrir Configurações e navegar pelos seis grupos, especialmente **Financeiro → Formas de pagamento**, **Notas fiscais → Nota do consumidor** e **Outras opções → Integrações avançadas**. Verificar redimensionamento e salvamento com dados descartáveis antes de usar dados reais.

## Entrega anterior — 19/10/2026 — fonte e legibilidade do ERP

**Pedido:** retirar Segoe UI de toda interface do Lumeron e aumentar a fonte pequena (print do painel Financeiro em 1440×900).

**Implementado na branch `feature/acbr-nfe-bootstrap`:**

- `public/css/erp-typography.css` (novo) cria tipografia de trabalho compartilhada com Roboto: 14px geral, 13px dados, 12px cabeçalhos, 13px rótulos e 12px descrições. Atenção específica a Financeiro (cartões/valores/grades), sidebar, barras de ação, formulários e PDV. Regras de maior densidade ficam responsivas em telas pequenas.
- `public/css/erp.css`, `public/css/pdv.css`, `public/css/pdv-lumeron.css` e `public/css/login.css`: famílias antigas removidas, alinhadas a Roboto/Arial.
- `resources/views/layouts/app.blade.php`, `resources/views/layouts/pdv.blade.php`, `resources/views/auth/login.blade.php`, `password-request.blade.php`, `password-reset.blade.php`: carregam Google Fonts Roboto 400–800 (fallback offline para Arial). O CSS específico `erp-typography.css` é importado no fim dos layouts administrativos e PDV, **após** `desktop-polish.css`.
- As páginas de recuperação de senha receberam `?v=filemtime` em `login.css` para evitar CSS antigo em cache.
- `tests/Feature/ErpTypographyTest.php` (novo) verifica ordem da cascata, carregamento nas rotas, tamanhos, ausência da fonte antiga e isolamento de CSS impresso.
- `docs/ai/ARCHITECTURE_AND_DECISIONS.md`: registra a decisão como D02-A.

**Verificação concluída:** inspeção remota do conteúdo das 11 views/arquivos de estilos e teste, ausência da fonte antiga nos arquivos de interface, ordem correta da folha tipográfica, 80 chaves abertas/fechadas em `erp-typography.css`, sem fechamento negativo. **Não foram executados testes PHPUnit nem validação visual no localhost Windows nesta tarefa**: este ambiente tem acesso ao repositório GitHub, não ao Laragon do proprietário. A fonte remota Roboto depende da conectividade a Google Fonts; sem rede o fallback é Arial, nunca Segoe.

**Próximo teste recomendado no Laragon:**

```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
php artisan test --filter=ErpTypographyTest
php artisan test --filter=UiRefinementTest
```

Abra Financeiro/Produtos/Fiscal/PDV com `Ctrl+F5` e verifique zoom 100% e 125% (1366×768 e 1440×900), priorizando colunas e modais.

## Entrega anterior — 19/10/2026 — acabamento visual desktop

**Pedido:** aprimorar diretamente o visual do Lumeron sem trocar identidade, corrigindo inconsistências de barras de ação, tabelas, campos, responsividade e tela Fiscal.

**Branch:** `feature/acbr-nfe-bootstrap` no repositório `robsonmktsouza-stack/nextor`.

**Implementado e gravado no GitHub:**
- `public/css/desktop-polish.css` (novo): acabamento compartilhado só para tela, tamanhos/alinhamento de comandos, comportamento das barras quando falta largura, abas com rolagem horizontal, bordas e densidade de grades, seleção e foco, estados vazios, paginação, formulários/diálogos e adaptações em 1260/900/620/385 px. Preserva alturas existentes de linhas e tabelas editáveis.
- `resources/views/layouts/app.blade.php`: importa o novo CSS após `branding.css` e o JS de navegação após `erp.js`.
- `resources/views/layouts/pdv.blade.php`: importa o CSS após `pdv-lumeron.css`, mantendo layout/teclado independentes do PDV.
- `resources/views/fiscal/index.blade.php`: estado vazio sem texto solto; ação verdadeira somente nas abas NF-e e NFC-e com rota existente.
- `public/js/desktop-polish.js`: mantém a aba de módulo atualmente ativa visível quando o menu horizontal transborda, sem substituir links e marcando `aria-current`.
- `tests/Feature/UiRefinementTest.php`: verifica cascata dos estilos em módulos, preservação de impressão fiscal, tokens de responsividade, ações contextualizadas do vazio e carregamento da correção de navegação.

**Verificações realizadas:** busca de classes/rotas no código-fonte e conferência remota dos arquivos. Checagem estática de chaves do novo CSS: 106 aberturas e 106 fechamentos, sem profundidade negativa; carregamento e ordem nas duas views; presença do estado vazio e dos métodos de teste. `node --check` **passou** no script de navegação. Um pequeno teste de comportamento em Node com DOM simulado **passou** (rolagem da aba ativa + `aria-current`). **Não foram executados testes PHPUnit, migrações, serviço Laravel nem verificação visual do ERP nas resoluções alvo** neste ambiente remoto. Foi tentado teste headless com Chromium em um HTML isolado, mas houve timeout; isso não equivale a validação nem a falha da UI. O CI retornou sem execuções PR associadas ao commit consultado; não equivale a sucesso da suíte.

**Próxima ação para validação:** atualizar esta branch no Laragon, executar `php artisan test --filter=UiRefinementTest` e observar o painel nas resoluções 1366×768, 1280×720, 1024×768 e zoom 125%, com e sem sidebar. Registrar qualquer overflow real; corrigir na camada CSS e acrescentar teste antes de declarar aprovado.

**Não alterado:** banco, rotas, lógica comercial/fiscal, ACBr, autorização SEFAZ, impressões DANFE e configurações de VPS. **Não publicar em produção sem aprovação.**

## Pendências anteriores

- `composer.lock` não estava versionado na inspeção de 19/10/2026, prejudicando instalação reprodutível.
- NFC-e via ACBr para BA permanece em desenvolvimento/testes assistidos; não afirmar produção homologada nem transmitir dados reais sem autorização.
- Revisar continuamente `docs/ai/PROJECT_STATE.md`, `docs/ai/ARCHITECTURE_AND_DECISIONS.md`, `docs/deployment/` e `AGENTS.md`.

## Procedimento permanente de passagem de bastão

Ao concluir uma alteração futura, atualizar este registro com data/branch/commit, objetivo, arquivos alterados, decisões, testes realmente executados (com resultado), pendências e próximo passo. Não apagar contexto ainda importante sem substituí-lo.
