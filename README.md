# Lumeron Control — Licenças, Faturas e Gestão Comercial

Aplicação Laravel 13 **independente** das instalações do ERP Lumeron/Nextor. Interface propositalmente enxuta, no padrão visual Lumeron, inspirada no fluxo de lista/detalhes/novo cliente do painel de representantes eGestor, sem copiar sua marca nem seu código.

**Escopo efetivamente implementado:** autenticação administrativa; painel, clientes, planos, licenças e faturas comerciais; cadastro, filtros, detalhes, pagamentos manuais, estornos, cancelamentos e auditoria; automações de vencimento e e-mails opcionais; vínculo e renovação explicitamente autorizados pela API HMAC assinada; testes de integração e CI.

**Não implementado ou não presumir:** cobrança por gateway, boleto bancário/Pix real, nota fiscal, controle de revendedores ou domínio real. O cadastro gera uma tarefa de provisionamento; a instalação e o convite de primeiro acesso só acontecem se o **agente privilegiado na VPS estiver instalado e configurado**, com DNS, HTTPS e SMTP válidos. A atualização do repositório, sozinha, não ativa esse serviço. **Integração do ERP:** agente de verificação HMAC implementado na branch `develop` do ERP, desativado por padrão até configuração expressa por instalação. O cadastro cria uma tarefa para instalar o ERP e preparar a credencial de acesso pelo serviço gerenciado da VPS, sem comandos manuais por empresa. Até que o serviço conclua, a empresa aparece como **Aguardando preparação**, não como instalação pronta. A liberação comercial é imediata no painel, mas o ERP somente aplica restrições após receber a integração em seu deploy.

## Instalar (aplicação separada)

Até existir um repositório próprio, este código vive **isolado na branch `control-center`** do repositório GitHub `robsonmktsouza-stack/nextor`. Não mesclar esta branch na `main` ou `develop` do ERP: a raiz é uma aplicação autônoma, com seu próprio banco e `APP_KEY`.

```powershell
cd C:\laragon\www
git clone --branch control-center --single-branch https://github.com/robsonmktsouza-stack/nextor.git lumeron-control
cd lumeron-control
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan control:admin admin@seudominio.com --name="Administrador"
php artisan serve --port=8002
```

Configure o banco privativo do painel em `.env` **antes** de rodar migrations. O comando `control:admin` solicita a senha de forma interativa. Nenhum usuário padrão, senha ou token é enviado ao repositório. Acesse `http://127.0.0.1:8002/login`.

### Produção

