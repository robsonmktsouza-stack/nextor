# Instruções obrigatórias — Lumeron Control

Esta branch `control-center` contém **um projeto Laravel independente**, não o ERP. Nunca abrir PR para incorporar a árvore na `main` ou `develop` do ERP. Preservar seu banco, login e segredos separados das instalações comerciais.

Produto aprovado: painel enxuto do proprietário, com Início, Clientes, Licenças e Histórico. Mesmo padrão visual Lumeron (Roboto, azul-petróleo, tabelas densas, sem gráficos ou controles supérfluos). Fluxo de cadastro por etapas, listagem e detalhes com notas/auditoria, inspirado funcionalmente nas capturas do painel eGestor, sem replicar propriedade intelectual.

Cada cliente: um CNPJ, instalação, segredo HMAC próprio, plano e validade. Os módulos vêm exclusivamente do catálogo de planos. Nunca armazenar senha do administrador do cliente neste banco. Geração de licenças não executa scripts na VPS. Bloqueios são estados de licença; exigem que o cliente ERP consulte e respeite o estado antes de terem efeito remoto. Não alterar PDV/NFC-e do ERP automaticamente.

Rotas públicas: apenas login e API assinada. Sem auto-registro. Admin criado por CLI com senha forte. `APP_KEY` único; segredos com cast encrypted; CSRF, throttle, validação e trilha de alterações. A API usa HMAC e nonce anti-replay; não revelar segredo em payload ou log.

Alterações em autenticação/licença exigem testes e revisão de segurança. Nunca declarar testes executados sem evidência. Manter `README.md` atualizado e não executar deploy/instalação real sem autorização.

## Política do proprietário — fluxo sem chaves nem confirmação do cliente (21/10/2026)

- **Não** pedir UUID, chave de licença, senha técnica, edição de `.env` nem confirmação por um usuário do ERP para criar, liberar, suspender ou renovar a conta.
- O painel pode cadastrar e ativar a licença comercial imediatamente. **Não** afirmar que isso bloqueia uma instalação ainda não conectada; mostrar claramente quando ela não comunicou com o Control.
- O segredo individual continua existindo **somente no backend** e nunca aparece na interface.
- Para instalação já existente, o operador de infraestrutura usa `php artisan control:bind-local <caminho-do-ERP> --url=<painel>`, que obtém o CNPJ do ERP, confere a conta e instala o vínculo no storage privado, sem copiar chave. Esse procedimento inicial **não exige ação do cliente** e não se repete nas renovações.
- Deploys futuros devem invocar o vínculo automaticamente pelo sistema de provisionamento; isso **ainda não significa** que o deploy remoto está configurado. A branch `develop` contém o agente que lê as credenciais privadas.
- Nunca implementar vínculo confiando só num CNPJ fornecido via web; exigir controle autorizado da instalação pelo servidor.

## Catálogo de planos e regras agendadas (21/10/2026)

- **Nunca** voltar a manter `standard/advanced/full` ou nomes/preços de planos fixos no PHP em tempo de execução. A migration inicial cria valores legados uma única vez, preservando empresas. Gerenciar pelo banco em `plans` com CRUD seguro na aba Planos.
- **Nunca** mudar o código de um plano usado por empresas. Os módulos são definidos apenas em `plans.modules`: editar o plano atualiza o acesso de todos os seus clientes na próxima verificação assinada, sem alterar a validade da licença. Arquivar remove novas opções, não cancela contratos. A API HMAC transmite o código do plano e seus módulos, não o JSON antigo de `companies.modules`.
- As regras da seção Automações pertencem somente ao **Control** (`automation_rules`), começam **desligadas**, são executadas por `control:automations-run` (agenda 08h) e geram apenas avisos de histórico interno via `automation_deliveries` com deduplicação transacional.
- Não enviar e-mails, WhatsApp, não gerar cobranças, suspender, renovar ou reativar contratos a partir dessas rotinas sem decisão expressa e testes dedicados. Vencimento continua derivado diretamente de `expires_at`; o alerta não altera o status.
- `php artisan schedule:work` serve para Laragon em janela separada; servidor Linux precisa de cron externo a cada minuto. Sem scheduler ativo, não declarar que monitoramento periódico funciona.
- Cobrir edições/arquivamento de planos, validade das licenças existentes, autenticação do proprietário, deduplicação e execução com as regras desligadas. Preservar CNPJ, secrets e isolamento do ERP.

## Padrão visual — listagem de Licenças e switches (21/10/2026)

