# AGENTS.md — Lumeron ERP (antigo Nextor)

Este é o contrato de trabalho para agentes de IA que leem o repositório. **Aplicar sempre, independentemente do chat ou IDE.**

## 1. Contexto e leitura obrigatória

- **Produto:** Lumeron ERP, sistema **web Laravel 13/PHP 8.3+**, MySQL/MariaDB, Blade, CSS e JavaScript.
- **Branches ativas desde 20/10/2026:** `main` = base estável aprovada; `develop` = integração do desenvolvimento; `feature/acbr-nfe-bootstrap` = branch histórica que originou a primeira promoção. Repositório `robsonmktsouza-stack/nextor`. Conferir branch/commit real antes de cada tarefa; nunca fazer merge/troca de branch automaticamente.
- **Não confundir** com o repositório `lumeronerp` (projeto WPF desktop separado).
- Antes de editar qualquer coisa, leia nesta ordem:
  1. [docs/ai/README.md](docs/ai/README.md)
  2. [docs/ai/PROJECT_STATE.md](docs/ai/PROJECT_STATE.md)
  3. [docs/ai/ARCHITECTURE_AND_DECISIONS.md](docs/ai/ARCHITECTURE_AND_DECISIONS.md)
  4. [docs/deployment/versioning-and-release-policy.md](docs/deployment/versioning-and-release-policy.md) — **política obrigatória de desenvolvimento versus produção**.
  5. [docs/ai/HANDOFF.md](docs/ai/HANDOFF.md)
  6. [README.md](README.md) e documentação/testes do módulo solicitado.
- Faça `git status -sb` e `git log -5 --oneline`; preserve alterações não commitadas. Leia o código antes de assumir que uma feature está pronta.

## 2. Regras inegociáveis

1. Preservar comportamentos existentes de vendas, PDV, estoque, financeiro, usuários, permissões e fiscal. Não reimplementar funções existentes em paralelo.
2. **Uma instalação e banco por CNPJ**. Não converter o sistema em multitenancy compartilhado nem permitir emitente fiscal de outro CNPJ.
3. Identidade oficial Lumeron: usar imagens existentes em `public/images/lumeron/`, estilos e layout existentes. Não inventar outra marca. O PDV possui layout próprio sem sidebar administrativa; manter responsividade e consistência visual.
4. NFC-e atual: **ACBrLibNFe/FFI**, não motor próprio, NFePHP, nem provedores externos pagos. A proposta histórica de motor nativo foi abandonada. Não copiar classes de protótipos antigos como se fossem produção.
5. NFC-e modelo 65/BA é o principal foco fiscal atual. Não declarar outras UFs/modelos totalmente suportados só porque existem abas. Assinatura, XML, XSD, QR Code, numeração, CSC, protocolo e situação SEFAZ devem ser reais/verificáveis; **não simular autorização**.
6. Rejeição e correção fiscal não duplicam vendas, baixas de estoque, lançamentos financeiros, documentos autorizados ou numeração usada. Tratar estados, concorrência, locks, transações e idempotência com cuidado.
7. Nunca versionar ou revelar segredos: `.env`, senhas, APP_KEY, A1/PFX, CSC, certificados, XMLs privados, bancos e registros de clientes. Nunca logar dados secretos.
8. Não acessar ou alterar VPS/produção, executar emissão em ambiente real, publicar deploy, rodar migrations destrutivas ou migrar branch sem autorização expressa.
9. Preferir mudanças pequenas e testáveis com a stack atual. Não alterar desenho, biblioteca, arquitetura ou escopo para 'melhorar' sem pedido.
10. **UX editorial obrigatória:** a aba ativa já nomeia seu conteúdo. Nunca repetir o mesmo título em uma segunda faixa dentro do painel/modal. Remover subtítulos e parágrafos genéricos que apenas explicam o óbvio; priorizar campos compactos, tabelas e ações no estilo ERP Windows empresarial. Orientações tributárias longas ficam em ajuda sob demanda (`details`, tooltip ou documentação), enquanto erros, riscos operacionais reais, alertas de rejeição da SEFAZ e requisitos legais acionáveis continuam visíveis. Ao criar novo módulo, não adicionar banners introdutórios permanentes sem necessidade. Referência: `tests/Feature/FiscalConciseLayoutTest.php`.

