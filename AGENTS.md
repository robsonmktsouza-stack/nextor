# Instruções obrigatórias — Lumeron Control

Esta branch `control-center` contém **um projeto Laravel independente**, não o ERP. Nunca abrir PR para incorporar a árvore na `main` ou `develop` do ERP. Preservar seu banco, login e segredos separados das instalações comerciais.

Produto aprovado: painel enxuto do proprietário, com Início, Clientes, Licenças e Histórico. Mesmo padrão visual Lumeron (Roboto, azul-petróleo, tabelas densas, sem gráficos ou controles supérfluos). Fluxo de cadastro por etapas, listagem e detalhes com notas/auditoria, inspirado funcionalmente nas capturas do painel eGestor, sem replicar propriedade intelectual.

Cada cliente: um CNPJ, instalação, segredo HMAC próprio, plano, validade e módulos. Nunca armazenar senha do administrador do cliente neste banco. Geração de licenças não executa scripts na VPS. Bloqueios são estados de licença; exigem que o cliente ERP consulte e respeite o estado antes de terem efeito remoto. Não alterar PDV/NFC-e do ERP automaticamente.

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
- **Nunca** mudar o código de um plano usado por empresas, nem alterar módulos/validade de clientes quando a definição comercial muda. Arquivar remove opção para novos cadastros, não cancela assinaturas já emitidas. API HMAC do ERP continua retornando o código de plano e módulos da licença individual.
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
