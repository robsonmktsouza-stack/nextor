# Lumeron Mobile — diagnóstico de arquitetura e plano técnico

**Branch exclusiva:** `feature/lumeron-mobile` (originada de `develop`).  
**Escopo decidido:** interface web responsiva e Progressive Web App (PWA) do Lumeron ERP; manter o mesmo backend e a mesma base de regras de negócio por CNPJ.  
**Natureza deste registro:** inspeção estática do código do GitHub, sem execução local nem validação em smartphone. Este documento não certifica funcionamento.

## 1. Código efetivamente analisado

- `composer.json`: Laravel 13, PHP 8.3+; MySQL/MariaDB conforme README.
- `routes/web.php`: páginas e operações de dashboard, produtos, serviços, clientes, vendas/orçamentos, compras, estoque, PDV, financeiro e fiscal, sob `auth`, `control.license`, `permission:...` e, conforme a operação, `company.configured`.
- `bootstrap/app.php`: aliases de middlewares; não há roteador de API separado em `routes/api.php` nesta branch; endpoints `/api/lumeron` de consulta residem em `web.php`.
- `resources/views/layouts/app.blade.php`: navegação administrativa, busca, sidebar, meta viewport, tema e identidade Lumeron.
- `resources/views/layouts/pdv.blade.php`: PDV com layout independente, não herda sidebar.
- `resources/views/auth/login.blade.php`: login por subdomínio, usuário e senha; possui meta viewport.
- `public/css/erp.css`, `ui-refinement.css`, `desktop-polish.css`, `erp-typography.css`, `pdv.css`, `branding.css`: regras responsivas já existentes, incluindo breakpoints de 760px, 900px e 1180px, e soluções de overflow para formulários e tabelas.
- `public/js/erp.js`: sidebar para viewport até 760px já alterna classe `mobile-menu-open`, com overlay; estado de sidebar desktop em `localStorage`.
- `resources/views/products/index.blade.php`, `products/form.blade.php`, `customers/index.blade.php`, `sales/index.blade.php`, `sales/create.blade.php`, `stock/index.blade.php`, `finance/dashboard.blade.php`, `fiscal/index.blade.php`, `purchases/index.blade.php`, `pdv/index.blade.php`: formulários, tabelas, modais e módulos a auditar no telefone.
- `config/session.php`: sessão por banco, criptografada e HttpOnly, timeout ocioso e absoluto, expira no fechamento por padrão.
- `app/Http/Middleware/RequireCentralLicense.php`, `app/Models/User.php`: licença comercial e permissões aplicadas do lado do servidor.
- Documentação de governança: `AGENTS.md`, `docs/ai/{README,PROJECT_STATE,ARCHITECTURE_AND_DECISIONS,HANDOFF}.md` e `docs/deployment/versioning-and-release-policy.md`.

## 2. Diagnóstico

1. **Não construir outro ERP ou backend móvel.** Reutilizar as rotas Blade, controllers, serviços, autenticação, CSRF, autorizações, licenças e banco por instalação existentes.
2. **Responsividade parcial já existe.** Há menu móvel, grids colapsáveis, modais e tabelas com rolagem, mas a presença de CSS não comprova boa usabilidade no telefone, principalmente em tabelas extensas, tela fiscal e PDV.
3. **PWA ainda não identificada.** A inspeção dos caminhos convencionais `public/manifest.webmanifest`, `public/manifest.json` e `public/sw.js` não encontrou arquivos; os três layouts lidos não referenciam manifest nem registro de Service Worker.
4. **Frontend sem pipeline Node detectado.** `package.json` e `vite.config.{js,ts}` não existem nos caminhos usuais; assets CSS/JS estáticos são incluídos nos layouts com versão baseada em `filemtime`.
5. **PDV e fiscal requerem auditoria própria.** O PDV contém operações com caixa, teclado, pagamentos e NFC-e; a responsividade visual não autoriza alterar lógica fiscal, impressão ou concorrência.
6. **Bibliotecas e marca existentes são a fonte da verdade.** Não criar novo design system ou duplicar interfaces sem necessidade.

## 3. Arquitetura proposta