## 2-A. Decisão permanente — desenvolvimento contínuo com produção isolada (20/10/2026)

**O proprietário decidiu lançar um piloto de NFC-e em produção e continuar desenvolvendo o Nextor/Lumeron em paralelo.** Isso é uma política de manutenção, **não prova que uma instância já está implantada**.

- **Não** permitir que commit/push/pull, branch `feature/*` ou `develop` atualize automaticamente clientes. Produção sempre usa **release/tag/commit explicitamente aprovado**; cada CNPJ pode permanecer em sua versão.
- Estratégia de destino: `main` para linha estável aprovada; `develop` para integração de novas funções; `feature/*` para implementação; `hotfix/*` para corrigir uma versão publicada. **Estado atual:** `main` foi promovida por fast-forward a partir da linha funcional em 20/10/2026, com `composer.lock` versionado e `develop` criada. A tag `v1.0.0` somente existe após sua confirmação na referência Git; verificar antes de afirmar que foi criada. Não realizar deploy por conta própria.
- O primeiro release `v1.0.0` é **candidato futuro**, não tag já criada. Exigir `composer.lock`, testes por versão, staging compatível e estratégia de implantação/backup/rollback antes da publicação.
- Uma instalação, banco, A1/CSC, armazenamento e worker por CNPJ. Scripts `deploy/instances/` permitem simulação e atualização individual, mas não significam produção provisionada; `--apply` **nunca** sem autorização expressa.
- `php artisan serve` só para desenvolvimento. VPS Linux exige ACBrLibNFe **Linux**, não DLL Windows/Laragon; a hospedagem de produção ainda depende de decisão.
- **Não exigir reiniciar do zero testes históricos de NFC-e sem motivo técnico concreto**, nem confundir a decisão de piloto com autorização para executar emissão real por conta própria.

**Leitura obrigatória:** [política completa de versões e publicação](docs/deployment/versioning-and-release-policy.md). Se o pedido envolver versão, produção, hotfix, migração, infraestrutura ou NFC-e, consulte-a **antes de propor comandos ou editar código**.

## 2-B. Lumeron Control e licenciamento central (20/10/2026)

A aplicação administrativa **independente** está na branch isolada `control-center`. **Jamais fazer merge dessa branch na `develop`/`main`**: são projetos distintos na raiz, enquanto não houver um repositório exclusivo.

A `develop` do ERP recebeu somente o **agente opt-in** em `app/Services/License/CentralLicenseService.php`, middleware `control.license`, comando `lumeron:license-sync`, migração própria e `config/central_license.php`; **está desativado por padrão**. A versão `v1.0.0` na `main` não foi alterada. Ler [docs/deployment/control-center-integration.md](docs/deployment/control-center-integration.md) antes de editar/ativar.

Habilitação gerenciada usa o vínculo privado HMAC criado pelo operador (`storage/app/private/lumeron-control.json`), com CNPJ verificado, backup/migração e sincronização assinada. Nunca pedir UUID/chave ou edição manual de `.env` ao cliente. Nunca copiar credenciais entre CNPJs, expor segredos ou bloquear operações legais de regularização de NFC-e. A criação de conta central não cria automaticamente VPS, usuário ou implantação.

## 2-C. Política atualizada: vinculação transparente pelo operador (21/10/2026)

**Decisão definitiva do proprietário:** não exigir do cliente nem do administrador do ERP confirmação, UUID/chave manual, edição de `.env` ou etapa extra para liberar, suspender ou renovar. No painel o operador só cadastra empresa, libera ou altera licença. O serviço de deploy cuida da comunicação autenticada.

