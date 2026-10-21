# Lumeron Control — Painel Privado de Licenças

Aplicação Laravel 13 **independente** das instalações do ERP Lumeron/Nextor. Interface propositalmente enxuta, no padrão visual Lumeron, inspirada no fluxo de lista/detalhes/novo cliente do painel de representantes eGestor, sem copiar sua marca nem seu código.

**Escopo efetivamente implementado:** autenticação administrativa sem cadastro público; painel inicial; empresas com CNPJ, contatos, subdomínio, plano, módulos e validade; cadastro em três etapas; listagem, filtros e modal de detalhes; ativação, suspensão, renovação, notas e auditoria; endpoint de verificação de licença assinado com HMAC, controle de replay e verificação de CNPJ; testes de integração e CI.

**Não implementado ou não presumir:** cobrança automática, faturas, controle de revendedores, instalação automática em VPS, criação de usuário no ERP, provedor de pagamento ou domínio real. **Integração do ERP:** agente de verificação HMAC implementado na branch `develop` do ERP, desativado por padrão até configuração expressa por instalação. O cadastro aqui **não** instala o ERP no servidor nem cria usuário lá. A liberação comercial é imediata no painel, mas o ERP somente aplica restrições após receber a integração em seu deploy.

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
3. Na ficha, o proprietário pode **suspender, reativar, renovar, alterar plano e módulos**, sem copiar códigos e sem pedir confirmação ao usuário do ERP.
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

Esta ligação administrativa pontual não é uma etapa do cadastro do cliente e não precisa ser repetida em cada renovação, suspensão ou alteração. Durante provisionamentos gerenciados futuros, o operador de deploy deverá invocar a mesma rotina automaticamente depois de criar a instalação; **o provisionamento remoto automático ainda não foi implantado**.

### Diagnóstico de conexão entre dois projetos Laravel no Laragon

Se executar `php artisan lumeron:license-identity` **dentro de `nextor`** retornar corretamente o CNPJ mas `control:bind-local` **dentro de `lumeron-control`** informar que não encontrou o cadastro, a causa era o subprocesso PHP herdar configurações `APP_*/DB_*` do Control e acabar lendo o banco da aplicação errada.

**Correção aplicada:** `BindManagedInstallation::erpProcess()` retira do subprocesso somente as variáveis Laravel/comerciais herdadas e deixa o Nextor carregar suas próprias variáveis do `.env`. A correção protege tanto a consulta de CNPJ quanto a sincronização HMAC. Teste de regressão simula configurações de bancos e `APP_KEY` do Control no processo pai e verifica que nenhuma delas chega ao Nextor.

Após `git pull origin control-center` e `php artisan optimize:clear` no **Control**, execute novamente `php artisan control:bind-local "C:\\laragon\\www\\nextor" --url=http://127.0.0.1:8000`. **Não repita migrations nem altere o CNPJ fiscal** se `lumeron:license-identity` já retorna os 14 dígitos corretos.

### Protocolo interno de licenciamento

`POST /api/license/check` aceita JSON contendo CNPJ e versão (sem vendas ou dados fiscais). A requisição inclui UUID da instância, timestamp, nonce de uso único e HMAC-SHA256 sobre método, rota e SHA-256 do corpo. A resposta `status`, `expires_at`, `modules`, `plan`, `cnpj` e `checked_at` também é assinada com HMAC.

O agente no ERP, branch `develop`, lê o arquivo privado provisionado, valida CNPJ contra a empresa local, UUID, HMAC de resposta e horário, mantém cache de 5 minutos e tolerância offline limitada. A `main`/`v1.0.0` não recebe essa funcionalidade até nova promoção aprovada.

**A licença nunca apaga registros, XMLs, autorização fiscal ou certificado A1 e não substitui as permissões do ERP.** A preservação de operações fiscais de regularização é obrigatória.

Em produção usar HTTPS, armazenamento privado por instância, backup protegido e ferramentas de deploy autorizadas; `127.0.0.1` só se aplica a aplicações rodando na mesma máquina.

## Testes

```bash
php vendor/bin/phpunit
php artisan route:list
```

Arquivos e diretórios de instância, XMLs, certificado A1 e CSC **não pertencem** a este projeto.

Leia [AGENTS.md](AGENTS.md) antes de ampliar a aplicação.
