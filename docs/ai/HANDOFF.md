# Handoff entre chats e agentes — Lumeron ERP

Este documento registra o último trabalho concluído. Verifique sempre o código da branch ativa e `git status -sb` antes de continuar.

## Continuidade — 21/10/2026 — refinamento visual do modo de consulta (sem faixa fixa)

O usuário validou que o modo de consulta anterior estava funcional, mas considerou a **faixa azul grande acima do título Financeiro feia, fixa e invasiva**. Decisão visual permanente: retirar o banner e usar um chip compacto **"Modo consulta"** na topbar, sem consumir espaço vertical nos módulos. Indicador contextual explica a causa ao clicar (suspensa, vencida ou offline). Ações comerciais de escrita aparecem suavemente acinzentadas com pequeno cadeado, **sem atributo HTML disabled** (clique abre o modal); consultas, pesquisa, impressão e exportação continuam normais.

**Código alterado (somente develop):**
- `resources/views/partials/commercial-readonly.blade.php`: chip acessível com atributos de estado + modal existente; sem `commercial-readonly-banner`.
- `resources/views/layouts/app.blade.php` e `layouts/pdv.blade.php`: indicador inserido dentro das topbars, removido da `main-content` e da `pdv-screen`.
- `public/css/commercial-readonly.css`: estilo compacto coerente com o desktop ERP, tons discretos para ações bloqueadas, SVG cadeado e layout responsivo.
- `public/js/commercial-readonly.js`: identifica links de Novo/Editar, botões de envio de formulários de escrita, ações em massa e modais; anota e estiliza controles; ao clicar exibe modal sem enviar a ação. Para menus em massa, muda a aparência quando seleção é Exportar/Imprimir; observer atualiza resultados dinâmicos. Mantém proteção de submit/fetch/XHR e botão sair.
- `tests/Feature/CommercialReadOnlyLicenseTest.php`: checa chip dentro da topbar, sem banner, com acesso à listagem protegido pelo middleware.
- O `RequireCentralLicense` continua **bloqueando escritas no servidor** (a interface não é barreira de segurança). Fiscal legal e dados permanecem preservados. A `main`/`v1.0.0` seguem intocadas.

**Como conferir no Laragon:** backup do banco local, `git pull origin develop` no Nextor, `php artisan optimize:clear`, Ctrl+F5. Com licença suspensa abrir `/finance`, `/products`, `/pdv`: não há banner sobre o conteúdo, apenas chip no topo; `Conta a receber`/`Conta a pagar` e `Novo produto` devem aparecer neutros e clicar abre modal; filtros, histórico, impressão e exportação funcionam. Reativar no Control e atualizar página retira chip e restaura os botões. Não precisa rodar migrations, nem mexer no Control.

**Validação:** JS analisado sintaticamente pelo runtime. Aguardar último GitHub Actions `develop` (workflow atual sobre o head) antes de relatar total de testes; inicialmente os testes de UI antigo falhavam porque ainda procuravam o banner, foram atualizados. Não afirmar validação visual pixel-a-pixel de produção.


## Continuidade — 21/10/2026 — Lumeron suspenso mantém acesso em modo de consulta

**Decisão do proprietário:** substituir a página em tela inteira `/license-status` pelo ERP aberto com barra discreta "Modo de consulta" e modal elegante apenas quando o usuário tentar executar operação bloqueada. Manter login, cadastros, consultas, histórico, relatórios e impressão, **sem gravar dados comerciais**. Reativação normal pelo Control, sem logout ou novo cadastro. Mensagens específicas para `suspended`, `expired` e `unverified`.

**Implementado somente na `develop` (nunca tocar `main`/`v1.0.0`):**
- `app/Support/CommercialLicensePolicy.php`: política central de métodos HTTP, mensagens e nomes EXATOS de rotas fiscais preservadas. **Não** liberar `fiscal/*` em massa (permitiria novas emissões e alterações indevidas). Foram preservadas consulta de pendência, cancelamento fiscal, transmissão offline de nota anterior, inutilização, recuperação e download de XML.
- `app/Http/Middleware/RequireCentralLicense.php`: consultas/GET liberados; POST/PUT/PATCH/DELETE comerciais são negados no **servidor**, independentemente da interface. HTML: redirect 303 ao contexto anterior + flash warning; AJAX/JSON: HTTP 423 + `LUMERON_LICENSE_READ_ONLY`. CNPJ e HMAC mantidos; permissões do ERP inalteradas.
- `resources/views/partials/commercial-readonly.blade.php`, `public/css/commercial-readonly.css`, `public/js/commercial-readonly.js`: aviso compacto com status e modal organizado. Captura formularios normais e programáticos, links Novo/Editar e erros de fetch/XHR; defesa efetiva permanece middleware. Layouts `layouts/app.blade.php` e `layouts/pdv.blade.php` utilizam o mesmo componente.
- `routes/web.php`: antigas visitas a `/license-status` de suspensos redirecionam para a página inicial do ERP.
- Testes: revisão `CentralLicenseGuardTest`, novos cenários `tests/Feature/CommercialReadOnlyLicenseTest.php` cobrindo GET real de produtos, form HTML 303, métodos de escrita AJAX 423, exceção fiscal explícita, emissão fiscal bloqueada, mensagens suspensa/vencida/não-verificada, reativação e logout.
- **Evidência CI:** workflow develop `39619729674` verde com **429 testes / 2.761 assertions**, antes dos commits adicionais documentais; conferir CI mais recente antes de declarar branch final verde.
- Não executado deploy nem mudança no Windows do proprietário. Após `git pull origin develop` e `php artisan optimize:clear`, testar no Laragon: Control suspenso → ERP aberto em modo consulta → tentar salvar → modal → reativar no Control → operação liberada após cache até ~5 minutos.

**Importante:** o middleware HTTP não controla alterações de jobs agendados, workers e transmissões fiscais já enfileiradas; revisão adicional necessária antes de implantações em produção. Não desabilitar operações legais fiscais nem apagar XMLs. Comunicação Control/ERP e vínculo automático continuam como anteriormente implementados.


## Continuidade — 21/10/2026 — operação do Lumeron Control SEM copiar chaves nem confirmar no ERP

**Decisão obrigatória do proprietário:** apenas cadastrar/ativar/suspender/renovar empresas pelo painel administrativo e pronto, no fluxo de representante adaptado. **Não** solicitar UUID, token, arquivo `.env`, confirmação do administrador do ERP nem senha técnica ao cliente. O CNPJ identifica a empresa, mas NÃO é credencial de posse: o pareamento exige acesso privilegiado do sistema de instalação.

