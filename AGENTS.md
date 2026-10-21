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