Servidor próprio com HTTPS, PHP-FPM/Nginx (ou equivalente), MySQL/MariaDB, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` único, backups e logs protegidos. **Nunca use `php artisan serve` em produção.** O painel não deve compartilhar banco, diretórios privados, sessões ou chave da instalação cliente.

`composer.lock` deve ser usado em deploy (gerado/versionado pelo CI do projeto); nunca executar `composer update` em instância já publicada.

### Fluxo aprovado: simples para o proprietário e transparente para o cliente

1. **Nova conta** → razão social/CNPJ, dados de acesso, plano e validade.
2. **Criar conta** → cliente e licença ficam cadastrados e ativos no painel.
3. Na ficha, o proprietário pode **suspender, reativar, renovar e alterar plano**, sem copiar códigos e sem pedir confirmação ao usuário do ERP.
4. A ficha separa a situação comercial (**ativa/suspensa/vencida**) da conexão técnica (**aguardando instalação / já se comunicou**). Não declarar bloqueio remoto enquanto a instalação não estiver conectada.

O painel continua gerando internamente `instance_id` e segredo HMAC individuais criptografados em seu banco, **mas não exibe esses valores**. Não existe opção de gerar nova chave na interface.

### Instalação já existente no Laragon (vínculo controlado pela infraestrutura)

Depois de cadastrar a empresa e atualizar o ERP na branch `develop`, o **operador responsável pelo servidor** executa **uma vez**, no terminal do Lumeron Control:

```powershell
cd C:\laragon\www\lumeron-control
git pull origin control-center
php artisan control:bind-local "C:\laragon\www\nextor" --url=http://127.0.0.1:8000
```

A porta usada deve ser a porta **real** do painel local (por exemplo, 8000 ou 8002). O comando consulta o CNPJ **diretamente do ERP**, identifica a conta correspondente no Control e instala as credenciais automaticamente em `storage/app/private/lumeron-control.json`. **Não solicita confirmação a nenhum usuário do ERP nem imprime UUID/segredo; não edita `.env`.**

A rotina exige que a migração do agente esteja aplicada no ERP (`php artisan migrate` em ambiente de desenvolvimento, com backup prévio), que o CNPJ empresarial esteja configurado e que o Control possa gravar no diretório privado da instalação. Antes de vincular, confere `/up` do Control; após gravar o arquivo privado, executa a sincronização HMAC no ERP e exige resultado **online**. Se a sincronização falhar, restaura o arquivo anterior ou desfaz o vínculo novo para evitar bloqueio por falha de implantação. Se houver CNPJ diferente ou vínculo com outra empresa, **recusa sem sobrescrever dados**. O vínculo não é executado somente porque se digitou o CNPJ no painel.

Esta ligação administrativa pontual não é uma etapa do cadastro do cliente e não precisa ser repetida em cada renovação, suspensão ou alteração. No fluxo gerenciado novo, o agente da VPS aciona o instalador e a mesma rotina de vínculo sem intervenção do usuário do ERP. Em instalações legadas, o comando manual continua disponível para manutenção.

### Diagnóstico de conexão entre dois projetos Laravel no Laragon

Se executar `php artisan lumeron:license-identity` **dentro de `nextor`** retornar corretamente o CNPJ mas `control:bind-local` **dentro de `lumeron-control`** informar que não encontrou o cadastro, a causa era o subprocesso PHP herdar configurações `APP_*/DB_*` do Control e acabar lendo o banco da aplicação errada.

**Correção aplicada:** `BindManagedInstallation::erpProcess()` retira do subprocesso somente as variáveis Laravel/comerciais herdadas e deixa o Nextor carregar suas próprias variáveis do `.env`. A correção protege tanto a consulta de CNPJ quanto a sincronização HMAC. Teste de regressão simula configurações de bancos e `APP_KEY` do Control no processo pai e verifica que nenhuma delas chega ao Nextor.

Após `git pull origin control-center` e `php artisan optimize:clear` no **Control**, execute novamente `php artisan control:bind-local "C:\\laragon\\www\\nextor" --url=http://127.0.0.1:8000`. **Não repita migrations nem altere o CNPJ fiscal** se `lumeron:license-identity` já retorna os 14 dígitos corretos.

### Protocolo interno de licenciamento

`POST /api/license/check` aceita JSON contendo CNPJ e versão (sem vendas ou dados fiscais). A requisição inclui UUID da instância, timestamp, nonce de uso único e HMAC-SHA256 sobre método, rota e SHA-256 do corpo. A resposta `status`, `expires_at`, `modules`, `plan`, `cnpj` e `checked_at` também é assinada com HMAC.

O agente no ERP, branch `develop`, lê o arquivo privado provisionado, valida CNPJ contra a empresa local, UUID, HMAC de resposta e horário, mantém cache de 5 minutos e tolerância offline limitada. A `main`/`v1.0.0` não recebe essa funcionalidade até nova promoção aprovada.

**A licença nunca apaga registros, XMLs, autorização fiscal ou certificado A1 e não substitui as permissões do ERP.** A preservação de operações fiscais de regularização é obrigatória.

Em produção usar HTTPS, armazenamento privado por instância, backup protegido e ferramentas de deploy autorizadas; `127.0.0.1` só se aplica a aplicações rodando na mesma máquina.

## Planos administráveis e automações

A partir de 21/10/2026, os planos comerciais **não são mais definidos em `config/license.php`**. A nova migration `2026_10_21_000001_create_plans_and_automation_tables.php` cria as tabelas `plans`, `automation_rules` e `automation_deliveries` sem alterar os documentos, clientes, senhas ou as licenças existentes. Durante a migration, apenas o catálogo inicial é criado com os códigos legados `standard`, `advanced` e `full`; os dados de planos passam a ser mantidos no **banco**, pela seção **Planos** da central.

O painel permite criar/editar plano, descrição, preço de referência, período, módulos incluídos e disponibilidade. Código de plano em uso permanece estável. Arquivar um plano o retira de novas contas sem suspender licenças já emitidas. **O plano é a fonte exclusiva dos módulos:** selecionar um plano na licença aplica todos os seus módulos automaticamente. Editar os módulos de um plano altera os direitos de todos os clientes vinculados quando o ERP atualizar sua licença (cache de até cinco minutos); antes de salvar, a tela informa o número de clientes afetados. O preço ainda não cria cobrança automática.

