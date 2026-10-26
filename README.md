# Lumeron ERP

Sistema de gestão empresarial desenvolvido em **Laravel 13**, com PDV, vendas, produtos, serviços, clientes, estoque, financeiro e interfaces fiscais.

O projeto nasceu como **Nextor**. O repositório permanece com o endereço técnico original por compatibilidade com os ambientes e integrações existentes, mas o produto e a identidade visual passam a se chamar **Lumeron**.

## Continuidade do desenvolvimento com IA

Antes de programar neste repositório com ChatGPT, Codex, Copilot, Claude ou outro agente, leia [AGENTS.md](AGENTS.md) e o [guia de contexto em docs/ai/](docs/ai/README.md). O guia registra o estado da branch, arquitetura, decisões substituídas, restrições de segurança e [handoff entre sessões](docs/ai/HANDOFF.md).

**Branches atuais:** `main` contém a base estável aprovada e `develop` recebe as próximas implementações. A antiga `feature/acbr-nfe-bootstrap` originou a primeira promoção. Todo agente deve conferir branch/commit reais e ler a [política de releases](docs/deployment/versioning-and-release-policy.md) antes de trabalhar. A documentação está no GitHub, mas não entra automaticamente em todos os chats.

## Versões, desenvolvimento contínuo e produção

**Política permanente aprovada em 20/10/2026:** o piloto NFC-e e futuros clientes recebem **versões estáveis individualmente aprovadas**, enquanto o desenvolvimento de novos módulos continua sem afetar a produção. Push/merge na branch de desenvolvimento **não implanta cliente automaticamente**. Os detalhes obrigatórios de `main`, `develop`, `feature/*`, `hotfix/*`, tags `vX.Y.Z`, staging, backup, rollback e deploy por CNPJ estão em **[Política de versões e publicação](docs/deployment/versioning-and-release-policy.md)**.

As branches `main`/`develop` foram separadas e o `composer.lock` está versionado; a CI principal cobre as duas branches. **Ainda é necessária infraestrutura de hospedagem configurada e aprovada antes do primeiro deploy**; a existência de scripts não significa cliente instalado. Outros agentes devem ler a política antes de alterar fluxos de versão/implantação.

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

## Controle central de clientes e licenças (opt-in)

O painel privado independente **Lumeron Control** está na branch isolada `control-center` deste GitHub (não mesclar a árvore dessa branch no ERP). A integração HMAC entrou na `develop`. O vínculo agora é criado internamente pelo operador de implantação em storage privado, **sem copiar chaves, editar `.env` ou pedir confirmação ao cliente do ERP**; a instalação ainda não vinculada permanece sem controle central. Veja o [guia de integração](docs/deployment/control-center-integration.md). O cadastro no painel não cria um servidor nem altera a versão estável `main`/`v1.0.0`. Em `develop`, suspender ou vencer uma licença mantém o sistema aberto em **modo de consulta**: cadastros, históricos e relatórios continuam visíveis, enquanto gravações comerciais são bloqueadas no servidor e avisadas em modal compacto. Regularizações fiscais específicas permanecem autorizadas aos usuários com permissão; [veja a política](docs/deployment/control-center-integration.md). A `main` não foi alterada. Não ativar nem transmitir dados de clientes sem autorização por CNPJ.

## Cuidados

Não versionar `.env`, certificados digitais, credenciais de CSC, arquivos fiscais, sessões ou bancos. Antes de emissão real, validar por empresa/UF, cenários tributários, fila fiscal, recuperação de falhas, backup e impressão.

## Histórico

O protótipo antigo de motor NFC-e próprio foi arquivado em `archive/nextor-native-nfce-2026-10-02.zip`; o plano técnico atual usa ACBrLib. O nome dos diretórios históricos não deve ser alterado sem plano de migração.


### Clientes cadastrados diretamente no Lumeron Control

O provisionamento por cliente pode ser executado automaticamente
por agente privado na VPS, sem contato técnico com a empresa.
O Control registra a conta e o agente chama
`deploy/instances/provision.sh --managed`, que instala banco privado,
administrador e vínculo HMAC. O cliente recebe um convite para criar
senha em seu domínio (envio somente via SMTP do Control).
A implantação inicial desse agente, do DNS e do TLS é um trabalho
**único da infraestrutura**, não um comando executado por cliente
nem um serviço que o GitHub ativa automaticamente.
Não utilizar um banco compartilhado para as instalações ERP.
Consulte `docs/deployment/managed-first-access.md`.


## Hospedagem SaaS com código deduplicado (develop)

Releases Laravel imutáveis com arquivos executáveis hardlinked para cada CNPJ,
mas bases MySQL/usuários, APP_KEY, configuração em cache, sessão, storage,
XML, A1/CSC e runtime privados. Sem alteração de Control ou main.
A instalação de clientes usa apenas SHAs explicitamente aprovados;
nenhum git pull publica no servidor. Comandos de aprovação, deploy, atualização
por CNPJ, rollback de código e limpeza segura:
[Arquitetura de releases compartilhados](docs/deployment/shared-immutable-releases.md).

### Catálogo fiscal padrão

Referências oficiais versionadas, modelos de natureza/grupo e determinação revisada
integrada ao emissor: [instalação, cobertura e atualização](docs/fiscal/catalogo-fiscal-padrao.md).
Instalação explícita: `php artisan fiscal:catalog --install --templates` após as
migrações. Modelos novos permanecem inativos até revisão fiscal; CFOP/CEST têm
cobertura parcial declarada. Não modifica configurações de produção automaticamente.

A edição nacional `2026.10.26.1` inclui todos os CFOPs da planilha oficial vigente e
a classificação nacional CEST consolidada, com períodos e exceções por UF.
[Conteúdo nacional, fontes estaduais e atualização](docs/fiscal/catalog-national-delivery-2026-10-26.md).

Os editores NF-e/NFC-e e as inutilizações apresentam orientação dos retornos
SEFAZ a partir de 1.025 códigos oficiais, preservando o motivo e as políticas
de correção existentes: [uso e atualização](docs/fiscal/sefaz-rejections-friendly.md).
