# Lumeron Control — Painel Privado de Licenças

Aplicação Laravel 13 **independente** das instalações do ERP Lumeron/Nextor. Interface propositalmente enxuta, no padrão visual Lumeron, inspirada no fluxo de lista/detalhes/novo cliente do painel de representantes eGestor, sem copiar sua marca nem seu código.

**Escopo efetivamente implementado:** autenticação administrativa sem cadastro público; painel inicial; empresas com CNPJ, contatos, subdomínio, plano, módulos e validade; cadastro em três etapas; listagem, filtros e modal de detalhes; ativação, suspensão, renovação, notas e auditoria; endpoint de verificação de licença assinado com HMAC, controle de replay e verificação de CNPJ; testes de integração e CI.

**Não implementado ou não presumir:** cobrança automática, faturas, controle de revendedores, instalação automática em VPS, criação de usuário no ERP, provedor de pagamento ou domínio real. **Integração do ERP:** agente de verificação HMAC implementado na branch `develop` do ERP, desativado por padrão até configuração expressa por instalação. O cadastro aqui **não** instala o ERP no servidor nem cria usuário lá.

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

### Licença e integração

Para cada empresa criada o painel gera:
- `instance_id` UUID fixo e secreto HMAC de 48 bytes representado em base hexadecimal, armazenado criptografado com `APP_KEY`.
- CNPJ vinculado e subdomínio exclusivo; mudança de CNPJ não é permitida após cadastro.
- Situação `active`, `suspended` ou `expired` (expiração derivada da validade); módulos autorizados.

**O segredo é mostrado apenas uma vez após a criação**, em sessão autenticada e uma resposta de uso único. Armazene-o exclusivamente no ambiente privado da instância correspondente; não divulgue em tickets ou logs.

`POST /api/license/check` aceita JSON:
```json
{"cnpj":"11222333000181","version":"v1.0.0"}
```

Cabeçalhos:
- `X-Lumeron-Instance`: UUID da instalação
- `X-Lumeron-Timestamp`: Unix UTC segundos, tolerância de 300 s
- `X-Lumeron-Nonce`: hexadecimal aleatório de 32 caracteres, uso único
- `X-Lumeron-Signature`: HMAC-SHA256 hexadecimal, chave secreta; mensagem `POST\n/api/license/check\n{timestamp}\n{nonce}\n{sha256(raw JSON body)}`

Resposta (HTTPS obrigatório em produção): `status` (active, suspended, expired), `expires_at`, `modules`, `instance_id`, `checked_at`, `plan`, cabeçalho `X-Lumeron-Signature` com HMAC-SHA256 **sobre o corpo JSON exato** para autenticar a resposta. O cliente ERP deve validar HMAC, UUID, CNPJ, nonce de saída e política de cache antes de aplicar restrições; a integração do cliente é tarefa separada para não alterar o PDV fiscal estável sem testes. Sem heartbeat não se afirma que o cliente está bloqueado remotamente.

A licença **não** deve apagar banco, documentos fiscais, XMLs, autorizações, nem impedir a regularização legal de NFC-e. Bloqueio comercial e continuidade fiscal requerem política operacional específica no ERP.

## Testes

```bash
php vendor/bin/phpunit
php artisan route:list
```

Arquivos e diretórios de instância, XMLs, certificado A1 e CSC **não pertencem** a este projeto.

Leia [AGENTS.md](AGENTS.md) antes de ampliar a aplicação.