A nova seção **Automações** contém três regras configuráveis, **todas desativadas por padrão**:

- Próximo vencimento: um aviso interno por data de validade quando restarem até N dias.
- Vencimento ocorrido: um aviso interno por data de validade vencida. O estado `expired` já é calculado dinamicamente mesmo sem esse aviso.
- Sem comunicação: aviso quando uma instalação não se comunica há N dias, incluindo pendentes há muito tempo.

As regras geram avisos internos; as regras de vencimento podem **opcionalmente enviar e-mail**, apenas com SMTP habilitado e switch da regra ligado. Não enviam WhatsApp nem geram faturas, renovam ou suspendem licenças automaticamente. Avisos possuem deduplicação por cliente e vencimento, com histórico de entrega; os parâmetros são alterados pelo painel.

### Ativar no Laragon após atualizar a branch

```powershell
cd C:\laragon\www\lumeron-control
git pull origin control-center
php artisan migrate
php artisan optimize:clear
php artisan control:automations-run
php artisan schedule:list
```

`control:automations-run` executa somente as regras que você habilitou na interface; enquanto todas estiverem desligadas, não cria avisos. Para **execução diária automática em desenvolvimento**, mantenha o agendador rodando em **outro terminal**:

```powershell
cd C:\laragon\www\lumeron-control
php artisan schedule:work
```

O agendamento registrado é diário às **08:00 (America/Sao_Paulo, conforme `APP_TIMEZONE`)**, e usa `withoutOverlapping()` para reduzir sobreposição. Se o Laragon estiver desligado nesse horário, a rotina não é executada automaticamente até o próximo agendamento; use o botão **Executar verificação** no painel para conferir imediatamente.

Em Linux, execute o agendador do Laravel via cron **todo minuto**, em um servidor confiável que possua acesso ao banco privado do Control:

```cron
* * * * * cd /srv/lumeron-control/current && php artisan schedule:run >> /dev/null 2>&1
```

O diretório acima é **exemplo**, não uma instalação existente. Não configurar o cron do ERP/Nextor como substituto: a rotina pertence exclusivamente ao Lumeron Control. O scheduler só funciona com o processo/cron ativo; não afirmar monitoramento 24 horas a partir da simples instalação da migration.

## Listagem de licenças no padrão do ERP

A seção **Licenças** reutiliza a linguagem visual compacta da grade Produtos do Lumeron ERP: **Nova conta**, ícones de impressão, exportação CSV da **página atual**, atualização, filtros recolhíveis por nome/CNPJ, situação e plano, cabeçalho denso, ações de visualizar/gerenciar e paginação de 20, 50 ou 100 registros. Não foi adicionada seleção em massa por checkboxes.

Ativação e suspensão por empresa usam **switch** com confirmação em modal compacto e envio CSRF ao controller autenticado. A ficha de cada empresa também mostra o switch; o status `Vencida` continua vencido mesmo quando a permissão administrativa foi ativada, até renovar a data. As opções com estados ligado/desligado em **Planos**, **Automações** e **Módulos** são representadas por switches, não caixas de marcação visíveis. Os módulos são configurados somente em Planos; a licença salva apenas plano e validade.

Impressão usa `window.print()` na página exibida, com CSS próprio; a exportação gera CSV apenas da página corrente e aplica proteção contra fórmulas em células. O bloqueio e a ativação continuam decididos pelo backend, não pelo switch visual.

## Testes

```bash
php vendor/bin/phpunit
php artisan route:list
```

Arquivos e diretórios de instância, XMLs, certificado A1 e CSC **não pertencem** a este projeto.

Leia [AGENTS.md](AGENTS.md) antes de ampliar a aplicação.


## Padrão de listagens unificado

O Lumeron Control utiliza **um único conjunto de componentes de listagem**. O mesmo visual compacto de Licenças foi aplicado a Clientes, Planos, Histórico, Regras e Avisos de Automações e à tabela de vencimentos no Início. Os arquivos compartilhados são `resources/views/partials/listing/toolbar.blade.php` e `footer.blade.php`, os estilos estão em `public/css/control.css`, e o comportamento de impressão, CSV, filtros e paginação em `public/js/control.js`.