- A listagem de `Licenças` reproduz o **padrão visual denso de Produtos do ERP**, com barra compacta de ferramentas, filtro colapsável, botões de imprimir/exportar/atualizar, busca, badges discretas, ações por ícones e rodapé de 20/50/100 linhas. Não criar tabelas grandes com filtros enormes quando a referência for a grade de Produtos.
- **Não usar a aparência de caixa de marcação para ativar/desativar opções**: apresentar switches (role=switch, input acessível via teclado), inclusive em status de licenças, habilitação de automações, disponibilidade de planos e módulos. A seleção em massa por checkbox **não foi solicitada na listagem de Licenças**.
- O switch comercial exige confirmação no diálogo padrão antes do POST CSRF para `clients.status`; não confiar no DOM para suspender/reativar. Preservar histórico, CNPJ, HMAC e acesso fiscal. Não atrelar mudança de plano ao toggle.
- A exportação CSV do navegador inclui **apenas a página visível**, jamais afirmar exportação de toda a base. Escapar células para que não sejam interpretadas como fórmulas por programas de planilhas. Impressão oculta o cabeçalho e os controles.
- Testes da listagem verificam controle por switch, filtragem/paginação e rotas autenticadas; não alterar o ERP/Nextor para esse refinamento da central.


## Padrão único obrigatório de TODAS as listagens (21/10/2026)

- **Clientes, Licenças, Planos, Histórico, Regras e Avisos de Automações e tabela-resumo do Dashboard** reutilizam `resources/views/partials/listing/toolbar.blade.php` e, quando paginadas, `resources/views/partials/listing/footer.blade.php`. Grade sem variações: `panel license-grid` + `data-admin-list`, `license-data-table` + `data-grid-table`, classes comuns de células, status, ações por ícone.
- Sempre que uma tela nova possuir tabela de listagem, **usar os parciais compartilhados** e a mesma densidade, filtro recolhível, ícones, impressão, exportação CSV e paginação (20/50/100). Não criar CSS de tabela por módulo ou voltar a cabeçalhos/formulários longos acima das listagens.
- Cada botão de imprimir/exportar atua na tabela **mais próxima**; com duas tabelas na página, nunca exportar/imprimir a errada. CSV é da página atual e exclui formulários, switches e coluna Ações; prevenção de fórmula em CSV obrigatória.
- Tabelas pequenas de resumo no dashboard usam mesma aparência e toolbar, mas não requerem paginação artificial.
- Switches acessíveis são usados para flags ligar/desligar; nada de caixas de seleção visuais para esse propósito. Não adicionar seleção em massa a Licenças sem solicitação explícita.
- Paginação e filtros devem ser processados no servidor e preservar parâmetros da URL. Para Automações usar paginadores independentes `rules_page` e `alerts_page`.


## Comunicação da interface para o operador (21/10/2026)

- **Não exibir mensagens técnicas ou instrutivas longas** nas páginas do Lumeron Control. Foram removidos avisos sobre Laravel, agendadores, deploy, sincronização interna, canais de comunicação não implementados e explicações negativas do tipo "não gera cobranças".
- Telas devem apresentar somente rótulos claros, dados, controles e, quando necessário, mensagens curtas de confirmação, erro ou restrição com ação do usuário. Exemplos: "Conta cadastrada.", "Plano atualizado.", "Automação atualizada.", "Verificação concluída.".
- Não colocar parágrafos didáticos no rodapé de listagens, abaixo de formulários ou entre componentes; evitar subtítulos redundantes e códigos internos de automação na interface. As regras de segurança e detalhes de infraestrutura **permanecem no backend e na documentação técnica**.
- O banco preserva auditoria. A migration `2026_10_21_000002_simplify_automation_descriptions.php` altera **somente descrições padrão idênticas às originais**, sem modificar flags, cadastros, prazos ou históricos.
- Validações realmente necessárias (CNPJ inválido, formato de URL ou plano indisponível) devem continuar visíveis em linguagem simples. Não esconder erros nem remover confirmações de operações sensíveis.


## Ícones do Lumeron Control: identidade visual Nextor (21/10/2026)

- Padrão **obrigatório**: usar o mesmo SVG vetorial já empregado em `nextor` (branch `develop`, `resources/views/partials/icon.blade.php`), copiado e adaptado no **Control** como `resources/views/partials/icon.blade.php`. Símbolos adicionais de Plano/Licença/Automações seguem geometria e espessura dos símbolos reais do ERP; evitar desenhos SVG primitivos e bibliotecas misturadas.
- **Todas as telas**, incluindo menu, barra, botões da tabela, ações, modais, filtros, confirmação e paginação futura, devem renderizar `@include('partials.icon',['name'=>'...', 'size'=>16])` (ajustar `size` ao contexto); não reintroduzir sprites `<svg><use href="#i-..."/></svg>` locais nem vários conjuntos incompatíveis.
- Ícones 16px nas ações por linha, 17–18px na toolbar e 19px no menu. Preferir ícones consistentes de `print/download/refresh/search/edit/plus` que já existem no Nextor. Usar `public/css/control.css` para tamanhos, estados hover/focus, cores e traço; não sacrificar acessibilidade `title` / `aria-label`.
- **Não alterar `develop` ou `main`** apenas para refinar a identidade da central, nem criar dependências de CDN ou fontes de terceiros para os ícones. Manter a implementação do Control standalone.


## Tooltips da central — padrão Nextor (21/10/2026)

