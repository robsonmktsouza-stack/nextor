# ERP Estoque e Vendas — Laravel (independente, sem HUB)

Primeira versão funcional de um ERP de estoque e vendas em **Laravel 13**. Interface inspirada no painel administrativo do Away CMS enviado para referência: barra superior azul-escura com linha laranja, menu lateral recolhível, cartões claros, tabelas compactas, formulários e modais.

**Não é uma instalação modificada do Away CMS**: não contém controllers, licenciamento, marketplace, requisições a HUB, plugins ou atualizações remotas daquele software. A interface foi desenvolvida em Blade, CSS e JavaScript puro, sem necessidade de Node.js ou build frontend.

## Funcionalidades implementadas

- Login via sessão Laravel, CSRF, sem cadastro público. A criação inicial do administrador ocorre pelo Artisan.
- Dashboard: total de produtos, valor estimado de estoque pelo custo, estoque baixo, total de vendas concluídas e histórico recente.
- Produtos: cadastro e edição em modais, SKU único, categoria, unidade, preço de custo/venda, estoque mínimo e ativação/desativação.
- Clientes: cadastro e edição em modais, CPF/CNPJ, contato e observações.
- Estoque: entrada, saída e ajuste para saldo final, com motivo, data, operador e saldo anterior/novo.
- Vendas: novo registro com vários itens; preço buscado no banco, cálculo no servidor, bloqueio por estoque insuficiente, histórico, detalhamento e cancelamento com estorno das quantidades.
- Operações de venda e cancelamento feitas em **transações SQL**. Cada alteração de estoque gera registro em `stock_movements`.

## Requisitos

- PHP **8.3 ou superior** (recomendamos 8.4 no Laragon) com `pdo_mysql` (ou `pdo_sqlite`), `mbstring`, `openssl`, `xml`, `ctype`, `fileinfo` e demais extensões usuais do Laravel.
- MySQL/MariaDB ou SQLite. O `.env.example` já está configurado para MySQL no Laragon.
- O ZIP inclui a pasta `vendor/` do framework Laravel usada na análise para facilitar a primeira execução. **Não há Node/npm obrigatório**. Em projetos mantidos a longo prazo, execute `composer install` depois de configurar seu Composer; como não enviamos `composer.lock`, ele gerará um novo lockfile consistente para o ERP.

## Instalar no Laragon (Windows)

1. Extraia a pasta `ERP_Away_Estoque_Vendas` para `C:\laragon\www\ERP_Away_Estoque_Vendas`.
2. Crie o banco MySQL chamado `erp_estoque_vendas` pelo HeidiSQL/phpMyAdmin.
3. Abra o terminal do Laragon **na pasta do projeto** e execute:

```bat
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan erp:make-admin
php artisan serve
```

4. Abra `http://127.0.0.1:8000/login`, informe o e-mail e a senha criados pelo comando e utilize o ERP.

Caso o MySQL tenha senha ou porta diferente, edite `DB_*` no `.env` antes do comando `migrate`. Para usar SQLite, altere `DB_CONNECTION=sqlite`, crie um arquivo vazio em `database/database.sqlite` e configure `DB_DATABASE` com o caminho absoluto do arquivo (ou remova `DB_DATABASE`, usando o caminho padrão do Laravel), depois execute a migração.

O servidor de desenvolvimento (`artisan serve`) **não deve** ser exposto à internet; em produção, utilize Nginx/Apache com a raiz pública apontada para `public/`, APP_ENV=production, APP_DEBUG=false, HTTPS, backup e credenciais seguras.

## Estrutura

```text
app/
  Console/Commands/MakeAdmin.php
  Http/Controllers/   Auth, Dashboard, Product, Customer, Stock, Sale
  Models/             User, Product, Customer, StockMovement, Sale, SaleItem
  Services/           InventoryService, SalesService
bootstrap/app.php
config/
database/migrations/
public/css/erp.css
public/js/erp.js
resources/views/        Blade (layout, login, dashboard, produtos, clientes, estoque, vendas)
routes/web.php
vendor/                 Laravel e dependências PHP
```

## Regras já adotadas

- Saldo dos produtos **não é editado** pelo formulário de produto: apenas pelo registro de movimentações.
- Venda concluída reduz estoque; cancelamento restaura quantidades e registra estornos. Cancelamento repetido não é permitido.
- A venda não permite quantidades superiores ao estoque. Itens usam preço cadastrado **no momento da venda**, salvo como histórico imutável.
- Não há emissão de NF-e/NFC-e, contas a pagar/receber, multiempresa nem permissões por função nesta versão. Esses módulos podem ser adicionados posteriormente.

## Segurança e notas

- Nunca inclua `.env` ou a pasta `storage` com sessões/logs no repositório público.
- Por motivos de segurança, nenhuma conta de usuário ou senha padrão acompanha o pacote.
- Para produtos em quilo, metro e similares, a quantidade é mantida com três casas decimais.
- Ao publicar o software, verifique a licença das dependências e de quaisquer arquivos derivados da referência visual.