Todas as tabelas oferecem ferramentas por ícones, com exportação CSV restrita à tabela acionada e aos registros da página atual. Ações que modificam dados continuam protegidas por autenticação e CSRF. Filtros recolhíveis e paginação 20/50/100 se aplicam às listagens paginadas; tabelas-resumo pequenas não apresentam paginação vazia. O Dashboard mantém a seção de atividades recentes em formato de histórico visual, pois ela não é uma tabela.

Não há migration adicional para a unificação visual. Após `git pull origin control-center`, executar `php artisan optimize:clear` e atualizar o navegador com Ctrl+F5.

## Plano define todos os módulos — migração 21/10/2026

Execute `php artisan migrate` no Control **antes de liberar o ERP atualizado**: a migração `2026_10_22_000001_preserve_legacy_entitlements_as_plans.php` preserva acessos individuais anteriores criando planos legados arquivados. Por exemplo, uma empresa anteriormente no Padrão com Financeiro liberado separadamente ficará com um plano anterior dedicado, até o operador escolher um novo plano. A auditoria registra a migração e os módulos concedidos não mudam. Os dados de CNPJ, licença, senha, validade e status não são alterados.

A API assinada retorna o array `modules` **derivado de `plans.modules`**, não de `companies.modules`. Novas licenças não têm mais switches individuais para módulos. O Nextor `develop` reconhece qualquer código válido e restringe menus, URLs e API token conforme o array assinado. As operações fiscais de regularização legal permanecem acessíveis com autorização do usuário, mesmo sem o módulo Fiscal contratado. O plano não altera permissões individuais dos empregados.

**Laragon:** atualize o Control, execute `php artisan migrate`, limpe o cache do Control e somente depois atualize o Nextor `develop` e execute `php artisan optimize:clear`. Não atualizar `main`/`v1.0.0`.

### Integridade da validade quando troca o plano

O endpoint de licença mantém a data de vencimento existente quando o formulário
envia somente um novo plano. A data só muda se `expires_at` for enviada
explicitamente. Isso evita que integrações ou formulários parciais transformem
acidentalmente uma licença com prazo em assinatura sem vencimento.

Após mudar um plano, o ERP conectado normalmente consulta o resultado na próxima
requisição depois do cache de 60 segundos; uma instância offline respeita sua
tolerância assinada, portanto não recebe alterações instantâneas.

### Vínculo opcional na atualização Linux gerenciada

Com o Lumeron Control instalado no mesmo host (ou com acesso local seguro ao
diretório do ERP), o operador de deploy pode chamar `control:bind-local`
após cadastrar a empresa no painel e configurar o CNPJ emitente no ERP.
Em Linux, `--file-owner=lum_<id>` permite que o arquivo privado criado pelo
operador privilegiado pertença ao usuário PHP da instalação, antes do teste
de comunicação. Não usar este parâmetro no Laragon/Windows.

O script `deploy/instances/update.sh` do ERP aceita explicitamente
`--control-path` e `--control-url` para realizar o vínculo ao atualizar uma
instalação autorizada. Cadastrar um CNPJ no painel sozinho não dá prova de
posse de um servidor e não o provisiona remotamente.

### Cadastro e edição das automações

O Control permite criar automações internas e editar regras no modal da grade,
incluindo nome, descrição, prazo e switch. Existem apenas três tipos seguros:
licença próxima do vencimento, licença vencida e instalação sem comunicação.
A regra é criada desativada, exceto se o administrador optar por ativá-la.
Após salvar, o tipo fica fixo para não alterar a interpretação do histórico.
É possível configurar várias regras do mesmo tipo em prazos distintos.

A migration `2026_10_21_000003_add_kind_to_automation_rules.php` conserva
os códigos, as regras existentes e a deduplicação dos avisos. Executar
`php artisan migrate` **somente no Lumeron Control**, nunca no ERP.
As rotinas não enviam mensagens nem executam comandos arbitrários.


### Notificações externas de licenças por e-mail (opcional)

O Lumeron Control registra avisos internos e, se explicitamente configurado,
também prepara e-mails para clientes sobre **licenças prestes a vencer** ou
**já vencidas**. O operador marca `Avisar cliente por e-mail` nas regras
de vencimento; a regra de instalação offline **não** pode mandar mensagem.
Novas regras e todas as regras existentes começam com a opção desligada.