- O painel `control-center` continua independente. Não mesclar seu código com a `develop`/`main` do ERP.
- O agente `develop` lê credenciais HMAC de `storage/app/private/lumeron-control.json` criado por um gerenciador **confiável** no servidor. `ManagedLicenseConfig` valida CNPJ contra `company_settings.document` e a identidade de instalação. O modo legado via variáveis de ambiente permanece somente por compatibilidade.
- Uma instalação **não vinculada** continua com licenciamento central desativado; após vínculo válido e primeira checagem assinada, ativar/suspender/renovar no Control produz efeito sem confirmar no ERP. Estados anteriores no banco não podem ser contornados removendo o arquivo.
- Para uma instalação legada/local, o operador roda, a partir do **painel**, `php artisan control:bind-local <diretório-do-ERP> --url=<URL-real-do-Control>` **uma vez**; o comando chama `php artisan lumeron:license-identity` no ERP para obter CNPJ e instala o arquivo sem imprimir segredos. Deploys futuros devem integrar esse passo automaticamente; não afirmar que infraestrutura remota já foi provisionada.
- **Não aceitar pareamento público só por CNPJ**, pois ele não comprova posse da instalação. Nem aplicar bloqueios fiscais destrutivos: preservar XML, NFC-e autorizadas e regularizações. `main`/`v1.0.0` continua intocada.

Ver [docs/deployment/control-center-integration.md](docs/deployment/control-center-integration.md).

## 2-D. Suspensão = modo de consulta (21/10/2026)

**Regra permanente aprovada:** quando a licença está suspensa, vencida ou sem verificação após tolerância, **não** redirecionar usuários para uma tela de bloqueio. Permitir login e navegação do ERP para consultas. Exibir aviso compacto e modal apenas para operações bloqueadas.

- Aplicar bloqueio de escrita **no backend** em `app/Http/Middleware/RequireCentralLicense.php` e `app/Support/CommercialLicensePolicy.php`. POST/PUT/PATCH/DELETE comerciais devem ser recusados; requisições AJAX recebem 423 e identificador `LUMERON_LICENSE_READ_ONLY`.
- Liberar somente exceções fiscais legais listadas explicitamente, não por prefixos genéricos `fiscal/*` ou `pdv/nfce/*`. Permissões dos usuários continuam obrigatórias. Preservar cancelamento/consulta/contingência/recuperação de XML autorizado.
- A interface utiliza `partials/commercial-readonly.blade.php`, `commercial-readonly.js` e `commercial-readonly.css` nos layouts `app` e `pdv`. O status deve ser **somente um chip discreto na barra superior**; NUNCA retornar banner grande/fixo no conteúdo. Ações comerciais são acinzentadas, mas clicáveis para abrir o modal. Impressão, exportação e consultas não ficam visualmente bloqueadas. Não duplicar a UI em cada módulo.
- Distinguir `suspended`, `expired` e `unverified`; não imputar atraso financeiro a falhas de comunicação.
- Reativação deve devolver capacidade de escrita após refresh do cache, sem novo login. Nunca apagar dados ao suspender.
- Middleware HTTP não impede jobs agendados; avaliar o impacto de workers nas operações financeiras/fiscais antes de produção.

## 3. Procedimento por tarefa

**Antes:** resumir o objetivo, localizar arquivos/rotas/testes existentes, mapear possíveis efeitos em outros módulos; verificar se solicitações recentes revogaram alguma decisão antiga.

**Durante:** codificar no fluxo existente, respeitar validações server-side, permissões, transações e dados fiscais. Criar/alterar testes próximos ao código. Evitar regressões de interface, banco e impressão.

**Depois:** executar testes pertinentes quando o ambiente permitir. Exemplos: `vendor/bin/phpunit --filter=NFCeManualEditorTest`, `vendor/bin/phpunit --filter=NFCeFiscalLifecycleTest`, `vendor/bin/phpunit --filter=InstanceIsolationTest`, `vendor/bin/phpunit --filter=FiscalConciseLayoutTest`. Informar falhas e o que ficou sem teste; **não alegar sucesso de testes não executados**. Atualizar `docs/ai/HANDOFF.md` com branch/commit, arquivos e resultados; atualizar `PROJECT_STATE.md` e decisões quando necessário.