- **Não usar atributos HTML `title` para tooltip nos controles do Lumeron Control**. Tooltips nativos do navegador são visualmente inadequados. Use `data-tooltip="Texto curto"` nos botões, ícones, switches e ações; preserve `aria-label` para botões só com ícone.
- O componente global `public/js/control-tooltip.js` usa o mesmo comportamento visual de `public/js/erp.js` do Nextor: balão escuro `.nextor-tooltip`, seta, posicionamento acima/abaixo conforme espaço, exibição no foco do teclado e no hover, ocultação ao clicar, rolar, redimensionar ou pressionar Escape.
- Os estilos ficam exclusivamente em `public/css/control-tooltip.css`. O layout `resources/views/layouts/main.blade.php` deve carregá-los globalmente com versionamento de cache. Não duplicar tooltip em cada módulo e não incorporar bibliotecas/CDNs.
- Conteúdo AJAX de modais também tem o `title` migrado automaticamente para `data-tooltip` como fallback. Preferir já entregar `data-tooltip` no HTML. Nunca inserir texto de tooltip via `innerHTML`; usar `textContent`.
- O tooltip não deve obstruir comandos nem quebrar formulários ou afetar impressão. Testar em listagens, ações da toolbar, switches e modais, inclusive com navegação por Tab.

## Planos comandam módulos do ERP (21/10/2026)

- **Fonte única de verdade**: `plans.modules` no Control. Não oferecer controles duplicados de módulos em `clients/create` ou `clients/partials/details`; essas telas somente exibem o resumo do plano selecionado. Rejeitar payloads `modules` enviados ao endpoint de licenças.
- `LicenseCheckController` obtém `modules` de `Company::entitledModules()`, consultando `planDefinition`. Plano ausente não pode conceder direitos.
- A migração `2026_10_22_000001_preserve_legacy_entitlements_as_plans.php` converte clientes com módulos distintos do plano anterior em **planos legados arquivados**, individualizados, sem perder autorizações históricas; nunca desligar módulos de clientes antes de executar essa migração em instalações já cadastradas. Não copiar segredos nem alterar CNPJ, situação ou validade.
- Novos planos com código válido devem ser aceitos pelo ERP. Alterar módulos de um plano em uso muda o acesso de seus usuários após atualização do cache; a interface do formulário avisa o número de clientes afetados.

## Automações configuráveis (21/10/2026)

- Novos eventos internos podem ser cadastrados com nome, descrição, prazo,
  switch e `kind` permitido (`expiry_soon`, `expired`, `offline`).
  O identificador `code` é gerado pelo servidor, nunca recebido do formulário.
- O tipo da automação é imutável após criação para preservar os eventos no
  histórico; só o comportamento parametrizado pode ser editado.
- Preservar avisos antigos e regras do seed; a migration acrescenta uma coluna
  de tipo, sem excluir ou recriar regras.
- Interface usa modal, e mantém GET de criação e edição como alternativa
  sem JS. Não criar ações arbitrárias, mensagens externas, cobranças ou bloqueios.


## Avisos por e-mail ao cliente (21/10/2026)

- O proprietário solicitou avisos externos de vencimento. As regras continuam
  gerando histórico interno; para notificações externas só existem gatilhos
  `expiry_soon` e `expired`, vinculados a `client_email_deliveries`.
- `notify_client_email` inicia desativado em toda regra (antiga ou nova).
  Além do switch da regra, exige `LUMERON_CLIENT_EMAIL_ENABLED=true`,
  transporte SMTP e remetente válido. Nunca enviar com driver log/array nem
  supor que atualizar a aplicação autoriza disparos em massa.
- Criação de aviso é transacional, restrita a ciclos novos e deduplicada por
  regra/empresa/vencimento. Antes de enviar, validar novamente validade,
  situação, prazo e opção ativa; renovados/suspensos vão para skipped.
  Até três tentativas, sem expor senhas nem erros detalhados na UI.
- O comando externo `control:client-email-send` corre a cada 10 minutos
  das 08h10 às 18h se o scheduler estiver realmente ativo. Rotas administrativas protegidas e
  throttle. E-mail enviado só comprova aceite pelo transporte.
- Não implementar WhatsApp fictício nem realizar campanhas: exige integração
  legítima com provedor e política de mensagens próprias.

## Faturas do Lumeron Control — base administrativa

- Menu Faturas: rascunho, emissão deliberada, pagamentos manuais e parciais,
  estorno com motivo e cancelamento auditável. `invoices` e
  `invoice_payments` são exclusivos do Control. Cópia de nome/CNPJ/plano e
  preço ao criar; nunca alterar o histórico por edição futura de planos.
- Rótulo "Fatura comercial — não é documento fiscal". Não afirmar integração
  bancária, link de pagamento, boleto gerado, NF-e/NFS-e nem aprovação de PSP.
- Não misturar licenças e cobrança: pagar fatura não renova licença por efeito
  colateral; emitir/cancelar fatura não bloqueia ERP. A alteração comercial
  de status/módulos/datas continua nas rotas próprias com HMAC.
- Valores calculados em centavos inteiros, nunca `float` para validação de
  saldo. Pagamentos são auditados; estorno não deleta linha; nenhuma rota
  pública de pagamentos. Impedir pagamentos acima do saldo e períodos
  de faturas sobrepostos para o mesmo cliente.