A migration `2026_10_21_000004_create_client_email_deliveries.php` preserva
cadastros, regras e histórico. Ela adiciona um switch por regra e uma fila
individual com status pendente/enviado/falha/cancelado, registro de tentativas,
até três tentativas com intervalo crescente e prevenção de duplicidade por
regra + empresa + ciclo de validade. Avisos já registrados antes da opção ser
habilitada **não** disparam mensagens retroativas. Ao tentar enviar, a central
verifica novamente a data de vencimento e o estado da licença: renovações ou
suspensões tornam o aviso pendente cancelado.

**Sem disparo automático após atualizar o código.** Configure um servidor SMTP
na instalação privada do **Control**, com `MAIL_MAILER=smtp`,
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS` e `MAIL_FROM_NAME`. Teste o SMTP com um destinatário
administrativo controlado antes de habilitar `LUMERON_CLIENT_EMAIL_ENABLED=true`.
O padrão é `MAIL_MAILER=log` e `LUMERON_CLIENT_EMAIL_ENABLED=false`.
Somente os dois gates (global + switch da regra) permitem envio.

O scheduler existente executa `control:automations-run` às 08:00; o comando
`control:client-email-send` processa a fila a cada 10 minutos entre 08:10 e
18:00, no fuso do Control, inclusive para tentativas após falha. É preciso manter
o agendador operacional em produção. É possível executar
`php artisan control:client-email-send` manualmente (o comando respeita as
mesmas travas). A tela Automações mostra a fila e permite um envio manual
autenticado apenas quando o transporte está pronto. O marcador **Enviado**
significa que o transporte SMTP aceitou a mensagem, não leitura pelo
destinatário.

**WhatsApp não é enviado automaticamente**: requer provedor/Cloud API oficial,
credenciais e modelos aprovados; nenhum endpoint foi simulado.

## Vencimentos integrados entre Control e ERP

O **Control é a autoridade comercial**: administra a validade da assinatura,
a suspensão/renovação, as regras de aviso e a entrega de e-mails.
A cada consulta HMAC assinada no endpoint `POST /api/license/check`, além de
plano/módulos/status/data, envia o campo opcional `notice_days`, correspondente
à maior antecedência das regras **ativas** do tipo `expiry_soon`. O campo
é coberto pela assinatura da resposta; não há transmissão do e-mail do
cliente nem de dados fiscais.

O ERP valida e armazena a configuração assinada e a exibe discretamente em
seu próprio cabeçalho/PDV quando está prestes a vencer. Sem regra ativa,
`notice_days=null`: nada é mostrado na instalação. Uma alteração de
validade no Control passa para o ERP na próxima checagem (cache de 60s
quando online); após falha de comunicação, segue a tolerância offline já
estabelecida, e não se promete sincronização imediata.

O monitor da central acompanha `last_seen_at` atualizado somente após
uma chamada HMAC legítima: cadastro de CNPJ sem vínculo seguro não
representa uma instalação conectada. Em Automações, resumo de situações
comerciais, uma única área de rolagem e históricos em abas substituem
a exibição de três grades longas ao mesmo tempo.

O **envio externo de e-mail** usa o cadastro de contato do próprio Control,
exige SMTP real + a configuração global de opt-in + o switch da regra,
e possui trilha de entrega independente. Avisos exibidos no ERP não
dependem do SMTP e não garantem que uma mensagem foi entregue ou lida.
Não executar migrations/atualizações cruzadas: banco do Control e bancos
dos ERPs são distintos.

## Faturas comerciais e pagamentos

A aba **Faturas** pertence exclusivamente ao Lumeron Control (branch
`control-center`); somente a ação administrativa explícita de aplicar uma fatura quitada altera a validade central, transmitida ao ERP via HMAC. Permite emitir faturas comerciais
numeradas `FAT-000001` vinculadas a um CNPJ, com cópia imutável do nome do
cliente, CNPJ, plano, período, valor, vencimento e descrição. O preço sugerido
equivale ao preço **mensal** configurado no plano multiplicado pelo
`period_months`; o operador pode ajustar valores antes da emissão. Se o plano
não tiver preço definido, o operador informa um valor. Não gerar documento
fiscal, nota fiscal, link de Pix, boleto bancário ou cobrança em gateway.

Fluxo: criar rascunho → editar/conferir → emitir → registrar pagamentos
parciais ou integrais por Pix, boleto, transferência, cartão, dinheiro ou
outro (registro **manual**, não confirmado por banco). Cada pagamento
guarda data, valor, referência e usuário; estornos mantêm histórico e
exigem motivo. Faturas quitadas, parciais, vencidas e em aberto derivam
dos pagamentos não estornados e da data; uma fatura emitida não pode ser
editada e uma fatura com pagamentos ativos não pode ser cancelada.
A criação impede períodos sobrepostos para o mesmo cliente, exceto faturas
canceladas. Cancelamentos exigem motivo e preservam o registro.

**Sem efeitos comerciais implícitos:** registrar pagamento **não renova**
uma licença, não modifica os módulos e não envia e-mail automaticamente.
A renovação continua uma ação administrativa explícita em Licenças/Clientes.
Faturas também não são encaminhadas ao ERP no payload HMAC de licenciamento.
Não criar cobranças históricas em massa nem emitir faturas automaticamente
após atualizar a aplicação. Uma futura integração com PSP deve exigir
webhooks autenticados, idempotência e consentimento/aviso apropriado.
Rodar a migration `2026_10_21_000005_create_invoices_and_payments.php`
**somente no banco do Control**.

### Renovar a licença a partir de uma fatura quitada

A fatura **não renova automaticamente** quando se registra um pagamento.
Depois de quitar 100% do valor, o operador pode usar o botão **Renovar
licença**. O Control confere o saldo, bloqueia aplicação repetida, exige
que o novo período realmente amplie a validade atual e audita a operação.
A licença atualizada é transmitida ao ERP na próxima checagem HMAC assinada.
Uma suspensão manual prévia não é removida por essa operação.

O botão **Desfazer renovação** restaura a validade anterior somente se
ninguém a alterou posteriormente; a reversão é auditada. Não é permitido
estornar um pagamento ou cancelar uma fatura com renovação aplicada sem
antes desfazê-la com segurança. Esta operação não comprova recebimento
bancário: pagamentos seguem sendo lançados manualmente.


## Fluxo integrado de cadastro, cobrança e validade — 21/10/2026

**Não há telas nem módulos novos.** O formulário Clientes > Nova conta mantém as
mesmas etapas e campos. Ao concluir, o Control cria cliente, licença HMAC e,
se o plano tiver `monthly_price > 0`, uma **fatura em rascunho** para o
PRÓXIMO período a partir do dia seguinte à validade inicial escolhida.
O plano determina a duração de 1/3/6/12 meses, e o preço da fatura é
`monthly_price × period_months` no momento da criação. Nunca reutilizar
uma fatura de outro ciclo. Se o plano não tiver preço, o cadastro conclui,
mas não inventa valor nem fatura; a confirmação orienta o operador a
configurar o preço na tela Planos.

A validade digitada ao cadastrar é o **prazo de liberação inicial**
(trial, contrato já vigente ou condição comercial acertada). **Não será
substituída pelo prazo do plano:** essa escolha continua com o operador.
Se ficar em branco, o acesso inicial vai **somente até o dia do cadastro**
(nunca indefinido). Datas anteriores a hoje são recusadas.

A primeira fatura não deve cobrar novamente o período inicialmente liberado:
ela começa no dia seguinte ao vencimento atual e cobre a duração do plano.
O vencimento da fatura coincide com a validade inicial informada. A
fatura permanece em rascunho até o operador usar **Emitir** na tela
Faturas. Não há cobrança externa, pagamento criado ou e-mail nesse fluxo.
A transação de cadastro e a preparação da fatura são atômicas: se
qualquer operação falhar, o cliente e a fatura não ficam pela metade.

Em uma fatura preparada pelo Control (origem `onboarding` ou
`recurring`), o operador registra o pagamento manualmente; a licença
só avança automaticamente após **quitação integral**, se o período da
fatura começar **exatamente no dia seguinte** à validade atual. Esta
ação é auditada e não reativa uma suspensão administrativa. Faturas
criadas manualmente e períodos desalinhados seguem exigindo aplicação
explícita da validade. Estornos requerem antes desfazer essa aplicação;
duas quitações/duas renovações para um mesmo período não são permitidas.

`control:invoices-prepare`, agendado diariamente às **08h05**,
prepara **um** rascunho para o próximo ciclo quando faltarem até sete dias
para terminar o último ciclo já emitido e não cancelado. Não gera
históricos de meses anteriores, não inventa cobrança para clientes
existentes sem um primeiro ciclo, não emite fatura, não envia e-mails,
não aciona gateway e não gera quando há um rascunho posterior existente.
Se uma empresa estiver suspensa ou o plano não tiver preço, pula a
preparação. O comando manual `php artisan control:invoices-prepare`
também respeita essas verificações, é seguro contra repetição e pode
ser usado em testes de homologação sem comunicar o cliente. O cron/
agendador do Control deve estar funcionando para a recorrência operar.

A integração ERP **não mudou**: continua recebendo apenas a validade,
status, plano e módulos assinados pela API HMAC. Registrar/emitir uma
fatura não cria instalação nem prova que o ERP está conectado; o
vínculo inicial de instalação continua sendo um passo de
infraestrutura autorizado. Essa migração é **exclusiva** do banco Control:
`2026_10_21_000007_invoice_cycle_origins.php`. Instalações já
cadastradas e suas faturas anteriores não sofrem reemissão retroativa.


## Cadastro único com provisionamento gerenciado (21/10/2026)

**Objetivo do cliente:** o proprietário cadastra uma empresa no Control com CNPJ,
subdomínio, contato, usuário inicial, plano e validade, e o cliente recebe
um **convite de primeiro acesso** para definir a própria senha no ERP. Não é
necessário criar diretórios, usuários Linux, bancos ou credenciais HMAC
individualmente para cada empresa depois da implantação inicial da infraestrutura.

O formulário e todos os menus existentes foram preservados. No mesmo
`CompanyController::store`, a transação cria uma tarefa
`managed_provisioning_jobs` na situação `queued`, além da licença e da
fatura em rascunho já existentes. **Uma conta em fila não é uma instalação
pronta.** A ficha do cliente mostra o estado de preparação no bloco
original de detalhes; nenhuma senha, chave HMAC ou token de convite aparece
no painel. O operador informa `admin_login` (ou será usado `admin`),
e o convite é dirigido ao e-mail cadastrado.

A aplicação HTTP **nunca** executa shell, sudo ou provisionamento.
Um serviço **separado e restrito na VPS** executa
`php artisan control:provision-accounts --limit=1` como root;
o comando recusa execução fora de Linux/produção, com flag desabilitada,
código executável sem propriedade root, SMTP desconfigurado ou caminhos
não confiáveis. Cada tarefa nova aciona
`deploy/instances/provision.sh --managed --apply` na árvore do ERP e cria:

- Um usuário Linux, banco MySQL, chave Laravel, armazenamento e ambiente
  exclusivos para o CNPJ (pasta `/srv/lumeron/instances/c_<ID>`);
- Aplicação, endereço HTTPS e usuário inicial do ERP;
- Identidade fiscal empresarial básica (CNPJ e razão social) para o vínculo
  HMAC existente, sem presumir certificado A1 nem credenciamento SEFAZ;
- Convite para criar senha no próprio domínio da empresa, enviado pelo SMTP
  do Control somente após sincronização segura e verificação de `/up`.

A preparação exige **configuração da infraestrutura uma única vez**.
No servidor de produção do Control, configure exclusivamente no `.env`
privado, nunca no GitHub:

```dotenv
LUMERON_MANAGED_PROVISIONING=true
LUMERON_ERP_RELEASE_SOURCE=/opt/lumeron/source
LUMERON_CONTROL_RELEASE_PATH=/srv/lumeron-control/current
LUMERON_CONTROL_PUBLIC_URL=https://control.seudominio.com.br
LUMERON_CUSTOMER_BASE_DOMAIN=seudominio.com.br
LUMERON_CUSTOMER_PHP_VERSION=8.3
LUMERON_MYSQL_ADMIN_CNF=/root/.my.cnf
LUMERON_CUSTOMER_TLS_CERT_NAME=seudominio.com.br
LUMERON_MANAGED_INVITE_EMAIL=true
MAIL_MAILER=smtp
MAIL_HOST=seu-servidor-smtp
MAIL_PORT=587
MAIL_USERNAME=seu-usuario
MAIL_PASSWORD=defina-na-vps
MAIL_FROM_ADDRESS=acesso@seudominio.com.br
MAIL_FROM_NAME="Lumeron"
```

**Os valores são exemplos, não pressupostos sobre a VPS existente.**
O registro DNS curinga `*.seudominio.com.br` precisa apontar para a VPS,
e o certificado TLS usado deve cobrir os subdomínios. Os binários
Linux/PHP-FPM, Nginx, composer, MySQL e credenciais administrativas
seguem os pré-requisitos de `provision.sh`. O código-fonte do
Control e o provisionador executável precisam ter propriedade root e
não permitir escrita de grupo/outros antes de habilitar o serviço.

Instale **uma vez** um agendador dedicado para o comando acima no
servidor confiável. Esse comando **não** deve ser adicionado ao scheduler
Laravel que roda como usuário web: apenas o serviço de provisionamento
autorizado pode criar usuários Linux e bancos MySQL. Agendar o serviço
a cada minuto torna o cadastro diário automático; um administrador de
infraestrutura deve inspecionar os logs do timer no início.

A tarefa assume `ready` **somente depois** que a aplicação responde,
a licença está vinculada e o SMTP aceita o convite. Uma falha deixa
`failed` (revisão técnica), sem classificar a conta como pronta nem
apagar silenciosamente bancos ou arquivos parciais. Corrigir falhas
parciais exige revisão de infraestrutura, pois não é seguro fazer
remoção automática de dados de cliente. Após convite vencido, o cliente
pode solicitar nova senha na própria tela de login do ERP.

**Não migrar os dados de dois clientes para um mesmo banco**.
A URL de cada empresa é separada, mas o processo de provisionamento é
invisível ao cliente. Atualizações futuras continuam preservando seu
banco/armazenamento privado. O pagamento e a criação de fatura não
acionam instalações duplicadas. A `main`/tag estável não é alterada.

## Provisionamento automático no Laragon (teste de piloto local)

O Control agora oferece `control:provision-local`, isolado do
provisionador de VPS. É exclusivo de ambiente `APP_ENV=local` e não
executa alterações no Nextor original. Cada empresa recebe a pasta
`C:\laragon\www\lumeron-<subdominio>` (sob o diretório irmão do Control),
instância Laravel independente, banco **SQLite exclusivo**, `APP_KEY`,
sessões, cache e storage exclusivos, administrador inicial e vínculo
HMAC autenticado com o Control. O URL local segue o padrão
`http://lumeron-<subdominio>.test`. Se o Laragon não estiver com
**Auto Virtual Hosts** habilitado, habilite/recarregue o Apache uma vez;
a instalação só é marcada como pronta após `GET /up` do ERP responder
corretamente e não expor o arquivo `.env` via HTTP.