**Branch `control-center` (app privada e independente):**
- Cadastro já gerava segredos HMAC criptografados; agora eles permanecem somente no backend. Removidos do `CompanyController::store` o flash `new_secret`, da view `clients.show` a chave, dos detalhes o UUID e o botão de rotação, e da rota a geração manual.
- Botões simples Ativar/Suspender/Renovar/Plano/Módulos continuam no painel. Histórico por conta. O estado de licença `active` é diferente de `last_seen_at` e deve ser exibido como **aguardando instalação** quando a máquina nunca comunicou.
- Comando privado `app/Console/Commands/BindManagedInstallation.php`: `control:bind-local <diretório ERP> --url=<url painel>`, rodado pelo **operador**, obtém CNPJ real com o novo comando read-only da instância, localiza empresa no banco do Control e grava `storage/app/private/lumeron-control.json` com HMAC, UUID, CNPJ e URL. Não mostra credenciais, não pede confirmação no ERP, não altera .env e não aceita sobrescrever instalação de outro CNPJ. Faz health check no Control e handshake HMAC online no ERP antes de confirmar; se falhar, restaura vínculo anterior ou apaga o novo arquivo. HTTP somente loopback em APP_ENV local; remoto HTTPS.
- Testes ampliados `ManagedBindingTest` e `ControlPanelTest`; CI Control aprovado com **16 testes e 82 assertions** em [workflow 39611765666](https://github.com/robsonmktsouza-stack/nextor/actions/runs/39611765666), incluindo testes de rollback quando a instalação não confirma sincronização online.
- **Limite real:** não houve execução na máquina Windows do proprietário; comando tem de ser executado pelo operador uma vez na instalação existente, depois de ERP `develop`/migração. Um simples cadastro no painel NÃO instala código em clientes nem executa deploy remoto.

**Branch `develop` (ERP; `main`/`v1.0.0` não alterados):**
- Criados `app/Support/ManagedLicenseConfig.php` e comando `lumeron:license-identity` (somente leitura do CNPJ e validação da migration).
- `CentralLicenseService` e middleware `RequireCentralLicense` leem vínculo privado automaticamente; CNPJ precisa coincidir com `CompanySetting` e com o da instância, UUID/HMAC/HTTPS continuam verificados. Guard fail-closed se vínculo corrupto ou último estado persistido. Sem vínculo, não muda o comportamento da instalação.
- Manter compatibilidade temporária com configurações `LUMERON_CONTROL_*` antigas, mas **não orientar novo cliente a digitar isso**. A conexão usa cache de 5 minutos e graça offline controlada; XMLs e operações legais fiscais nunca podem ser apagadas/bloqueadas destrutivamente.
- Testes de vínculo interno adicionados em `CentralLicenseGuardTest` e CI `develop` aprovado com **418 testes e 2.710 assertions** em [workflow 39610747028](https://github.com/robsonmktsouza-stack/nextor/actions/runs/39610747028). Nova revisão de documentação/segurança pode gerar CI adicional; consultar o SHA antes de relatar resultado como atual.
- Fonte normativa: [docs/deployment/control-center-integration.md](../deployment/control-center-integration.md), `AGENTS.md` (seção 2-C), README do Control e README ERP.

**Próximos passos somente após solicitação:** atualizar os dois clones no Laragon, aplicar apenas a migration central no ERP com backup, executar o vínculo local a partir do Control (uma vez, sem expor credenciais), confirmar conexão pela última comunicação da empresa. A integração em deploys Linux remotos requer operador com acesso seguro aos dois ambientes ou serviço de provisionamento próprio; não afirmar que já foi implementado. Não tocar na tag `v1.0.0`.


## Continuidade — 20/10/2026 — Lumeron Control isolado e integração opt-in

**Pedido do proprietário:** desenvolver painel administrativo simples de clientes/licenças inspirado no fluxo do eGestor, **sem Sites, NFe+, revendedores, cobranças ou dashboards gigantes**, mantendo visual Lumeron.

**Projeto entregue no GitHub:** branch **`control-center`** (raiz de aplicação Laravel 13 própria, banco e sessão próprios; **jamais mesclar essa branch com a `main`/`develop` do ERP**). Conteúdo: login sem registro público; `control:admin` interativo; Início/Clientes/Licenças/Histórico; cadastro em três etapas, busca e modal de detalhes; liberar/suspender/renovar, planos, módulos, chaves rotativas, notas/eventos; API `POST /api/license/check` com HMAC, nonce e assinatura da resposta. `composer.lock` versionado, logotipo original da marca, testes `tests/Feature/`. Workflow `Lumeron Control checks` aprovado com **9 testes e 44 assertions** no GitHub. Não é VPS implantada nem repositório exclusivo ainda; clone separado via `git clone --branch control-center --single-branch ... lumeron-control` e servidor local porta 8002. O painel **não cria automaticamente usuários nem instalações**.

**Agente mínimo no ERP:** `develop` contém `config/central_license.php`, `app/Services/License/CentralLicenseService.php`, `app/Http/Middleware/RequireCentralLicense.php`, `app/Console/Commands/CentralLicenseSync.php`, migration `2026_10_20_220000_create_central_license_states.php`, `resources/views/errors/license-blocked.blade.php`, testes `CentralLicenseGuardTest`, documentação `docs/deployment/control-center-integration.md`, entrada do middleware e arquivo `.env.example`. **DESATIVADO POR PADRÃO** para não alterar operação do cliente nem o `v1.0.0`. HMAC na chamada e resposta, CNPJ/UUID conferidos, cache de 5 min, tolerância offline de 72 horas, módulos comerciais e continuidade de regularização fiscal acessível mesmo com licença suspensa. Ativação sempre por instância/CNPJ com autorização, backup/migração e URL HTTPS configurados. Documentar verificação final do GitHub Actions antes de declarar integração pronta.

**Não realizado:** novo repositório próprio para o painel, instalação VPS, DNS, dados de clientes, pagamentos ou envio fiscal. **Nenhuma empresa real foi cadastrada/ativada**. Próximas tarefas: definir servidor/domínio, migrar painel para repo exclusivo quando criado, configurar acesso real e publicar uma versão do ERP com agente aprovado (não alterar `main` sem novo pedido).


## Continuidade — 20/10/2026 — primeira promoção de código à `main` e abertura da `develop`

**Autorização:** o proprietário pediu explicitamente colocar a versão atual aprovada na `main`, criar a linha de desenvolvimento e preparar `v1.0.0`. Não autorizou instalar/deployar em servidores ou emitir notas reais.

**Executado no GitHub:**
- Comparação de `main` antiga (`36bbdbf5044d68d384fea5b9e7a1560b20363253`) contra `feature/acbr-nfe-bootstrap` confirmou histórico linear: **1.340 commits à frente e 0 atrás** no início da operação, sem divergência.
- Criado e validado `composer.lock` no GitHub Actions com PHP 8.3; o trabalho foi incorporado à feature e o workflow provisório gerador do lock foi **removido**. Não resolver dependências na VPS.
- Workflow `ACBr integration checks` ampliado para `main`, `develop`, `feature/acbr-nfe-bootstrap`, tags `v*` e PRs; valida a existência/integridade do lock. Regressões fiscais complementares passam a cobrir `main`/`develop`.
- A `main` foi promovida com `update_ref` **fast-forward, sem force push**, ao commit `8746a6cf0000f81a6ae1d43127fc6fc484a80607`, depois de novo CI completo aprovado.
- A `develop` foi criada a partir exatamente desse commit, para os próximos módulos. Os arquivos `AGENTS.md`, `CLAUDE.md`, `README.md` e `docs/ai/` foram atualizados na `develop` para instruir futuros agentes a não usar `main` como branch de trabalho.
- Será publicada a nota de versão `docs/releases/v1.0.0.md`. A tag `v1.0.0` só deve ser considerada existente **após** verificação do GitHub: seu job é condicionado a CI verde no SHA exato da `main`, com commit de aprovação e verificação de tag preexistente. Nenhuma rotina faz deploy.
- CI comprovada antes da primeira promoção: [workflow `39507374699`](https://github.com/robsonmktsouza-stack/nextor/actions/runs/39507374699); verificar resultados dos workflows posteriores, pois novos commits de documentação também geram CI.

**Obrigatório a partir de agora:** `main` = versão base estável, `develop` = próxima integração, `feature/*` = alterações menores, `hotfix/*` = urgência em release. Nada na `main` instala em cliente automaticamente. Diferenciar GitHub/tag de operação SEFAZ em produção. Manter um ambiente/banco/CNPJ, XMLs, APP_KEY e ACBr próprios por cliente. `php artisan serve` continua exclusivo de desenvolvimento.

**Pendente fora do GitHub:** definição de hospedagem (Linux ACBr própria ou Windows), HTTPS, servidor e worker gerenciados, backup externo e teste de restauração, aprovação individual para o primeiro deploy. Não prometer painel central criado nem infraestrutura pronta.


## Continuidade — 20/10/2026 — política PERMANENTE de versões e desenvolvimento paralelo à produção

**Decisão explícita do proprietário:** após extensa validação da NFC-e no Laragon, quer avançar para **piloto controlado em produção** e continuar desenvolvendo módulos e melhorias no Nextor/Lumeron **sem parar as vendas e emissão dos clientes**. Foi solicitada documentação durável no GitHub para que agentes futuros sempre respeitem esta regra.

**Fonte normativa recém-criada:** [`docs/deployment/versioning-and-release-policy.md`](../deployment/versioning-and-release-policy.md). **Ler antes de executar trabalhos envolvendo branches, migrações, releases, fiscal ou VPS.** Links e instruções também adicionados a `AGENTS.md`, `CLAUDE.md`, `README.md`, `docs/ai/README.md`, `docs/ai/PROJECT_STATE.md`, `docs/ai/ARCHITECTURE_AND_DECISIONS.md` e `docs/deployment/one-cnpj-per-instance.md`.

**Regra absoluta:** `git push`/desenvolvimento **não** publica automaticamente no cliente. Política futura: `main` para linha estável aprovada, `develop` para integração, `feature/*` para funcionalidades, `hotfix/*` para correção a partir da tag/commit instalado. Cada CNPJ roda release aprovado e isolado, publicado individualmente com backup, janela e possibilidade de recuperação. `v1.0.0` é **candidata futura, não tag criada**. `php artisan serve` só para desenvolvimento, não produção.

**Estado verificado nesta tarefa:** branch de escrita `feature/acbr-nfe-bootstrap` (no começo: `f3ddfb538ed2`); `main` estava desatualizada; `develop` não existia; `composer.lock` ainda não estava na raiz; workflow `ACBr integration checks` ativado para push na feature e PR; scripts `deploy/instances/provision.sh`, `update.sh`, `list.sh` já existem, com `--apply` explícito, isolamento por CNPJ e backup MySQL local em atualização. Linux requer ACBrLib própria e backup externo. Hospedagem final ainda não foi escolhida/confirmada.

**Escopo realizado:** SOMENTE edição/criação de documentação via GitHub, sem alterar código de negócio, composer, workflow, branch, tag, certificados, base de dados, DNS, VPS, instalações ou SEFAZ. Não foi criado release `v1.0.0`; não afirmar que já ocorreu deploy. Testes de aplicação não são necessários para esta alteração documental; conferir existência dos arquivos/links no repositório.

**Próxima tarefa quando o proprietário solicitar:** definir hospedagem e sequência de implantação, gerar/versionar `composer.lock` na branch de desenvolvimento, reconciliar `main` com a linha funcional, criar `develop`, ajustar CI para `main`/`develop`, e preparar primeiro release **sem implantar nada sem autorização específica**. Não reiniciar do zero a homologação fiscal sem motivo técnico concreto.


## Continuidade — 20/10/2026 — eliminar espera infinita ao abrir comprovante PDF

**Problema real:** após o ajuste de altura do comprovante, o usuário mostrou `/pdv/receipt/9?print=0` com aba do Chrome carregando indefinidamente, em branco. A rota `SaleReceiptPdfService::render` iniciava **Chrome/Edge headless externo em todas as consultas**, aguardando o processo e arquivo temporário. Esse fluxo era pesado e podia bloquear a requisição do Laragon.

**Mudança aprovada e aplicada na branch `feature/acbr-nfe-bootstrap`:**
- `app/Services/Receipts/ThermalReceiptPdfWriter.php` (novo) gera PDF 1.4 **em memória, diretamente no PHP** (fontes padrão, linhas, tabela, valores, layout térmico, imagem JPEG ou PNG inclusive PNG transparente), calculando a altura do documento a partir das linhas efetivamente desenhadas. Cada via vira exatamente uma página com largura 58 ou 80 mm, sem folha extra só para o rodapé.
- `app/Services/SaleReceiptPdfService.php`: `render(Sale)` não chama `proc_open`, Chrome, Edge, `usleep`, sistema de arquivos temporários ou fila. Reaproveita `SaleReceiptPresenter` para dados persistidos de venda, empresa, cliente, pagamentos, operador e configurações térmicas. Incorpora logotipo PNG/JPEG e WebP quando GD permite converter WebP para PNG. Conserva `renderHtml` apenas para compatibilidade com testes e a versão HTML da impressão inicial do PDV permanece intacta.
- Rotas `sales.receipt` e `pdv.receipt?print=0` seguem retornando `application/pdf` privado inline para visualizador PDF do Chrome, com permissões e políticas de cache anteriores; `?download=1` permanece disponível. A finalização original do PDV sem `print=0` mantém seu próprio HTML e impressão automática, sem afetar NFC-e/DANFE.
- `tests/Feature/SaleReceiptReuseTest.php`: valida geração sem browser, tempo inferior a dois segundos em fixture pequena, uso de logo RGBA transparente, via única 58/80 mm e exatamente duas páginas quando configuradas duas vias; não depende de `pdfinfo` nem Chromium para a regressão rápida.
- PDF de exemplo produzido em PHP no ambiente de desenvolvimento da IA: comprovante 80 mm com 1 item, 1 página, validade confirmada por `pdfinfo` e renderização visual por `pdftoppm`; cenário 58 mm, 13 itens, PNG transparente e duas vias confirmou 2 páginas. Benchmark local de 15 itens com imagem RGBA 900×360: aproximadamente **0,33 s** (não representa benchmark no PC do usuário).

**Limites honestos:** o motor PDF interno recompõe o comprovante a partir dos mesmos dados e regras, mas não reutiliza o CSS do HTML nem promete equivalência pixel a pixel. Imagens WebP precisam da extensão GD para conversão. Não foi acessado o localhost do proprietário; verificar a velocidade real e impressão física 58/80 mm no Chrome após `git pull`. Sem nova migration, biblioteca Composer, serviço pago ou deploy.

**Verificação:** consultar o último workflow `ACBr integration checks` e registrar resultados reais. No Laragon: `git status -sb`, `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`, reabrir `/pdv/receipt/9?print=0` (Ctrl+F5); deve mostrar PDF quase imediatamente e página única com o rodapé.

## Continuidade — 20/10/2026 — corrigir comprovante térmico dividido em duas páginas

**Problema reportado:** screenshot de `/pdv/receipt/9?print=0` no Chrome já mostrava o leitor PDF nativo, mas a impressão do comprovante de **uma via** estava dividida em duas páginas: a primeira terminava após dados do consumidor e a segunda continha só o rodapé não fiscal.

**Causa identificada no Blade:** `@page` usava altura fixa estimada pela quantidade de itens (`receiptHeightMm`). O cálculo não refletia altura variável do logo real incorporado, endereço, linhas quebradas, cabeçalhos/rodapés personalizados nem observações. Por isso o Chrome paginava o último trecho.

**Correção na branch `feature/acbr-nfe-bootstrap`:**
- Somente em `receiptPdfMode`, o Blade `resources/views/pdv/receipt.blade.php` usa a mesma largura térmica de 58/80 mm na tela e na impressão, com as mesmas margens internas; elimina a diferença entre medidas antes e depois do modo de impressão.
- Script leve `receipt-paper-fit` calcula a altura real de cada via através de `getBoundingClientRect()`, após montar os elementos e novamente em `load` (logo carregada); acrescenta uma pequena folga de 4 mm ao `@page` do PDF, garantindo espaço para todo o rodapé. Para duas vias intencionais, cada via continua em página própria, sem fragmentos extras.
- `tests/Feature/SaleReceiptReuseTest.php`: novos cenários no Chromium/pdfinfo para largura 80 e 58 mm, logo cadastrada, cabeçalho/rodapé, várias descrições longas e observações, verificando **1 página por via** e 2 páginas somente quando `receipt_copies=2`.
- Não alterar a rota, leitor PDF, DANFE, emissão fiscal, dados comerciais, PDF de orçamento nem CSS/JS do restante do sistema. O comportamento HTML de finalização automática do PDV continua sem o script de ajuste de papel.

**Validação:** conferir os resultados do workflow `ACBr integration checks` para os commits `be9130ff` (correção Blade) e `eff95515` (teste de paginação); a verificação visual no Chrome/Laragon do usuário ainda é necessária para confirmar o resultado com a logo real. Sem migrações.

**Atualização Laragon:** após `git status -sb`, `git pull origin feature/acbr-nfe-bootstrap` e `php artisan optimize:clear`, atualizar `/pdv/receipt/9?print=0` com Ctrl+F5. Com uma via configurada, deve haver só uma página PDF contendo rodapé; com duas vias configuradas, uma página por via.

## Continuidade — 20/10/2026 — comprovante de venda no leitor nativo de PDF do Chrome

**Solicitação e evidência:** captura real de `localhost:8000/pdv/receipt/9?print=0`. Ao reabrir uma venda pelo histórico do PDV, aparecia uma página HTML estreita com botões, painel da NFC-e e prévia do cupom; o proprietário pediu que abrisse como o orçamento: **PDF inline no leitor nativo do Chrome, com imprimir/baixar pelos próprios controles do navegador**, sem essa página intermediária.

**Alteração na branch `feature/acbr-nfe-bootstrap`:**
- `app/Services/SaleReceiptPdfService.php` (novo): usa os dados persistidos de `SaleReceiptPresenter`, o mesmo Blade `pdv.receipt` e a configuração térmica de **58/80 mm**. Chrome/Edge headless gera PDF em diretório privado temporário; incorpora a logo comercial cadastrada via data URI quando disponível; verifica assinatura/EOF e remove os arquivos temporários. Não cria venda, NFC-e, movimentação ou pagamento e não lê valores transitórios da sessão do PDV.
- `resources/views/pdv/receipt.blade.php`: modo `receiptPdfMode` reutiliza a apresentação do comprovante e retira ações HTML, alertas fiscais e o script de impressão automática do arquivo PDF; as cópias e largura de impressão continuam governadas pelas mesmas configurações do PDV.
- `SaleController::receipt`: `/sales/{sale}/receipt` entrega `application/pdf` com `Content-Disposition: inline`, `no-store`, permissão de Vendas, e `?download=1` opcional. Orçamentos continuam rejeitados nessa rota.
- `PdvController::receipt`: a consulta histórica `/pdv/receipt/{sale}?print=0` devolve o mesmo PDF inline, sem janela HTML. **A finalização normal do PDV (URL sem `print=0`) mantém a impressão automática e o retorno ao caixa.** Se houve erro no preparo da NFC-e, os cinco redirecionamentos agora usam `?print=0&status=1` para preservar a página explicativa sem impressão automática, em vez de ocultar o erro dentro do PDF.
- `tests/Feature/SaleReceiptReuseTest.php`: regressões de acesso, PDF inline/download, página de venda, conteúdo da via térmica, retorno automático do PDV, erro fiscal visível e ausência de dados de caixa da sessão anterior. Inclui geração real de PDF no CI quando Chromium estiver instalado.

**Sem mudanças:** rotas e nomes dos links, impressão fiscal DANFE, regras de NFC-e, PDF A4 de orçamentos, banco/migrations, estoque, financeiro, operações de caixa e VPS. A aparência do PDF é a do cupom térmico existente, **não A4**; a diferença é o visualizador nativo do navegador.

**Conferência no Laragon:** `git status -sb`, `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`, `Ctrl+F5`. Abrir **PDV → histórico → comprovante** (URL `?print=0`) e **Vendas → Comprovante de venda**; as duas abas devem mostrar a barra de ferramentas do leitor PDF do Chrome, não os controles HTML antigos. Concluir uma nova venda no PDV e confirmar que a impressão automática do caixa permanece. Testar 58/80 mm e duas vias antes do uso real. Não há migração por esta mudança e não houve acesso ao Laragon do proprietário.

**Validar:** execução `ACBr integration checks` no último commit do trabalho, especialmente PHP lint, Blade, testes de `SaleReceiptReuseTest` e teste de PDF real pelo Chromium. Não presumir aprovação até a CI concluir.

## Continuidade — 20/10/2026 — reconciliação operacional e auditoria do caixa PDV

**Pedido:** o proprietário solicitou corrigir completamente a abertura/fechamento do caixa e rastreabilidade de troco, sangrias, suprimentos, devoluções e relatórios após auditoria do fluxo. Trabalho na branch `feature/acbr-nfe-bootstrap` (sem deploy/SEFAZ). Não confundir obrigações fiscais da NFC-e ou escrituração legal com controle gerencial de caixa.

**Nova migração:** `2026_10_20_180000_reconcile_pdv_cash_sessions.php` liga `sales.pdv_cash_session_id` ao turno (nullable para vendas legadas), congela `expected_amount`, `difference_amount` e `closing_breakdown` no fechamento e adiciona `closed_by`, `reviewed_by`, `reviewed_at`, `review_notes`. `pdv_cash_movements` permite `sale_id` e `sale_return_id` para rastrear devoluções pagas em dinheiro. Não rodar migração em produção sem aprovação e backup.

**Motor único:** `app/Services/PdvCashService.php`. Abre/fecha com transação e lock no usuário e na sessão; uma venda de PDV adquire lock na sessão ainda dentro da transação do `SalesService`, de modo que fechamento simultâneo não desassocia venda da apuração. O saldo esperado considera fundo inicial + parcelas de vendas em espécie + suprimentos − sangrias − reembolsos **efetivamente registrados**, usando valores `SalePayment.amount` líquidos de troco (não subtrair `change_amount` novamente). O valor contado é informado sem pré-preenchimento: diferença não nula exige observação. Fechamento congela os dados originais; revisão administrativa é auditada e só pode ocorrer uma vez. Vendas canceladas ainda representam dinheiro recebido até que o dinheiro seja comprovadamente reembolsado por movimento próprio. Devoluções de mercadorias não presumem automaticamente saída de dinheiro. Os reembolsos de PDV exigem venda cancelada ou devolução concluída, limite de dinheiro originalmente pago, limite da devolução, saldo de caixa disponível e motivo; concorrências entre devoluções da mesma venda são serializadas no banco. Devolução com reembolso registrado não pode ser cancelada silenciosamente pelo módulo de devoluções.

**Telas/rotas:** `PdvController` delega abertura, fechamento e movimentos ao serviço existente; formulário de contagem em branco, ações de reembolso vinculado e acesso ao histórico na interface PDV. `PdvCashReportController` e templates `pdv/cash-history.blade.php` e `pdv/cash-report.blade.php` permitem listar e imprimir/Salvar PDF no Chrome. Operador só vê seus caixas, administrador vê todos e pode registrar conferência com justificativa. Rotas `pdv.cash.history`, `pdv.cash.report`, `pdv.cash.review` exigem autenticação e permissão de PDV, última ainda valida role admin. CSS/JS apenas ajustes pontuais ao PDV, sem redesign do ERP.

**Compatibilidade histórica e limites importantes:** `require_cash_opening` **continua configurável**, com default antigo `false`; para garantir vinculação de **todas** as vendas a sessões, habilite **Configurações → PDV → Exigir abertura de caixa** e mantenha essa regra ativa. Vendas anteriores sem vínculo são apuradas como legado por operador e janela de tempo; não fingir ter sessão vinculada na época. Fechamentos antigos sem snapshot continuam exibidos por cálculo histórico, sem afirmar que os valores eram congelados. Reembolso em espécie depende de confirmação/lançamento pelo operador; a funcionalidade não cria baixa bancária, escrituração contábil ou um crédito automático para devoluções. Reconciliar esses movimentos com Financeiro conforme política de cada empresa. A impressão do caixa é comprovante operacional, **não substitui documento fiscal nem Livro Caixa/contabilidade**. Revisar com cliente a política de permissões, suprimentos e diferenças antes de produção.

**Testes:** `tests/Feature/PdvCashReconciliationTest.php` cobre venda vinculada, pagamentos mistos, troco sem desconto duplicado, sangria/suprimento, divergência, snapshot, dupla abertura/fechamento, reembolsos rastreados e bloqueios/permutações, histórico/permissões e não cancelamento de devolução com dinheiro devolvido. Execução `ACBr integration checks` do commit `9d122f1e` aprovada: **408 testes e 2645 assertions** em https://github.com/robsonmktsouza-stack/nextor/actions/runs/39479772602; verificar CI do HEAD posterior à proteção adicional de concorrência em reembolsos. Testes automatizados não substituem simular caixa real no Laragon.

**Laragon (após backup, conferir branch e estado local):**
```powershell
cd C:\laragon\www\nextor
git status -sb
git pull origin feature/acbr-nfe-bootstrap
php artisan migrate
php artisan optimize:clear
```
Validar com operador e administrador: abrir fundo de troco, venda PIX+dinheiro com troco, sangria, suprimento, devolução de dinheiro, contagem/falta/sobra, relatório PDF e aprovação administrativa. Nenhuma transação real foi transmitida.

## Continuidade — 20/10/2026 — prévia do comprovante no Chrome, sem imprimir automaticamente

**Pedido:** após incluir comprovante comercial em **Vendas**, o proprietário reiterou que a venda deve **abrir em nova aba do Chrome, permitindo conferir e só depois imprimir**. A versão anterior fazia isso apenas quando a URL carregava `?print=0`: abrir `/sales/{id}/receipt` diretamente disparava `window.print()` e causava uma experiência indesejada.

**Correção no mesmo fluxo (sem novo PDF nem modelo paralelo):**
- `resources/views/pdv/receipt.blade.php`: JavaScript de impressão automática agora exige `$receiptOrigin==='pdv'` **e** ausência de `?print=0`; para origens `sales`, a prévia nunca chama `window.print()` no `load`, mesmo com `?print=1`. Apenas o clique em **Imprimir comprovante interno** chama o diálogo do navegador. Após imprimir pela área Vendas, a aba permanece aberta.
- CSS `@media screen` e classe `sales-receipt-preview`: prévia mais larga e legível na aba Chrome, somente para vendas comuns. O CSS `@media print` continua com a largura térmica configurada em 58/80 mm, preservando o impresso do PDV e seus recibos.
- `resources/views/sales/show.blade.php` e `sales/index.blade.php`: hyperlinks `target="_blank" rel="noopener noreferrer"` continuam, mas agora usam a rota direta `sales.receipt` **sem `?print=0`**. Funciona também ao colar URL manualmente.
- `tests/Feature/SaleReceiptReuseTest.php`: cobre abertura direta, URLs antigas `?print=0`, `?print=1`, visualização sem impressão automática, nova aba em links e preservação da impressão automática padrão no PDV. Sem alterações no banco, rotas, NFC-e, estoque ou financeiro.

**Validar:** conferir CI da branch `feature/acbr-nfe-bootstrap` após commit `4d99d7ee`; caso ainda em execução, não alegar sucesso. Revisar manualmente no Chrome que uma venda comum abre na aba com texto legível, imprimir exibe janela do Chrome (opção Salvar como PDF) e o PDV continua imprimindo por padrão conforme seu fluxo atual. Atualização Laragon: `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`, Ctrl+F5. Nenhuma migration.

## Continuidade — 20/10/2026 — impressão de venda comum com o mesmo comprovante térmico do PDV

**Solicitação:** no módulo Vendas, uma venda comum só apresentava impressão da NFC-e quando disponível; o proprietário pediu **imprimir também o comprovante não fiscal de venda, exatamente o mesmo já existente no PDV**. Não criar PDF A4 paralelo nem confundir recibo comercial com DANFE.

**Implementado na branch `feature/acbr-nfe-bootstrap`:**
- `app/Services/SaleReceiptPresenter.php` (novo) extraiu o carregamento de itens, pagamentos, formas, configurações de impressão 58/80 mm, emitente e eventual NFC-e já ligada à venda, antes feito diretamente em `PdvController::receipt`. No PDV, mantém uso da sessão de caixa `pdv_cash_received`/`pdv_change`; no módulo Vendas, usa apenas os valores persistidos na própria venda, não a sessão do caixa.
- `PdvController::receipt` continua restrito a `sale.source=pdv` e passa a reutilizar o mesmo presenter com `fromPdv=true`. Não houve mudança de autorização, preparação ou transmissão fiscal.
- `SaleController::receipt` (novo) mostra a **mesma view `pdv.receipt`** para `operation_type=sale`, inclusive para vendas criadas fora do PDV; recusa orçamento com 404. Nova rota GET `sales.receipt` em `/sales/{sale}/receipt`, com middlewares `permission:sales` e `company.configured` em `routes/web.php`.
- `resources/views/pdv/receipt.blade.php` agora diferencia somente a origem server-side: em Vendas exibe `Voltar à venda`, oferece `Imprimir comprovante interno`, não mostra controles de preparação NFC-e/status do fechamento de caixa e não redireciona para o PDV após imprimir; em PDV mantém integralmente sua experiência atual, incluindo fluxo de impressão e retorno ao caixa. O cupom é **SEM VALOR FISCAL** nos dois fluxos.
- `resources/views/sales/show.blade.php` exibe botão `Comprovante de venda` para operações `sale`, separado do botão `Imprimir DANFE NFC-e` fiscal. `sales/index.blade.php` exibe atalho de impressão para vendas; orçamentos preservam seu atalho PDF.
- `tests/Feature/SaleReceiptReuseTest.php` (novo) verifica sessão/autenticação/permissão de Vendas, distinção de orçamento, reaproveitamento do template, dados monetários/pagamentos e isolamento da sessão do PDV, rota antiga PDV exclusiva e ausência de movimentação por impressão.

**Resultados:** execução `ACBr integration checks` do commit `4440f0fa` em https://github.com/robsonmktsouza-stack/nextor/actions/runs/39469867539 passou com **401 testes e 2.582 assertions** na suíte completa, além das verificações de sintaxe, Blade, navegador e fiscal direcionado. Após este registro de documentação, conferir o CI do novo HEAD se necessário. Nenhuma venda real, NFC-e ou operação em produção foi criada/transmitida. Sem novas migrations.

**Laragon:** `cd C:\laragon\www\nextor`, `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear` e `Ctrl+F5`. Abrir **Vendas → venda existente → Comprovante de venda** (ou atalho da listagem); a tela é visualização térmica, não um PDF A4; imprimir manualmente e conferir o papel 58/80 mm configurado no PDV. Não exigir permissão `pdv` para um vendedor que já tem `sales`.

## Continuidade — 20/10/2026 — correção de paginação e escala A4 do orçamento

**Problema reportado com capturas reais:** o PDF do Excel do proprietário é **uma folha A4**, com conteúdo desde cerca de 4 mm da borda lateral, cabeçalho mais abaixo e corpo tipográfico legível. O documento exportado pelo Nextor em `/sales/11/quote/pdf` estava em **duas páginas**, a segunda praticamente vazia, com conteúdo excessivamente estreito e texto menor. O orçamento #00011 da captura tem apenas um item, ao passo que a referência tem nove — comparar a altura sem preservar a grade gera uma página visualmente muito diferente.

**Correções somente no modelo `resources/views/sales/quote-pdf.blade.php`:**
- `@page A4` mudou de `12mm 10mm 17mm` para `20mm 3mm 14mm` (topo mais próximo da planilha e largura útil maior);
- Removido o `position:fixed; bottom:-12mm` no rodapé, que podia projetá-lo para fora da área de impressão e ocasionar folha fantasma; aviso fiscal agora acompanha o fluxo normal;
- Margem superior de assinaturas reduzida de 23 para 13 mm;
- Grade preserva o mínimo visual de **nove linhas** com `empty-row` vazias, sem criar itens em banco ou simular produto. Em orçamentos mais longos, linhas reais continuam e há paginação natural;
- Fonte (corpo, empresa, seções, rótulos, itens, totais e assinaturas) aumentada para acompanhar o Excel exportado, em vez de versões minúsculas anteriores.

**Regressão obrigatória** em `tests/Feature/QuoteCommercialDocumentTest.php`: usa Chrome/Chromium real em CI para gerar PDFs com 1 e 9 itens e verifica **exatamente uma página** usando `pdfinfo`, quando disponível no runner Linux. Em Windows o comando externo é dispensável; inspecionar visualmente no Chrome/Edge continua obrigatório. O commit `8676a5dc` passou CI de 396 testes, 2.539 assertions. A última alteração de fonte é `d58d6c21`; conferir CI desse commit e do HEAD antes de atestar aprovação. Nenhuma mudança em `erp.css`, banco, regras fiscais ou vendas. **Sem migrations** nesta atualização.

**Atualização Laragon:** `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`, reabrir o PDF `/sales/11/quote/pdf` com Ctrl+F5. Testar impressão em A4 do orçamento #00011 e um de nove linhas, confrontando com o PDF do Excel anexado pelo proprietário.

## Continuidade — 20/10/2026 — orçamento PDF reproduzido do Excel/PDF editados pelo proprietário

**Solicitação e fontes:** o proprietário anexou `Modelo_Impressao_Orcamento_Nextor(1).xlsx` e `Modelo_Impressao_Orcamento_Nextor.pdf` editados por ele e autorizou aplicar exatamente este desenho ao Nextor/Lumeron. **Os arquivos enviados, sobretudo a página A4 exportada no Excel, são agora a referência visual específica do orçamento**, superando propostas visuais anteriores. Não implementar sistema de cadastro em Excel nem alterar o visual do ERP como um todo.

**Estrutura reproduzida** em `resources/views/sales/quote-pdf.blade.php`: cabeçalho com logo cadastrada opcional, nome fantasia/razão social, CNPJ, IE, endereço e contatos; título **ORÇAMENTO COMERCIAL** e número em destaque à direita; faixa horizontal verde-petróleo `#477B78`; linha `PROPOSTA DE FORNECIMENTO | PRODUTOS E SERVIÇOS`; seção `DADOS DO CLIENTE` com três colunas e campos de cliente, CPF/CNPJ, contato, endereço, validade com dias, responsável, forma de pagamento e situação; `DISCRIMINAÇÃO DOS ITENS` em nove colunas (ITEM, TIPO, CÓDIGO, DESCRIÇÃO, UN., QTD., VLR. UNIT., DESCONTO, VALOR TOTAL) com grade compacta e zebra cinza muito clara; parte inferior em colunas `CONDIÇÕES COMERCIAIS` e `RESUMO FINANCEIRO` com Produtos, Serviços, Descontos e Total Geral; observações e duas assinaturas (`Responsável pela proposta` / `Aceite do cliente`). Tipografia Calibri/Arial, branco, verde-petróleo `#477B78`, cinza claro `#E9EFF0`, A4, sem cartões arredondados ou enfeites de IA.

**Dados e limitações:** substituir dados de demonstração pelos dados reais da instância e orçamento. Nome fantasia e razão social são exibidos quando distintos; **não imprimir o placeholder `SUA MARCA`**: se não há logo, imprimir cabeçalho textual alinhado. Produtos/serviços e descontos são calculados apenas a partir dos itens persistidos, sem alterar a venda. Campo `Forma de pagamento` mostra nomes das formas já gravadas, quando houver; caso contrário `A combinar`. Prazo de entrega/execução inexistente no modelo de dados aparece como `A combinar`, e não inventamos `5 dias úteis`, bandeiras ou qualquer forma específica de pagamento. Observações são as do orçamento/rodapé comercial já configurado. Identificação de documento não fiscal e cabeçalho da tabela repetível em múltiplas páginas permanecem.

**Arquivos desta entrega:** `resources/views/sales/quote-pdf.blade.php`, `app/Services/QuotePdfService.php` (carregamento existente de `payments` para apresentar meios cadastrados), `tests/Feature/QuoteCommercialDocumentTest.php` (regressão estrutural 9 colunas, data em dias, pagamento gravado, somatórios, proteção comercial). Nenhuma alteração em `public/css/erp.css`, banco, rotas, serviço de vendas ou emissão fiscal. Nenhuma migração.

**Validação:** conferir workflow `ACBr integration checks` após o commit `704b148f`, mais commits subsequentes. Dois testes de versões intermediárias acusaram ausência da razão social alternativa e expectativa antiga do subtotal; ambos foram corrigidos preservando o desenho do Excel. Testar visualmente no Laragon, inclusive PDF com 9+ itens e logos reais, antes de afirmar equivalência pixel a pixel. Não houve acesso ao computador ou emissão fiscal.

**Atualização:** na branch `feature/acbr-nfe-bootstrap`, `git pull origin feature/acbr-nfe-bootstrap` e `php artisan optimize:clear`; atualizar o PDF existente em `/sales/11/quote/pdf`. Não executar migração por esta revisão.

## Continuidade — 20/10/2026 — documento comercial inspirado na referência eGestor

**Pedido mais recente:** o usuário mostrou um orçamento real do eGestor e pediu aplicar aquela **pegada de documento comercial enviado ao cliente**, mantendo a organização superior do modelo atual do Lumeron. Não copiar logotipo/layout de terceiros nem alterar o tema global; o histórico de correção indevida da paleta do ERP foi revertido.

**Aplicado exclusivamente no PDF comercial:** em `resources/views/sales/quote-pdf.blade.php`, título/número do orçamento mais visível no topo direito, hierarquia melhor de empresa, cliente, validade e situação; títulos de seção com fundo cinza suave e barra vertical verde-acinzentada; cabeçalho da grade em verde-petróleo moderado (`#4f7d7c`), mantendo dados, linhas e zebra leve em tons neutros; descrição distingue produto/serviço com rótulo tipográfico discreto; resumo mais firme com total geral e subtotais brutos de Produtos/Serviços **apenas se houver ambos** (soma de `line_total+discount` de cada grupo), sem alterar `subtotal`, `discount_total` ou `total` persistidos. A4, logo real da empresa, rodapé não fiscal e múltiplas páginas preservados. **Sem marca d'água, cartões coloridos ou recursos supérfluos.**

**Arquivos da rodada:** `resources/views/sales/quote-pdf.blade.php` (única alteração funcional/visual), `tests/Feature/QuoteCommercialDocumentTest.php` (novo cenário de produto + serviço e totais, além da regressão anterior), e esta documentação. Não alterar `erp.css`, demais telas, estoque, financeiro, fiscal, serviços de PDF ou banco.

**Validação:** verificar a execução do workflow `ACBr integration checks` após o commit `476db373` e eventual commit desta documentação. O renderizador real Chrome/Edge deve ser revisado no Laragon com um orçamento de vários itens e um com produto + serviço. Nenhuma emissão real, migração ou deploy foram realizados.

**Atualização local (branch feature/acbr-nfe-bootstrap):** `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`, recarregar `/sales/11/quote/pdf`. Não executar migrations por causa desta alteração.

## Continuidade — 20/10/2026 — acabamento fino do PDF de orçamento ERP

**Solicitação:** o usuário aprovou a direção de relatório empresarial tradicional para o orçamento, mas pediu melhorar seu acabamento. **Não reinterpretar como autorização para alterar as cores do ERP inteiro:** o episódio de paleta global foi revertido e permaneceu fora do escopo.

**Mudanças estritamente locais:** `resources/views/sales/quote-pdf.blade.php` recebeu refinamento de tipografia (corpo 10,5px, hierarquia contida), margens A4, espaçamento e alinhamento de cabeçalho/identificação empresarial, número/data/validade, campos de cliente, zebra muito leve e bordas alinhadas na tabela, números monetários tabulares e área de totais compacta, com fundo cinza neutro e um filete cinza-azulado discreto. Campos opcionais sem dado exibem um traço, evitando repetição de “Não informado”. O aviso não fiscal permanece no rodapé fixo. **Nenhuma mudança em nomes, valores, fórmulas, banco, conversão de orçamento, serviço de PDF, rotas ou temas globais.**

**Regressão:** `tests/Feature/QuoteCommercialDocumentTest.php` passou a verificar o acabamento impresso A4 (tabela, fundo neutro, números tabulares, aviso e ausência de cartão azul); preservados os testes existentes de geração real de PDF pelo Chromium e conversão de orçamento. Confirmar resultado do workflow `ACBr integration checks` no HEAD antes de declarar entrega validada. Atualização Laragon apenas `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear` e reabertura do PDF com Ctrl+F5; sem migração.

## Continuidade — 20/10/2026 — orçamento PDF visual de ERP tradicional

**Solicitação mais recente:** após visualizar o PDF real de `/sales/{sale}/quote/pdf`, o proprietário rejeitou o estilo de peça publicitária/gerada por IA. O documento precisa ter aparência de **relatório comercial de ERP clássico**, sem cartões arredondados, tons saturados, títulos de marketing, status em badges ou total em faixa azul grande.

**Implementação:** `resources/views/sales/quote-pdf.blade.php` totalmente reestruturado apenas na apresentação. Agora contém cabeçalho empresarial compacto (logo da empresa opcional, razão social, CNPJ, contatos), título/número do orçamento e emissão/validade/situação em área tabular discreta, dados do cliente em grade com bordas retas, tabela compacta de produtos com SKU, unidade, quantidade, preços, descontos e valor total, resumo de totais sem cartão colorido, observações e aviso não fiscal no rodapé de impressão. Tipografia Arial, cinzas neutros, linhas finas, A4 e cabeçalho de itens repetível em mais de uma página. Retiradas frases como “Proposta comercial” e “Segue o detalhamento”. A mudança não altera os cálculos, arquivos gerados, permissões, estado do orçamento nem fluxo de conversão.

**Serviço:** `app/Services/QuotePdfService.php` carrega `items.product` para mostrar unidade cadastrada do produto, evitando consultas por linha; cai para `UN` quando ausente e para `SV` em serviços. Sem alteração de banco ou migrations nesta rodada.

**Regressão:** `tests/Feature/QuoteCommercialDocumentTest.php` passou a exigir o layout tabular de ERP e a ausência dos antigos cartões, mantendo testes do PDF real via Chromium, segurança, logo e conversão. CI do commit `79047f43` aprovado; confira execução de HEAD ao concluir. Para atualizar no Laragon: `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`, Ctrl+F5 e reabrir o PDF. **Nenhuma nova migração para este ajuste visual.**

## Continuidade — 20/10/2026 — PDF comercial personalizado de orçamento e conversão segura

**Pedido:** em **Vendas > Nova venda/orçamento**, permitir gerar proposta A4 com logo e dados da própria empresa para baixar e enviar ao cliente, sem fazer orçamento parecer documento fiscal. Dar acesso ao arquivo também pela consulta e listagem. Converter o orçamento em venda com aproveitamento de itens e valores sem movimentar estoque/financeiro na etapa de cotação.

**Branch:** `feature/acbr-nfe-bootstrap`, a partir de `5453516f`. Não há envio a SEFAZ, alteração de produção ou novo provedor PDF. Arquivos principais:
- `app/Services/QuotePdfService.php` (novo): renderização real de PDF A4 por Chrome/Edge local, arquivo temporário privado/limpo, cabeçalhos HTTP privados; usa logo da empresa cadastrada em `CompanySetting`/disco público, incorporada em data URI. `resources/views/sales/quote-pdf.blade.php` (novo) contém layout comercial com CNPJ/endereço, cliente, vendedor, validade, itens, descontos, totais, observações e rodapé.
- `SaleController.php`, `routes/web.php`: rota GET protegida `sales.quote.pdf` que abre PDF inline ou baixa com `?download=1`; POST protegido `sales.quote.convert`. O usuário precisa de sessão + permissão sales + configuração da empresa. Em nova venda, `after_save=pdf` redireciona ao PDF apenas para orçamento.
- `resources/views/sales/create.blade.php`: validade opcional e botão Salvar e visualizar PDF somente para tipo orçamento; `show.blade.php` apresenta PDF e conversão com confirmação; `index.blade.php` tem atalho PDF e filtro de status convertido.
- `SalesService.php`, `app/Models/Sale.php`, `database/migrations/2026_10_20_100000_add_source_quote_to_sales.php`: conversão pela mesma transação de vendas e estoque, bloqueio de vencidos/cancelados/já convertidos e `source_quote_id` único. Cotação original fica marcada converted e vinculada à venda, sem nova baixa/cobrança no orçamento. Falha por estoque insuficiente não efetiva conversão.
- `tests/Feature/QuoteCommercialDocumentTest.php` (novo): autenticação, cabeçalho inline/attachment e cache privado, PDF HTML com dados comerciais, PDF real quando Chromium presente, logo local, cotação não mexe em estoque/financeiro, conversão preserva valores, bloqueia duplicata e status vencido/cancelado. Documentação detalhada: `docs/commercial/quote-pdf.md`.

**Evidência:** commit de implementação `980ceadd` passou `ACBr integration checks` com **392 testes e 2.487 assertions**, incluindo cenários de PDF e conversão. Há um teste adicional de logo em commit posterior; conferir CI do HEAD antes de declarar essa última variante aprovada. Os testes não equivalem a revisão visual do PDF no Laragon com a marca real do cliente.

**Laragon:** após backup e confirmação de branch sem alterações locais, `git pull origin feature/acbr-nfe-bootstrap`, `php artisan migrate` (**nova migração necessária**), `php artisan optimize:clear`, Ctrl+F5. Verificar Chrome/Edge instalado e PHP `proc_open` habilitado. A URL do PDF é privada, não compartilhável como link público: baixe e anexe manualmente no WhatsApp/e-mail. Não foram implementados integração WhatsApp automática, aprovação eletrônica ou editor drag-and-drop de layout.



## Continuidade — 19/10/2026 — correção funcional de cartões NFC-e/PDV

**Pedido:** a aba Pagamento de Nova NFC-e não mostrava cartão de crédito/débito apesar dos códigos originais; separação dos tipos nas Configurações, compatibilidade com pagamentos antigos, emissão ACBr correta e pagamentos mistos, sem envio a SEFAZ.

**Causa encontrada:** a migration original `2026_10_02_000005_create_settings_module.php` semeia `credit_card` e `debit_card` com o mesmo tipo genérico `card`, mas `NFCeManualController` só exibe e aceita os tipos `credit_card` e `debit_card`.

**Alterações na branch `feature/acbr-nfe-bootstrap`:**
- `database/migrations/2026_10_19_210000_reconcile_card_payment_kinds.php`: conciliação idempotente dos DOIS códigos internos originais. Só reclassifica `kind=card` nos registros exatos ou insere os ausentes; nunca muda nomes personalizados, taxas, contas, ordem, disponibilidade PDV ou ativo/inativo. `down()` sem remoção destrutiva.
- `app/Models/PaymentMethod.php`: resolvedor compatível com códigos originais e aliases conhecidos `credit-card/debit-card`; cartões genéricos ambíguos ficam com tipo `card` e não são convertidos por heurística de substring.
- `SettingsController` e `settings/index.blade.php`: crédito e débito separados; tipo `card` legível/editável apenas nos históricos, não criado em novos cadastros.
- `NFCeManualController`: opções de catálogo ativas apenas com tipo fiscal válido, inclusive legados reconhecíveis; bloqueio server-side de formas ambíguas antes de criar venda; validação de dados TEF/POS mantida.
- `PdvController`/`public/js/pdv.js`: reconhecimento de débito/crédito em todos os caminhos de seleção, cartão integrado e pagamento misto; validação de adquirente e autorização antes de salvar venda; tipos antigos `card` continuam identificáveis.
- `FiscalPreparationService`: congela `payment_kind` reconhecido no snapshot da NFC-e. `NFCeIniBuilder`: reconcilia soma das parcelas em centavos e gera `tPag` 03/04/17/01/15/18, grupo eletrônico quando aplicável e `vTroco` no último pag; não inventa adquirente ou integração. `NFCePreflightService`: recusa dados eletrônicos incompatíveis com dinheiro/boleto, pagamento negativo/zero ou soma diferente.
- `App/Support/NFCeCardBrands.php`: catálogo único de `tBand` 01–27 e 99; usado por validação Laravel, preflight, PDV e renderização NFC-e via JSON de servidor.
- `tests/Feature/NFCeManualEditorTest.php`, `NFCePaymentIniRegressionTest.php` (novo), `SettingsModuleTest.php`, `PdvFiscalCheckoutFlowTest.php`: regressões dos dois cartões, venda única com dinheiro+PIX+débito, migração idempotente, bloqueio do legado ambíguo, dados TEF/POS e tPag/vPag/tBand/cAut/CNPJ e troco.
- Matriz de cobertura e limites em `docs/fiscal/nfce-payment-methods-2026.md`.

**Ajustes finais:** o PDV agora exige soma exata em centavos antes de fechar a venda; a tela e o servidor impedem bandeira de cartão no PIX e permitem PIX sem integração fictícia; `NFCeCardBrands::codes()` normaliza chaves numéricas de dois dígitos para comparações estritas. Testes de PIX com bandeira indevida também foram incluídos. A regra permanente de separação crédito/débito está documentada em `ARCHITECTURE_AND_DECISIONS.md`.

**Evidência de CI:** GitHub Actions no commit `32da968c` finalizou `ACBr integration checks` verde: **385 testes, 2.424 assertions**, com as verificações do Chromium e dos testes fiscais direcionados aprovadas. Confirmar a execução no HEAD se houver commit posterior só de documentação.

**Importante:** nenhum documento foi transmitido, não houve acesso ao banco Laragon/VPS do proprietário. O GitHub Actions executa PHP/SQLite/Chromium e valida INI, **não XML assinado/autorizado em ACBr Windows/SEFAZ-BA**. Para aplicar no Laragon: `git pull origin feature/acbr-nfe-bootstrap`, `php artisan migrate` (nova migração necessária), `php artisan optimize:clear`; recarregar com Ctrl+F5. Não rodar em produção sem aprovação.

## Continuidade — 19/10/2026 — lista de produtos cortada na Nova NFC-e

**Problema observado no Chrome/Laragon:** o dropdown da busca em `/fiscal/nfce/create` era recortado logo abaixo do campo, dentro do modal da NFC-e. A lista absoluta era filha da área rolável `.nfe-workspace-body`, que deliberadamente tem `overflow-y:auto!important` e `overflow-x:hidden!important`. Aumentar `z-index` ou liberar `overflow` do modal não resolveria com segurança.

**Correção na branch `feature/acbr-nfe-bootstrap`:**
- `public/js/nfce-editor.js`: a própria lista `#nfceProductResults` usa Popover API `manual` em navegadores compatíveis, sem mudar IDs/HTML do formulário. Quando aberta, calcula posição fixa pela busca, largura disponível, espaço acima/abaixo e limite do rodapé de emissão. Fecha ao escolher produto, sair da aba, clicar fora ou deslocar o campo para fora da área visível; reposiciona em scroll/resize; fallback mantém a lista antiga onde não houver Popover API.
- `public/css/desktop-polish.css`: regra restrita à lista de resultados de produto com `[popover]`, camada superior e posicionamento controlado por JS, sem afetar dropdowns globais, outras abas nem a rolagem da janela.
- `tests/browser/nfce-product-picker.html`: reproduz uma área rolável menor com `overflow` e rodapé fixo; exige que a lista esteja na top layer, extrapole a borda de recorte, respeite o rodapé e feche na troca de aba, preservando os testes anteriores de clique, Enter, SKU, GTIN, preço e quantidade.
- Nenhuma alteração fiscal, de banco, estoque, pagamentos ou regras de emissão.

**Verificações:** os testes reais do Chromium no workflow `ACBr integration checks` passaram no commit de implementação `6cbb11d` (conferir a suíte completa e a execução do HEAD no GitHub). O ambiente local do proprietário ainda deve confirmar visualmente a correção com Ctrl+F5 após `git pull origin feature/acbr-nfe-bootstrap`.

## Continuidade — 19/10/2026 — prioridade de SKU/código de barras na pesquisa NFC-e

**Motivo:** a inclusão repetida de produtos já foi corrigida, mas o filtro antigo devolvia os 14 primeiros por ordem do catálogo, sem distinguir um SKU/código de barras exato de um prefixo semelhante. Enter poderia inserir um produto diferente quando o catálogo tem códigos parecidos.

**Mudanças publicadas na branch `feature/acbr-nfe-bootstrap`:**
- `public/js/nfce-editor.js`: pesquisa normalizada (inclusive acentos), índice de nomes/SKU/GTIN criado na abertura do editor e ranking determinístico (SKU/GTIN exato, nome exato, prefixo de código, prefixo de nome, ocorrência parcial em código e em nome). Os 14 primeiros são mantidos durante a iteração sem ordenar todo o catálogo a cada tecla. A inclusão por clique/Enter ainda soma +1 por ID, preserva preço/desconto, atualiza total e devolve foco ao campo.
- `tests/browser/nfce-product-picker.html`: adiciona SKU prefixo concorrente antes do SKU exato, testa seleção pelo Enter, busca de CAFÉ digitado como "cafe" e releitura exata de código de barras sem nova linha.
- Não houve alteração do banco, parâmetros fiscais, emissão, estoque nem serviços do backend.

**Conferência:** validar a execução do workflow `ACBr integration checks` no HEAD após estes commits. QA no Laragon: digitar um SKU exato que compartilhe prefixo com outro código; Enter deve selecionar o exato; testar busca sem acento e repetição de código de barras com quantidade/total corretos. A CI não substitui o teste com leitores físicos de códigos de barras.

## Entrega mais recente — 19/10/2026 — busca e inclusão repetida de produtos na NFC-e

**Relato do usuário:** na aba Produtos de Nova NFC-e, após escolher uma mercadoria no dropdown, a busca repetida do mesmo produto não tinha um fluxo confiável; botão "Adicionar produto" separado acima da busca e colunas numéricas desalinhadas em relação ao desktop do ERP. Print de `/fiscal/nfce/create` com duas mercadorias.

**Alterações na branch `feature/acbr-nfe-bootstrap`:**
- `public/js/nfce-editor.js`: `addProduct(id)` passa a localizar a linha existente pelo **ID do produto** e **somar 1 na quantidade**, em vez de adicionar uma segunda linha; mantém preço/desconto editados, recalcula total, limpa pesquisa, volta foco para o campo sem reabrir a lista. Produtos existentes **continuam aparecendo nos resultados**, marcados "Na nota: Q · +1"; botão Localizar abre resultados sem fechamento imediato pelo listener global de cliques. Enter no resultado, Enter no campo e clique fazem mesma operação. Parser monetário entende formatação pt-BR.
- `resources/views/fiscal/nfce/create.blade.php`: campo Buscar produto e botão `nfceAddProduct` (agora **Localizar**, preservando ID) ocupam **uma única barra**; dropdown ancorado no campo, com `aria-expanded`, `aria-controls` e seleção acessível. Estrutura do formulário/nomes fiscais preservada.
- `public/css/desktop-polish.css`: barra de busca alinhada, resultados sobrepostos com `position:absolute` para não deslocar a tabela, colunas densas e alinhadas. Remove ícone de lista do campo Quantidade, usa prefixos "R$" nos campos Preço e Desconto. Regras restritas ao painel `#nfcePanel-products`, para não afetar consultas/documentos fiscais, Pagamento nem outros módulos.
- `tests/browser/nfce-product-picker.html`: smoke teste no Chromium com **JS global real `erp.js` + `nfce-editor.js`**; busca → clique → busca repetida → incremento sem duplicar linha → preço editado preservado → Localizar não fecha lista → Enter adiciona outro produto → total correto → layout alinhado. `.github/workflows/acbr-integration-checks.yml` executa este teste em cada push na branch.

**Ao testar no Laragon:** `git switch feature/acbr-nfe-bootstrap`, `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear` e Ctrl+F5. Em Nova NFC-e, adicionar produto, repetir 2×, verificar uma única linha quantidade 3, testar alteração do preço e uso de Localizar/Enter. Não há migração, dados fiscais ou estoque alterados pelos commits.

## Entrega anterior — 19/10/2026 — fiscal sem títulos repetidos e textos didáticos

Usuário enviou imagens de consulta NFC-e e Nova NFC-e no modal e pediu eliminar redundância: aba **Dados gerais** seguida de título **Dados gerais** e parágrafos permanentes com instruções longas no fiscal e em outros módulos, como “Série, ambiente e modalidade...”.

**Alterações aplicadas à branch `feature/acbr-nfe-bootstrap`:**
- `resources/views/fiscal/nfce/create.blade.php`: retirados campos visuais redundantes de modelo/série/CRT/modalidade do painel Dados gerais; série, ambiente e CRT permanecem no cabeçalho, sem perder dados fiscais nem envio para o servidor. A versão atual da view já não contém o aviso “Série, ambiente e modalidade...” e não deve reintroduzi-lo.
- `public/js/nfce-editor.js`: ao iniciar qualquer editor (novo, consulta, correção), remove somente títulos **decorativos**, cujo texto seja exatamente igual ao nome da aba e que não contenham controles ou links. Preserva ações, campos, avisos de rejeição, erros e fluxo de edição.
- `resources/views/settings/index.blade.php`: removidas mensagens permanentes genéricas sobre homologação/produção, tributos, documentos de transporte e habilitação de eventos, preservando campos, nomes e validação fiscal; sem ocultar mensagens de erro/advertência de retorno.
- `tests/Feature/NFCeEditorClarityTest.php`: verifica ausência da nota explicativa, campos repetidos e preservação de abas e ações.
- Testar manualmente em `/fiscal/nfce/create`, `/fiscal/{id}`, edição de rejeição, outras telas de Configurações; `php artisan optimize:clear` + Ctrl+F5 se houver HTML cacheado.
- **Não esconder alerta/erro fiscal real** por CSS global. Padrão futuro: ajuda via tooltip contextual quando necessária, não banners de texto permanente no formulário. Sem alterações a ACBr, schema, CSC, números e banco.

## Entrega anterior — 19/10/2026 — limpeza editorial dos modais e mensagens redundantes

**Pedido:** usuário mostrou três capturas do Lumeron Fiscal (NFC-e consulta/nova emissão) e apontou a duplicação "Dados gerais" na aba e no painel, além de muitos parágrafos didáticos permanentes espalhados pelo ERP. Regra de interface: **uma aba já nomeia sua seção; não repetir seu título dentro dela. Campos e ações primeiro. Textos longos só sob demanda. Avisos reais de erro/segurança/SEFAZ não devem ser escondidos.**

**Arquivos alterados na branch `feature/acbr-nfe-bootstrap`:**
- `resources/views/fiscal/nfce/create.blade.php`: retira segunda barra de títulos em Dados gerais, Consumidor, Produtos, Pagamento e Resumo, mantém botões Adicionar produto / Adicionar pagamento alinhados como ações; remove grande banner didático sobre modelo 65 e os parágrafos de orientações fiscais gerais de cada aba. Mantém status real, emitente, ambiente, modelo, número, campos, erros, cinco abas, emissão, confirmação e avisos de rejeição.
- `resources/views/fiscal/nfce/_readonly_panels.blade.php`: elimina títulos duplicados da consulta histórica, preservando dados/status.
- `resources/views/fiscal/nfce/correct-rejection.blade.php`: elimina títulos duplicados das abas durante correção de rejeição e o tutorial do total do pagamento; mantém justificativas fiscais, campos corrigíveis e contagem de produtos.
- `resources/views/fiscal/nfe/editor.blade.php`: remove barras redundantes (Dados gerais, Pagamento, ICMS, PIS/COFINS e IBS/CBS dentro das abas), preserva botão do recolhimento/expansão e seções secundárias realmente distintas.
- `resources/views/settings/index.blade.php`: retira 22 legendas genéricas e conselhos permanentes, remove parágrafo fixo dos CSCs e textos redundantes dos switches; preserva opções/credenciais, descrições ligadas a riscos de produção e os campos críticos.
- `resources/views/fiscal/tax-groups/form.blade.php`: avisos tributários extensos saem da leitura principal e ficam em `details` opcionais (ICMS-ST/FCP e IBS/CBS). Nada alterado em submissão de campos/regras.
- Vendas, Financeiro, Dashboard, Estoque e PDV: remoção seletiva de textos que só repetiam os rótulos, preservando ajudas contextuais realmente operacionais, como confirmação de cancelamento, contingência e ajuste do estoque.
- `public/css/desktop-polish.css`: remove estilo do banner NFC-e, cria `nfe-section-title--actions` compacto e detalhes fiscais discretos `erp-help-details`. Não altera desenho da Ribbon, paleta ou regras de seleção/modal.
- `public/js/nfce-editor.js`: retira textos didáticos permanentes para TEF/PIX na tabela de pagamentos eletrônicos; mantém os campos, a seleção de integração e todas as validações fiscais.
- `AGENTS.md`: fixa a regra de UX editorial para todas as alterações futuras e atualiza comando de testes PHPUnit válido no Laravel do projeto.
- `tests/Feature/NFCeRejectionCorrectionTest.php`: atualiza a verificação da seção Produtos para a contagem real, já que o título duplicado não existe mais.
- `tests/Feature/NFCeManualEditorTest.php`: mantém verificações fiscais, passa a exigir interface sem banner e sem segunda seção "Dados gerais". Novo `tests/Feature/FiscalConciseLayoutTest.php` cobre os três modos da NFC-e, NF-e e Configurações. Executar PHPUnit e verificar CI.

**Criterio visual:** telas densas e limpas no padrão ERP desktop clássico, sem grandes callouts instrucionais ou repetição de título em abas. Não remover rótulos, indicadores de ambiente, botões, erros de validação nem alertas fiscais acionáveis. Se editor sofrer regressão, restaurar funcionalidade, não os textos redundantes.

## Entrega anterior — 19/10/2026 — hardening de produção NFC-e para Simples Nacional (BA)

**Pedido:** concluir o fechamento operacional do Lumeron para empresas do Simples Nacional, que já haviam emitido NFC-e em homologação, sem alterar dados reais ou reinventar a integração ACBr. O usuário quer código em produção, não um plano.

**Implementado no branch `feature/acbr-nfe-bootstrap`:**
- `app/Services/Fiscal/NFCeCatalogAuditService.php` (novo): audita todas as mercadorias ativas usando os mesmos serviços `NFCeFiscalProfileService`, `NFCeTaxCalculationService`, `NFCeRtcTaxService` e `NFCeItemValueGuard` do emissor, gerando contagem de itens válidos/inválidos com exemplos; **não cria venda, movimenta estoque, reserva sequência ou transmite NFC-e**.
- `app/Console/Commands/ACBrNFeReadiness.php`: comando `php artisan acbr:nfce-check --environment=production --catalog` valida CRT de Simples (1, 2 e 4), dados do emitente BA, parâmetros por ambiente, CSC sem expor segredo, certificado A1 PKCS#12 e validade X509, DLL/FFI, schemas e habilitação fiscal. Usa os mesmos caminhos padrão de schemas do ACBr local. `--limit=20` controla erros do catálogo.
- `app/Services/Fiscal/NFCeItemValueGuard.php` (novo): verifica precisão XML de quantidade (3 casas), preço (4), desconto e total (2), concilia linha comercial antes do INI, e GTIN GS1 (8/12/13/14 dígitos + módulo 10). Conectado ao preflight, INI builder, auditoria do catálogo e checagem anterior à emissão manual. Nenhum dado silenciosamente arredondado ou sanitizado para ocultar erro.
- `app/Services/Fiscal/NFCeTaxCalculationService.php`: CSOSN 500 e CST 60 agora suportam **FCP-ST anteriormente retido** conforme evidência real da entrada (`fcp_st_retained_base/rate/value`), produzindo `vBCFCPSTRet/pFCPSTRet/vFCPSTRet`, com parametrização por item ou unidade e bloqueio de campos incompletos ou no CSOSN errado. Não presume FCP próprio nem o retido. Mensagem legada NEXTOR substituída por Lumeron.
- `app/Services/Fiscal/NFCeTaxGroupTranslator.php`, `FiscalTaxGroupController.php`, `resources/views/fiscal/tax-groups/form.blade.php`: propagação/validação/editabilidade desses três campos, sem alterar o modelo de dados (usa JSON `tax_config`).
- `app/Services/Fiscal/NFCeTransmissionService.php`: geração assíncrona de DANFE após autorização via fila `database/fiscal`, nunca `sync` dentro de requisição FFI/ACBr. `app/Console/Commands/ACBrNFCeStatus.php`: inclui CSC de produção nos segredos protegidos ao reportar falha.
- Testes `tests/Feature/NFCeSimplesProductionTest.php` (novo): snapshots de INI ACBr em ambiente de produção com CSOSN 102, 400, 500, CST PIS/COFINS 04/06, ST histórico, FCP-ST anterior e ausência de IBS/CBS fictícios em CRT 1, ano 2026.
- Testes `tests/Feature/NFCeProductionAuditTest.php` (novo): relatório de produtos ativos, zero criação de vendas/notas/movimentos, relatório não divulga CSC. `tests/Unit/NFCeItemValueGuardTest.php` (novo): GS1, linha monetária e precisão. `NFCeTaxCalculationServiceTest.php` ampliado com FCP-ST.
- Documento `docs/fiscal/nfce-simples-producao.md`: aceitação de instalação individual, comandos, comportamento operacional, fontes (Portal NF-e e ACBr).

**Testes:** workflow GitHub Actions `ACBr integration checks` foi observado verde com os novos guardas e testes em commits intermediários. Confirmar **run verde no HEAD** antes de declarar passagem da branch. A CI não tem acesso ao Laragon/ACBr Windows/certificado real; homologações anteriores foram relatadas pelo usuário.

**Instrução operacional sem alterar produção:**
```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
vendor/bin/phpunit --filter=NFCeSimplesProductionTest
vendor/bin/phpunit --filter=NFCeProductionAuditTest
vendor/bin/phpunit --filter=NFCeItemValueGuardTest
php artisan acbr:nfce-check --environment=production --catalog
php artisan acbr:nfe-doctor
```
Após isso, `php artisan acbr:nfce-status` (usa ambiente NFC-e atualmente escolhido) faz uma consulta sem transmitir. O fechamento de conformidade por instalação depende de verificar GTIN e regras do catálogo reais, XML assinado com schemas atuais e cStat 100 em teste de regressão dos cenários alterados. Não alterar CSC nem confirmar uma emissão de produção sem autorização expressa.

**Escopo deliberadamente não presumido:** monofasia ICMS sobre combustível (diversa de PIS/COFINS CST 04), CSOSN 201/202/203/900, FCP próprio conforme operação e casos estaduais especiais. Auditoria deve identificar produtos nessa situação e não aplicar regra genérica para forçar autorização.

## Entrega anterior — 19/10/2026 — transição de homologação já existente para produção

**Contexto confirmado pelo usuário:** Lumeron **já emitiu várias NFC-e em homologação** anteriormente. O trabalho atual NÃO é construir uma integração SEFAZ-BA do zero, mas padronizar parâmetros fiscais/tributários e preparar uma ativação controlada em produção. Evitar pedir nova homologação integral sem motivo: validar especificamente regressões de XML introduzidas pelos novos mapeamentos, e consultar serviço no ambiente real antes da primeira emissão.

**Correção importante no repositório `feature/acbr-nfe-bootstrap`:**
- `app/Services/Fiscal/NFCeEnvironmentParameters.php`: mantém os campos legados `series`, `next_number`, `csc_id`, `csc_token` como **homologação**, preservando o legado; adiciona `production_series`, `production_next_number`, `production_csc_id`, `production_csc_token` para produção. CSC por ambiente é obrigatório; nenhum fallback entre ambientes.
- `SettingsController`/tela Configurações → NFC-e: dois conjuntos explícitos, CSC de produção criptografado, ativação de produção exige seus dados e não aceita numeração igual/inferior ao máximo já reservado no mesmo ambiente+série.
- `FiscalPreparationService`: no ambiente produção reserva `production_next_number`; em homologação segue a sequência anterior (inclusive preparação de notas pendentes de parâmetros fiscais). Retira **ambos CSCs** do snapshot fiscal.
- `NFCePreflightService`: confere CSC, série e próximo número no ambiente efetivo do documento. `NFCeTransmissionService`, `NFCeOfflineService`, `NFCeInutilizationService`: usam ID/CSC do ambiente do evento e verificam lacunas na sequência correspondente.
- `tests/Feature/NFCeProductionParametersTest.php`: valida isolamento entre ambientes, criptografia do CSC, impedimento de liberação sem parâmetros, sequência separada, recusa de números usados e interface sem expor segredo.
- **Não foi alterado o banco de dados do usuário; não foi ativada produção; não foram importados ou solicitados CSC/credenciais ao usuário pelo chat.** A instalação Laragon irá ler os novos campos do seu próprio banco assim que forem preenchidos nas Configurações.

**Operação após atualizar:** Configurações → Notas fiscais → NFC-e: conferir os valores de homologação existentes e preencher série/numeração **reais de produção** consultadas na SEFAZ/arquivos anteriores, além de **ID CSC/CSC de produção**. Selecionar ambiente produção e marcar permissão de produção somente após conferência. Não presumir número 1 para emissão real se houver histórico anterior. Verificar certificado A1, fila `database/fiscal`, schemas atuais e `php artisan acbr:nfce-status` no novo ambiente antes de faturar. Evitar emitir notas desnecessárias em produção para “testar”.

**Evidência e limite:** o CI verifica regressões Laravel/INI, mas não possui acesso ao certificado ou à ACBr Windows da instalação. As emissões anteriores em homologação são fato relatado pelo usuário, não arquivos/protocolos vistos pelo assistente.

## Entrega anterior — 19/10/2026 — ampliação real do emissor NFC-e BA

Usuário exigiu continuar desenvolvimento até ser viável operar de verdade, sem encerrar com plano. Alterações nesta branch:

1. Corrigida a **base IBS/CBS** em `NFCeRtcTaxService` durante o período 2026–2032: deduz ICMS, PIS, COFINS **efetivamente calculados para o item** no próprio serviço legado, alinhado ao art. 12, §2º, V, LC 214/2025. Builder e Preflight usam o mesmo conjunto de impostos. Fora da transição não deduz esses tributos. Bloqueia base negativa.
2. Implementados **ICMS CST 51 (diferimento, fundamento legal, vICMSOp/pDif/vICMSDif/vICMS líquido)** e **CST 60 (ICMS-ST anterior, valores históricos com escopo unitário/linha, pST/vICMSSubstituto)** em `NFCeTaxCalculationService`, classificados por `NFCeFiscalProfileService` e `NFCeTaxGroupTranslator`.
3. `FiscalTaxGroupController` e formulário de grupos agora validam/cadastram ICMS diferido e dados de retenção anterior, sem inventar base nem imposto histórico. Emissões incorretas continuam recusadas; grupos padrão ST podem ser cadastrados antes de se conhecerem os valores de entrada.
4. RTC reduzido **CST 200 / cClassTrib 200003** (alimentos especificamente enquadrados no Anexo I): aceita somente redução integral de 100% de IBS UF, IBS municipal e CBS. Escreve `pRedAliq` e `pAliqEfet` no INI ACBr, mantém base e zera os tributos. Não classifica automaticamente qualquer alimento. Corrigido formulário que enviava `ibs_reduction_rate` (campo sem persistência): agora envia `ibs_uf_reduction_rate` e `ibs_municipal_reduction_rate`.
5. Novo `NFCeManualTaxPreviewService`: na emissão manual de nova venda (botão **Emitir**, não o mero Salvar), classifica e valida os produtos **antes** da operação com estoque/financeiro. Se NCM, grupo, CST/CSOSN ou RTC forem inválidos, retorna erro por item sem criar a venda. O snapshot fiscal ainda é recalculado após criação da venda.
6. Testes novos em `NFCeRtcTaxServiceTest`, `NFCeTaxCalculationServiceTest`, `NFCeRtcIniTest` e `NFCeManualEditorTest`. Integração CI de `95f0e6de` já ficou verde: **349 testes, 2.202 assertions**; novo teste de não baixa de estoque está sendo verificado em execução posterior.

**Fontes oficiais verificadas nesta rodada:** LC 214/2025 art 12 §2 V (Planalto); calculadora-base Receita `https://piloto-cbs.tributos.gov.br/servico/calculadora-consumo/calculadora/bases-de-calculo`; modelo ACBrLib INI com ICMS 51/60 e RTC `https://acbr.sourceforge.io/ACBrLib/ModeloNFeINIReformaTributaria.html`; tabela IT 2025.002 v1.70 (cClassTrib 200003); schemas oficiais PL 010f publicados no Portal NF-e. Detalhes e escopo em `docs/fiscal/nfce-formulario-conformidade-2026.md`.

**Conformidade em produção ainda exige testes EXTERNOS:** o GitHub CI não usa a ACBrLib Windows real nem possui certificado A1, CSC e credenciamento do emitente na SEFAZ-BA. Verificar `php artisan acbr:nfce-status` no Laragon com cert/schema configurados (somente consulta); emitir NFC-e teste em homologação e verificar **cStat 100, XML assinado, QR Code e DANFE**. Não afirmar autorização real sem essa evidência. ICMS monofásico combustível e várias classificações RTC/IS ainda precisam de módulos específicos; não substituir códigos por cálculos genéricos.

**Instalar/atualizar no Laragon:** `git switch feature/acbr-nfe-bootstrap`, `git pull origin feature/acbr-nfe-bootstrap`, `php artisan optimize:clear`; PHPUnit via `vendor/bin/phpunit` (Laravel 13 não oferece `artisan test` neste projeto). Alterações não requerem migração.

## Entrega anterior — 19/10/2026 — motor RTC da NFC-e, CI real e limitações

**Motivo da continuidade:** usuário pediu emissão de NFC-e COMPLETA SEFAZ. A entrega anterior adequava somente formulário, pagamentos e CPF/CNPJ, sem gerar grupos IBS/CBS no XML. Foi erro tratar essa intervenção como completa.

**Evolução publicada:** `feature/acbr-nfe-bootstrap`.
- `app/Services/Fiscal/NFCeRtcTaxService.php`: novo cálculo do grupo **CST 000 / cClassTrib 000001**, a partir de alíquotas explicitamente configuradas no grupo tributário (IBS-UF, IBS-município e CBS), base com desconto e arredondamento monetário por item. Não inventar alíquotas ou classificação.
- `app/Services/Fiscal/NFCeTaxGroupTranslator.php`: mantém CST, cClassTrib e três alíquotas no snapshot de produtos elegíveis; continua rejeitando benefícios/combustível/IS sem mapeamento.
- `app/Services/Fiscal/NFCeIniBuilder.php`: adiciona seções ACBr `[IBSCBSnnn]`, `[gIBSCBSnnn]`, `[gIBSUFnnn]`, `[gIBSMunnn]`, `[gCBSnnn]` e os totais `[IBSCBSTot]`, `[gIBS]`, `[gIBSUFTot]`, `[gIBSMunTot]`, `[gCBSTot]`. Não soma IBS/CBS ao vNF da transição 2026.
- `app/Services/Fiscal/NFCePreflightService.php`: exige perfil RTC para CRT 2/3 na emissão de 2026, valida todos os itens e impede tratamento parcial. Simples Nacional CRT 1/4 em 2026 não recebe alíquotas-teste inventadas; regime a partir de 2027 precisa de implementação própria.
- `resources/views/fiscal/tax-groups/form.blade.php`: esclarece quais regras são suportadas.
- Testes: `tests/Unit/NFCeRtcTaxServiceTest.php` (12), `tests/Feature/NFCeRtcIniTest.php` (2), `tests/Feature/NFCeIniBuilderTest.php` atualizado. Correções reais de sintaxe em `NFCeManualEditorTest.php` e de regressão em `NFCeTaxGroupTranslatorTest.php`.
- `.github/workflows/nfce-rtc-tests.yml`: rotina PHP 8.3, Composer, `php -l`, PHPUnit, ativada no GitHub Actions. **Regressão RTC ficou verde no run 39300149793 e novamente no run 39301286051 (com proteções de IS e redução).** O workflow `ACBr integration checks` ainda precisa de confirmação verde na última revisão.

**Fontes oficiais da análise:** Portal NF-e schemas 010f NT 2025.002 v1.50 (31/08/2026), modelo NFC-e INI RTC da ACBrLib, CGIBS/RFB esclarecimento 06/08/2026 (adiamento da rejeição não elimina a obrigação), Receita Federal orientações do Simples Nacional 2027. URL e checklist na matriz `docs/fiscal/nfce-formulario-conformidade-2026.md`.

**PENDENTE e não fingir implementado:** cClassTrib e CST de redução/diferimento, monofásicos/combustíveis, benefícios específicos de ICMS, IS, CNPJ alfanumérico e perfis do Simples em 2027; confirmar regras estaduais vigentes e versões ACBr/XSD/QR Code v3; executar teste **real** com certificado e credenciamento em SEFAZ-BA **homologação**, inspecionando XML assinado, rejeições e protocolo. Não habilitar produção sem isso.

**Testes locais:** no repositório com `composer install`, usar `vendor/bin/phpunit --filter=NFCeRtcTaxServiceTest`, `vendor/bin/phpunit --filter=NFCeRtcIniTest`, `vendor/bin/phpunit --filter=NFCeIniBuilderTest`, `vendor/bin/phpunit --filter=NFCeManualEditorTest`. **Não usar `php artisan test`: comando não existe neste Laravel 13 do projeto.**

## Entrega anterior — 19/10/2026 — formulário manual NFC-e x SEFAZ-BA

**Pedido:** adaptar a emissão de NFC-e manual às exigências fiscais vigentes, mantendo aparência do Lumeron.

**Fontes:** manuais/notas técnicas do Portal Nacional NF-e (NT 2025.001 QR Code v3, NT 2025.002 RTC), atualização Receita Federal de agosto/outubro de 2026, documentação ACBrLibNFe, diretrizes SEFAZ-BA. Matriz detalhada, links e limitações em `docs/fiscal/nfce-formulario-conformidade-2026.md`.

**Implementado na branch `feature/acbr-nfe-bootstrap`:**
- `resources/views/fiscal/nfce/create.blade.php`: orientações ambientais, fiscais e de pagamento, informações controladas de modelo 65, série, CRT e contingência, NCM e consumidor identificado. Mantém cinco abas e não expõe certificado/CSC secreto.
- `public/js/nfce-editor.js`: corrigido **ReferenceError de `readOnly` não definido** na tela de nova NFC-e. Campos eletrônicos condicionais para cartão/PIX (TEF/POS, bandeira, adquirente, autorização, CNPJ/UF da transação, beneficiário, terminal), preservados após `old()`; troca para dinheiro limpa metadados; segue usando `select` nativo e wrapper do ERP. Verificações de CPF/CNPJ, CNPJ de pagamento e total. NCM exibido por produto.
- `app/Http/Controllers/NFCeManualController.php`: campos recebidos e validados ANTES da criação da venda e baixa de estoque/financeiro; usa `App\\Support\\BrazilianTaxId` para dígitos verificadores, CNPJ de pagamento com 14 dígitos, código TEF/POS e campos coerentes com meio; armazena os dados no pagamento e snapshot usados pelo mesmo ACBrLib. Catálogo carrega NCM/CEST/origem/grupo fiscal para a UI. Emissão solicitada enfileira explicitamente `database/fiscal`, nunca `sync`.
- `app/Support/BrazilianTaxId.php` (novo): validação numérica CPF/CNPJ, inclusive dígitos verificadores e repetições; não remove letras antes de validar.
- `public/css/desktop-polish.css`: campos fiscais responsivos e discretos sem redesenhar o ERP.
- `tests/Feature/NFCeManualEditorTest.php`: casos de CPF inválido, cartão integrado ausente/incorreto, cartão válido até snapshot, PIX com dados reais, dinheiro sem dados de cartão e interface.
- `tests/Unit/BrazilianTaxIdTest.php` (novo): CPF/CNPJ válidos e inválidos.
- `docs/ai/ARCHITECTURE_AND_DECISIONS.md`: decisão D06-B; matriz de conformidade fiscal nova.

**Verificado:** arquivos/alterações remotas no GitHub; sintaxe JavaScript por compilação V8 (`new Function`) = PASS; folha CSS com pares de chaves equilibradas = PASS; inspeção estática dos campos `payments.*`, preservação no snapshot, `readonly` definido, encaminhamento para fila dedicada, 5 abas originais preservadas. **Ainda não foram executados PHPUnit/Laravel, nenhum navegador com dados do usuário ou ACBr/SEFAZ de verdade. Não afirmar conformidade total em produção.**

**Pendências fiscais relevantes:** implementação/validação dos grupos IBS/CBS/IS/cClassTrib segundo NT e regime de cada contribuinte, CNPJ alfanumérico na vigência efetiva, cenários especiais (combustível, ST, monofásico, etc.), QR Code v3/CSC conforme ACBr/UF, testes reais de XML/QR e retorno cStat em homologação BA. A flexibilização de rejeições IBS/CBS em 2026 não equivale a isenção de todas as obrigações; não forçar indiscriminadamente. O motor atual já rejeita perfis tributários não implementados no preflight.

**Executar no Laragon antes de continuar ou publicar:**

```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
php artisan test --filter=BrazilianTaxIdTest
php artisan test --filter=NFCeManualEditorTest
php artisan test --filter=NFCeFiscalLifecycleTest
```

Conferir no Chrome emissão manual de 5 abas, cadastro produto NCM/tributação, consumidor, cartões (integrado/não integrado), PIX, dinheiro, total, troca do pagamento, scroll do modal e impressão. Só testar SEFAZ com emitente e certificados de **homologação**, sem transmissão de notas reais sem autorização. Se qualquer teste falhar, corrigir antes de afirmar conclusão.

## Entrega anterior — 19/10/2026 — correção global de dropdowns em telas e modais

**Pedido:** resolver o menu do seletor CRT que ultrapassava o rodapé de Configurações e outros campos que eram cortados dentro dos modais do Lumeron.

**Causa encontrada no código:** o componente próprio `.ui-select` em `public/js/erp.js` criava a lista como filho absoluto do controle, ficando sujeito aos containers com `overflow`, empilhamento de modais/rodapés e rolagem. Para campo pesquisável, `trigger.select()` selecionava todo o texto automaticamente, provocando o destaque azul do print.

**Implementado em `feature/acbr-nfe-bootstrap`:**
- `public/js/erp.js`: usa `popover="manual"` com `showPopover()/hidePopover()` na **top layer** do navegador; mantém o menu filho do wrapper, `<select>` original, `change`, teclado, callbacks e valores. Calcula posição horizontal/vertical, aplica `drop-up` antes do rodapé fixo de Configurações/modais, ajusta em scroll/resize/visualViewport e fecha no evento de fechamento do diálogo. Segundo clique fecha o popover sem deixá-lo órfão. No campo pesquisável, não destaca todo o texto; digitação iniciada quando fechado começa nova pesquisa. Fallback preserva implementação anterior em browsers sem suporte.
- `public/css/desktop-polish.css`: estilo do menu flutuante restrito a `.ui-select .ui-select-menu[popover]`, sem alterar `overflow` de cards e modais indiscriminadamente; estilos de foco e seleção preservados.
- `tests/Feature/SelectOverlayTest.php` (novo): verifica CRT como `select` nativo, importação do JS/CSS, top layer, fechamento, rodapé/rolagem/zoom, regras CSS e regressões de sintaxe estrutural.
- `docs/ai/ARCHITECTURE_AND_DECISIONS.md`: decisão D02-C para outras IAs não voltarem a criar dropdowns diferentes.

**Verificações realizadas:** conferência remota dos arquivos da branch, análise estrutural de CSS balanceado, `new Function(js)` com sintaxe **PASS**, e 11 verificações estáticas das regras de popup, campos, posicionamento, fechamento e navegação **PASS**. Tentativa de navegador Chromium isolado no contêiner encerrou por timeout, sem evidência de resultado visual. **Não houve execução de Laravel/PHPUnit no Laragon nem captura da interface real com a correção**, portanto não dizer que está visualmente homologado.

**Validação recomendada no Windows/Laragon:**
```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
php artisan test --filter=SelectOverlayTest
php artisan test --filter=UiRefinementTest
```

Abrir Configurações → Minha empresa → CRT próximo à barra Salvar; inspecionar os campos de Produtos, Financeiro e Fiscal, e seletores em modais com bastante conteúdo, zoom 100/125%; validar abrir para cima, seleção por teclado, pesquisa, fechamento fora e Escape. `Ctrl+F5` para invalidar CSS/JS em cache (assets carregados com `filemtime`).

## Entrega anterior — 19/10/2026 — mensagens de validação totalmente em português

**Problema visual identificado no PDV:** notificação "Verifique os campos" exibindo `validation.gt.numeric` em vez de uma mensagem compreensível. O Laravel tinha locale/fallback `pt_BR` em `config/app.php` e `.env.example`, porém faltavam os arquivos `lang/pt_BR/...`. A mensagem era uma chave de tradução literal.

**Implementado em `feature/acbr-nfe-bootstrap`:**
- `lang/pt_BR/validation.php` (novo): regras gerais de validação Laravel traduzidas para pt-BR, incluindo `gt.numeric`, `gte.numeric`, `min/max`, `required`, `numeric`, `decimal`, `exists`, `regex` e regras para arrays. `attributes` fornece nomes visíveis de campos usados por PDV e outros módulos; mensagens específicas para quantidade/valor de pagamento zero.
- `lang/pt_BR/auth.php`, `passwords.php`, `pagination.php` (novos): traduzem mensagens padrões de acesso, recuperação de senha e paginação.
- `tests/Feature/PortugueseValidationTest.php` (novo): regressão que testa tradução efetiva do Laravel, erro de quantidade e pagamento zerados, erro de POST real da venda no PDV, nomes dos campos, regras decimais, autenticação e paginação.

**Não foi alterado:** `gt:0`, regras de negócio, controller do PDV, payload, formulário ou JS de notificação. A causa estava na ausência do catálogo de traduções, não no toast. Portanto, traduções passam a funcionar globalmente.

**Verificação até agora:** arquivos gravados e lidos diretamente do GitHub. Testes PHPUnit ainda precisam ser executados no Laravel local; não afirmar que passaram sem execução. Confirme `APP_LOCALE=pt_BR` e `APP_FALLBACK_LOCALE=pt_BR` no `.env` real (o `.env.example` já contém esses valores).

```powershell
git switch feature/acbr-nfe-bootstrap
git pull origin feature/acbr-nfe-bootstrap
php artisan optimize:clear
php artisan test --filter=PortugueseValidationTest
```

No PDV, digitar quantidade 0 ou pagamento 0 em uma tentativa de venda deve exibir "A quantidade do produto deve ser maior que zero." ou "O valor do pagamento deve ser maior que zero.", e **jamais** a chave `validation.gt.numeric`.

## Entrega anterior — 19/10/2026 — PDV, retorno da venda e NFC-e SEFAZ-BA

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

## Planos comerciais definem acesso por módulo (21/10/2026)

- A licença assinada aceita qualquer código de plano válido `[a-z][a-z0-9_-]{2,29}`; não limitar a `standard/advanced/full`. O array de módulos assinado pelo Control é a autoridade comercial. Nunca usar o antigo `companies.modules` para conceder direitos.
- `CommercialModuleAccess` vincula PDV/Vendas a `pdv`, Produtos/Estoque a `products`, Financeiro a `finance`, Serviços a `services` e Fiscal a `fiscal`. O middleware `RequireCentralLicense` impõe HTTP **403** em telas/ações não contratadas (JSON usa `LUMERON_MODULE_UNAVAILABLE`); ocultar os links no layout, incluindo atalhos do cabeçalho. Credenciais API (`api/lumeron` e `api/nextor`) também não burlam essas licenças.
- Exceções de regularização fiscal são taxativas, inclusive consulta de notas existentes, downloads legais e cancelamento/transmissão de contingência. **Nunca** liberar `fiscal/*` indiscriminadamente ou bloquear obrigação fiscal legal por não contratação. Uma opção de Regularizações fiscais fica no menu de usuário quando a aba Fiscal completa não foi contratada.
- Instalações sem vínculo continuam com o comportamento antigo; suspensas, vencidas ou não verificadas após tolerância mantêm consulta e bloqueiam gravação, independentemente dos módulos, conforme política anterior. Permissões próprias dos usuários nunca são ampliadas por um plano.
- Antes da atualização do ERP, executar a migration `2026_10_22_000001_preserve_legacy_entitlements_as_plans.php` no **Control** para impedir a perda de autorizações individuais antigas. Não tocar os documentos XML, certificados, CNPJ, estoque, bancos, filas, `main` ou `v1.0.0`.

O cadastro compartilhado **Clientes** não tem módulo comercial próprio no catálogo, portanto segue disponível conforme permissões do usuário mesmo sem o módulo PDV. Não inventar uma restrição de Clientes baseada em PDV; Vendas/PDV e seus fluxos seguem bloqueados quando não contratados.