- Uma aplicação Laravel por CNPJ e origem HTTPS/subdomínio; cada PWA instalada pertence à origem da respectiva empresa.
- Manifesto no domínio da instância, com nome Lumeron, `display: standalone`, idioma pt-BR, `start_url` da própria instância, `scope: /`, cores oficiais e ícones oficiais derivados sem redesenhar o elefante.
- Service Worker **restritivo**: cache apenas de arquivos estáticos públicos e, se necessário, página genérica de indisponibilidade. **Nunca** armazenar HTML autenticado, páginas de dashboard, resultados de busca, respostas API, XML/PDF, dados de clientes, estoque, vendas, tokens, credenciais ou qualquer resposta privada. Sem fila offline de POST nem simulação de operação fiscal offline.
- Todas as transações continuam no backend. Conexão ausente = operações de escrita indisponíveis; não mostrar autorização SEFAZ nem conclusão de venda sem resposta real.
- Mesmos controles de sessão, CNPJ, permissão, licença, CSRF, throttle e validações, independentes da largura da tela.
- Estilos de adaptação mobile em folha adicional, carregada após o CSS existente; preservar visual desktop e relatórios/impressões.
- Ajustes específicos do mobile: navegação acessível, fechamento do menu por Escape e mudança de viewport, alvos táteis, barra segura em dispositivos com notch, formulários com teclado virtual, ações persistentes que não obstruam inputs, modais com rolagem interna e tabelas utilizáveis sem rolagem lateral da página.
- Respeitar `prefers-reduced-motion`, foco visível, labels, área de toque adequada e zoom de acessibilidade.

## 4. Matriz de validação funcional

| Área | Verificação obrigatória em mobile |
|---|---|
| Login | Subdomínio, usuário/senha, recuperação, erros, expiração/relogin e modo standalone |
| Dashboard | Indicadores, alertas, links, filtros e carregamento |
| Produtos/serviços | Lista, filtros, CRUD completo, foto, campos fiscais e validação |
| Clientes | Lista, busca, edição completa, cadastro rápido e validações |
| Vendas/orçamentos | Carrinho, totais, descontos, pagamento, aprovação, conversão e erros |
| Compras/estoque | Consulta, recebimento, movimentação, bloqueios e confirmação |
| Financeiro | Dashboard, CP/CR, recibos, transferências, baixa/estorno e conciliação |
| PDV | Produtos, carrinho, caixa, permissões/PIN, pagamentos, cupom e estado NFC-e real |
| Fiscal | Consulta, filtros, edição onde permitida, documentos/arquivos, estados reais, impressão externa |
| Configurações | Gestão de usuários/permissões, empresa e licença |
| PWA | Instalação Chrome Android/Safari iOS, ícones, atualização, online/offline, retorno ao navegador, logout, cache privado inexistente |

## 5. Critérios de aceitação

1. Nenhum módulo deixa de funcionar por estar em viewport de 360–430px; testar também 768px/1024px e desktop 1366px.
2. Sem perda de dados em rotação de tela, modais, zoom 200%, teclado virtual ou instalação.
3. Sem cache de dados privados; verificar `Cache Storage`, respostas do SW, sessão e bloqueio comercial.
4. Dados e operações sempre restritos à empresa autenticada; nenhuma ampliação de permissão pelo PWA.
5. Fluxos fiscais/PDV validados online nos testes existentes, sem regressão de estoque, venda e financeiro.
6. Ícones 192/512, manifesto e Service Worker testados nos navegadores-alvo; a instalação não pode ser declarada pronta sem verificação real.
7. Testes PHPUnit de regressão e novos testes para manifesto, headers, sessão e cache; inspeção real em Android/iOS.
8. Nenhuma alteração automática de `main`, `develop`, `control-center`, tags, VPS ou instalações de clientes.

## 6. Sequência técnica de implementação

1. PWA seguro: manifesto/ícones, registro e SW estritamente estático; testes de cache e instalação.
2. Navegação/layout base mobile nos layouts `app`, `pdv` e login; acessibilidade e estados de sessão.
3. Revisão operacional de dashboard, cadastros, vendas, compras, estoque e financeiro.
4. Tratamento específico do PDV, processos fiscais, modais/seletores e documentos/impressões.
5. Testes automáticos, testes de dispositivo real, regressão visual e atualização segura do SW.
6. Aprovação explícita de release antes de qualquer merge ou deploy.

**Inspeção realizada somente no código remoto.** Não foram executados PHPUnit, navegador, Lighthouse, instalação PWA nem teste em dispositivo. Não interpretar este documento como implementação pronta.