**Para a empresa existente #2**:
```powershell
cd C:\laragon\www\lumeron-control
git pull origin control-center
php artisan migrate
php artisan optimize:clear
php artisan control:provision-local 2 --preview
php artisan control:provision-local 2
```

O `--preview` é somente leitura. O processamento reutiliza a fila
`managed_provisioning_jobs` já criada e **não cria outra empresa ou
fatura**. O projeto ERP de origem é obtido do `HEAD` limpo
da branch `develop`, por `git archive`; não copia `.env`, banco,
storage, certificados ou arquivos particulares da instância anterior.
O Control usa o comando existente `control:bind-local` para vincular a
licença por CNPJ confirmado pelo próprio ERP, sem revelar segredo.

Quando a ficha informar **Acesso preparado**, o operador encontra
o link **Primeiro acesso ao ERP** no bloco original de detalhes.
Ele abre o convite do próprio Laravel para definir senha. O link é
disponível **somente dentro da sessão autenticada do Control local**;
não há envio de e-mail de teste ao cliente. Após criar a senha,
o usuário acessa o endereço individual com subdomínio e login que
já constavam no cadastro do Control.

Para todos os **próximos cadastros automáticos no Laragon**, configure
`LUMERON_LOCAL_PROVISIONING=true` no `.env` do Control, e rode
`php artisan optimize:clear`. Mantenha
`php artisan schedule:work` em um terminal local separado. O
agendador processa tarefas novas uma vez por minuto, sem comandos
por empresa. Um erro fica como **Precisa de revisão** (a tarefa não
é declarada pronta sem o ERP estar acessível); para reprocessar
explicitamente, use `php artisan control:provision-local ID`.

O provisionamento usa PHP com extensão `zip`, `git` e `composer`
do Laragon. A versão SQLite é exclusiva para desenvolvimento local;
na VPS a infraestrutura gerenciada usa MySQL independente por cliente,
como definido no script Linux. Não execute nem publique estas
instâncias de testes como sistemas reais de produção.
