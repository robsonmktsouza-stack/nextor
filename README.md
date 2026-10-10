# Lumeron ERP

Sistema de gestão empresarial desenvolvido em **Laravel 13**, com PDV, vendas, produtos, serviços, clientes, estoque, financeiro e interfaces fiscais.

O projeto nasceu como **Nextor**. O repositório permanece com o endereço técnico original por compatibilidade com os ambientes e integrações existentes, mas o produto e a identidade visual passam a se chamar **Lumeron**.

## Identidade visual

Os dois arquivos oficiais da marca estão incluídos em:

- `public/images/lumeron/logo.png` — elefante e nome Lumeron, fundo transparente.
- `public/images/lumeron/elephant.png` — mascote isolado, fundo transparente.

A logo completa aparece na entrada do sistema e nos espaços amplos. O elefante identifica o sistema no menu compacto, no PDV e no ícone da aba. O estilo está centralizado em `public/css/branding.css`, mantendo as classes legadas para evitar regressões.

A marca **Lumeron** não substitui os dados nem a logo fiscal de cada empresa emitente em seus documentos.

## Funcionalidades

- Autenticação por subdomínio, nome de usuário e senha, recuperação de senha e permissões.
- Cadastros de produtos, serviços, clientes, fornecedores e configurações empresariais.
- Controle de vendas, PDV, estoque, caixa, financeiros e relatórios.
- Emissão e acompanhamento fiscal: telas NF-e, NFC-e, NFS-e, CT-e e MDF-e em estágios diferentes de implementação.
- **NFC-e via ACBrLibNFe**: emissão, cancelamento, inutilização e contingência em desenvolvimento/testes assistidos. Não pressupor aprovação para produção apenas porque homologação foi bem-sucedida.

## Ambiente local: Laragon

Requisitos principais: PHP 8.3+, extensões Laravel, MySQL ou MariaDB, Composer e, para os fluxos NFC-e, ACBrLibNFe e schemas compatíveis com o sistema operacional.

```bash
git pull
composer install
php artisan migrate
php artisan optimize:clear
php artisan serve
```

Abra `http://localhost:8000/login`. Para criar o primeiro administrador da base local, execute `php artisan erp:make-admin`; o nome do comando permanece por compatibilidade.

As configurações locais do banco permanecem no `.env` e não devem ser versionadas. Depois de atualizar, pressione `Ctrl+F5` para garantir o carregamento dos estilos e das imagens oficiais.

### Verificações úteis

```bash
php artisan test --filter=LumeronLoginTest
php artisan test --filter=InstanceIsolationTest
php artisan test --filter=LumeronBrandingTest
php artisan test --filter=FiscalModuleTest
```

## Arquitetura comercial: um CNPJ por instalação

Cada CNPJ contratado possui seu próprio banco, arquivos, usuários, certificado, CSC e processos fiscais. Um cliente com duas empresas terá **duas instalações independentes**. As rotinas para futura implantação isolada foram preparadas em `deploy/instances/`, mas **não executam nenhuma implantação na VPS sem autorização expressa**.

- [Instalações independentes por CNPJ](docs/deployment/one-cnpj-per-instance.md)
- [Login com subdomínio](docs/deployment/login-by-subdomain.md)

A API de leitura utiliza `/api/lumeron/products`, `/api/lumeron/customers` e `/api/lumeron/sales`. O caminho anterior `/api/nextor` continua disponível para compatibilidade.

## Cuidados

Não versionar `.env`, certificados digitais, credenciais de CSC, arquivos fiscais, sessões ou bancos. Antes de emissão real, validar por empresa/UF, cenários tributários, fila fiscal, recuperação de falhas, backup e impressão.

## Histórico

O protótipo antigo de motor NFC-e próprio foi arquivado em `archive/nextor-native-nfce-2026-10-02.zip`; o plano técnico atual usa ACBrLib. O nome dos diretórios históricos não deve ser alterado sem plano de migração.
