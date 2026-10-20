# AGENTS.md — Lumeron ERP (antigo Nextor)

Este é o contrato de trabalho para agentes de IA que leem o repositório. **Aplicar sempre, independentemente do chat ou IDE.**

## 1. Contexto e leitura obrigatória

- **Produto:** Lumeron ERP, sistema **web Laravel 13/PHP 8.3+**, MySQL/MariaDB, Blade, CSS e JavaScript.
- **Código ativo:** GitHub `robsonmktsouza-stack/nextor`, branch de desenvolvimento de referência `feature/acbr-nfe-bootstrap` em 19/10/2026. `main` está historicamente atrás; conferir o branch/commit real antes de qualquer alteração. Nunca fazer merge ou troca de branch automaticamente.
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
- Estratégia de destino: `main` para linha estável aprovada; `develop` para integração de novas funções; `feature/*` para implementação; `hotfix/*` para corrigir uma versão publicada. **Estado atual:** `main` historicamente desatualizada e `develop` ainda não criada. Não trocar nem mesclar branches por iniciativa própria.
- O primeiro release `v1.0.0` é **candidato futuro**, não tag já criada. Exigir `composer.lock`, testes por versão, staging compatível e estratégia de implantação/backup/rollback antes da publicação.
- Uma instalação, banco, A1/CSC, armazenamento e worker por CNPJ. Scripts `deploy/instances/` permitem simulação e atualização individual, mas não significam produção provisionada; `--apply` **nunca** sem autorização expressa.
- `php artisan serve` só para desenvolvimento. VPS Linux exige ACBrLibNFe **Linux**, não DLL Windows/Laragon; a hospedagem de produção ainda depende de decisão.
- **Não exigir reiniciar do zero testes históricos de NFC-e sem motivo técnico concreto**, nem confundir a decisão de piloto com autorização para executar emissão real por conta própria.

**Leitura obrigatória:** [política completa de versões e publicação](docs/deployment/versioning-and-release-policy.md). Se o pedido envolver versão, produção, hotfix, migração, infraestrutura ou NFC-e, consulte-a **antes de propor comandos ou editar código**.

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