## 4. Referências de código

- Rotas: `routes/web.php`; autenticação e permissões em controllers/middleware.
- Transações comerciais: `app/Services/SalesService.php`, estoque, finanças e `app/Services/FiscalPreparationService.php`.
- PDV: `app/Http/Controllers/PdvController.php` e `resources/views/layouts/pdv.blade.php`.
- Fiscal: `app/Http/Controllers/FiscalController.php`, `NFCeManualController.php`, `app/Services/Fiscal/`, `app/Jobs/`.
- ACBr: `app/Services/Fiscal/ACBr/ACBrNFeService.php`.
- Instância: `config/instance.php`, `App\\Support\\InstanceIdentity`, `docs/deployment/`.
- Visuais: `public/css/erp.css`, `ui-refinement.css`, `branding.css`, `public/images/lumeron/`.
- Testes: `tests/Feature/`, `tests/Unit/` e workflows `.github/workflows/`.

**Quando houver conflito entre este arquivo e o código, investigue e registre; não silencie erros.** Este documento não substitui a leitura das regras operacionais fiscais atuais.

## Planos comerciais definem acesso por módulo (21/10/2026)

- A licença assinada aceita qualquer código de plano válido `[a-z][a-z0-9_-]{2,29}`; não limitar a `standard/advanced/full`. O array de módulos assinado pelo Control é a autoridade comercial. Nunca usar o antigo `companies.modules` para conceder direitos.
- `CommercialModuleAccess` vincula PDV/Vendas a `pdv`, Produtos/Estoque a `products`, Financeiro a `finance`, Serviços a `services` e Fiscal a `fiscal`. O middleware `RequireCentralLicense` impõe HTTP **403** em telas/ações não contratadas (JSON usa `LUMERON_MODULE_UNAVAILABLE`); ocultar os links no layout, incluindo atalhos do cabeçalho. Credenciais API (`api/lumeron` e `api/nextor`) também não burlam essas licenças.
- Exceções de regularização fiscal são taxativas, inclusive consulta de notas existentes, downloads legais e cancelamento/transmissão de contingência. **Nunca** liberar `fiscal/*` indiscriminadamente ou bloquear obrigação fiscal legal por não contratação. Uma opção de Regularizações fiscais fica no menu de usuário quando a aba Fiscal completa não foi contratada.
- Instalações sem vínculo continuam com o comportamento antigo; suspensas, vencidas ou não verificadas após tolerância mantêm consulta e bloqueiam gravação, independentemente dos módulos, conforme política anterior. Permissões próprias dos usuários nunca são ampliadas por um plano.
- Antes da atualização do ERP, executar a migration `2026_10_22_000001_preserve_legacy_entitlements_as_plans.php` no **Control** para impedir a perda de autorizações individuais antigas. Não tocar os documentos XML, certificados, CNPJ, estoque, bancos, filas, `main` ou `v1.0.0`.

O cadastro compartilhado **Clientes** não tem módulo comercial próprio no catálogo, portanto segue disponível conforme permissões do usuário mesmo sem o módulo PDV. Não inventar uma restrição de Clientes baseada em PDV; Vendas/PDV e seus fluxos seguem bloqueados quando não contratados.


## 2-E. Economia de armazenamento da hospedagem (21/10/2026)

A pedido expresso do proprietário, os releases aprovados do **ERP** podem
compartilhar conteúdo executável por **hardlinks imutáveis root-owned**
(do mesmo filesystem), preservando **runtime e bootstrap/cache próprios** por
CNPJ. APP_KEY, banco/usuário MySQL, sessão, cache, certificados, XMLs e upload
permanecem privados. PHP-FPM isolado, ondemand; workers fiscais em lote ou
rápidos conforme perfil, habilitados só após validação. Nenhuma publicação
ocorre com push em develop; não alterar a branch control-center. Código novo
de deploy em deploy/instances/ e documentação técnica detalhada em
docs/deployment/shared-immutable-releases.md. Nunca alegar que estes scripts
foram aplicados à VPS sem execução/medição explícitas.
