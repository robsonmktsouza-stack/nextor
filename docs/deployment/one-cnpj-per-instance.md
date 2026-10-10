# Lumeron na VPS: uma instalação por CNPJ

## Contrato de isolamento

**1 CNPJ = 1 contratação = 1 aplicação Laravel própria**, mesmo que um cliente possua duas empresas. Um único código-fonte versionado pode originar todas as instalações, mas o ambiente de execução não é compartilhado.

Exemplo com cinco clientes:

| Cliente | Contratos | Instalações |
| --- | ---: | --- |
| A | 2 | cliente_a_1, cliente_a_2 |
| B | 1 | cliente_b_1 |
| C | 1 | cliente_c_1 |
| D | 1 | cliente_d_1 |
| E | 2 | cliente_e_1, cliente_e_2 |
| **Total** | **7** | **7** |

Cada instalação recebe: URL/subdomínio, usuário Linux `lum_{id}`, pool PHP-FPM exclusivo, banco e usuário MySQL `lum_{id}`, `APP_KEY`, cookies, cache, sessões, armazenamento privado, arquivos fiscais, certificado A1 e CSC próprios, além da fila e do worker `lumeron-fiscal-{id}` e de um agendador `lumeron-schedule-{id}.timer` próprios. O cliente A nunca vê nem altera o banco da segunda empresa pelo primeiro ambiente.

**Não é um SaaS multiempresa com banco compartilhado.** A empresa cadastrada no ERP deve corresponder a `LUMERON_INSTANCE_CNPJ` do ambiente. A UI recusa CNPJ diferente, e a NFC-e não deve ser transmitida com um emitente que não coincida com a instalação.

## Preparação obrigatória

Esta documentação descreve scripts **entregues ao GitHub**, não uma VPS já configurada. Execute somente após conferência em ambiente de teste e com credenciais e DNS reais.

- VPS Linux administrada, Ubuntu/Debian ou compatível, Nginx, MySQL/MariaDB, PHP 8.3 ou 8.4 CLI + FPM (extensões Laravel e FFI conforme ACBr), Composer, `rsync`, `mysqldump`, `openssl`, `runuser`, systemd.
- Repositório em uma cópia local confiável na VPS, por exemplo `/opt/lumeron/source`, na versão/commit que será disponibilizado a todos os clientes. O código-fonte não deve conter certificados, XML de clientes ou arquivos `.env`.
- **Bloqueio atual:** ainda falta gerar e versionar o arquivo `composer.lock` no repositório. No ambiente de desenvolvimento, execute `composer update`, teste a instalação e envie o `composer.lock` ao GitHub. **Não execute `composer update` diretamente na VPS dos clientes.** Os scripts se recusam a implantar sem lock.
- Configure no MySQL um arquivo de acesso administrativo `/root/.my.cnf` com modo `0600`; os scripts geram usuários de menor privilégio, restritos ao banco da própria instalação.
- Cada subdomínio deve apontar para a VPS, ter certificado TLS válido em `/etc/letsencrypt/live/{dominio}/`, e não pode ser reutilizado por outro CNPJ. Obtenha TLS **antes** do provisionamento com seu método operacional (certbot/DNS ou outro).
- Garanta cópias externas cifradas do banco **e** dos arquivos privados, incluindo XMLs, certificados e a própria `APP_KEY`. O backup local criado em atualizações não substitui backup externo testado.
- Instale e valide separadamente a **ACBrLibNFe para Linux**, seus schemas e dependências, pela arquitetura exata da VPS. A DLL do Windows usada no Laragon não funciona no Linux. O repositório **não** fornece a biblioteca licenciada.
- Reserve capacidade de CPU/RAM para sete pools FPM, workers e chamadas ACBr. Ajuste `pm.max_children` após medir o uso real.

## Criar a primeira instalação

Exemplo ilustrativo, substitua o CNPJ e domínio pelos reais:

```bash
sudo bash deploy/instances/provision.sh \
  --id academia_ba \
  --cnpj 00000000000000 \
  --domain academia.seudominio.com.br \
  --source /opt/lumeron/source
```

O comando acima é uma **simulação**: não modifica a VPS. Após conferir, execute novamente acrescentando `--apply`.

Em caso de sucesso, são criados:

```text
/srv/lumeron/instances/academia_ba/
  current -> releases/2026.../
  releases/2026.../        aplicação Laravel versionada
  shared/.env              APP_KEY e conexão MySQL somente da academia
  shared/storage/          sessões, cache, XMLs e certificados particulares
  shared/acbr/             configuração ACBr particular
  uploads/                 imagens públicas particulares
/etc/php/8.3/fpm/pool.d/lumeron-academia_ba.conf
/etc/nginx/sites-available/lumeron-academia_ba.conf
/etc/systemd/system/lumeron-fiscal-academia_ba.service
/etc/systemd/system/lumeron-schedule-academia_ba.{service,timer}
/root/lumeron/academia_ba.mysql.cnf
```

O script cria o banco e aplica migrações, mas **não** cria o administrador, não instala a biblioteca ACBr, não insere CSC/certificado nem liga a fila fiscal automaticamente.

Em seguida, no servidor:

```bash
cd /srv/lumeron/instances/academia_ba/current
sudo runuser -u lum_academia_ba -- /usr/bin/php8.3 artisan erp:make-admin
```

No navegador, informe o CNPJ contratado em **Configurações → Dados da empresa**; depois configure CRT, IE, UF, certificado A1, ambiente, CSC e tributação dos produtos nas telas existentes.

Configure somente esta instalação em `shared/.env` com os caminhos reais para `ACBr_NFE_LIBRARY_PATH` (biblioteca Linux), `ACBr_NFE_SCHEMAS_PATH` e `ACBr_NFE_CONFIG_PATH`. Preserve o dono do arquivo e seu modo `0600`. Faça `php artisan optimize:clear` e `php artisan optimize` como o usuário da instância para carregar a mudança.

**Antes de emitir:**

```bash
cd /srv/lumeron/instances/academia_ba/current
sudo runuser -u lum_academia_ba -- /usr/bin/php8.3 artisan lumeron:instance-check --fiscal
sudo runuser -u lum_academia_ba -- /usr/bin/php8.3 artisan acbr:nfe-doctor
sudo runuser -u lum_academia_ba -- /usr/bin/php8.3 artisan acbr:nfce-check
```

Confirme os nomes exatos dos comandos ACBr com `php artisan list acbr`. Teste autenticação, produtos, venda, impressão, XML, comunicação e autorização da UF em homologação. **A verificação local não autoriza automaticamente emissão em produção**.

Após a validação fiscal e definição de monitoramento:

```bash
sudo systemctl enable --now lumeron-fiscal-academia_ba
sudo systemctl status lumeron-fiscal-academia_ba
```

A fila fiscal é individual e inclui `fiscal,default`, sem depender de um terminal manual.

Para habilitar as rotinas agendadas próprias do cliente (financeiro, webhooks e exportação contábil), após conferir as configurações:

```bash
sudo systemctl enable --now lumeron-schedule-academia_ba.timer
sudo systemctl status lumeron-schedule-academia_ba.timer
```

Não configure um cron global adicional para a mesma instalação; isso poderia executar as rotinas em duplicidade. Em caso de nota com estado indeterminado, consulte a SEFAZ pela chave antes de qualquer retransmissão.

## Atualizar apenas um CNPJ

Primeiro atualize a cópia confiável do repositório na VPS até o commit aprovado. Depois:

```bash
sudo bash deploy/instances/update.sh --id academia_ba --source /opt/lumeron/source
sudo bash deploy/instances/update.sh --id academia_ba --source /opt/lumeron/source --apply
```

O script prepara o novo release, instala as dependências do lock, coloca apenas aquela empresa em manutenção, interrompe o worker e o timer daquela empresa se estiverem ativos, realiza backup MySQL local, aplica migrações e troca o symlink `current` atomicamente. O código anterior permanece disponível. **Migrações destrutivas exigem janela de manutenção e plano de restauração; trocar só o symlink não reverte o banco.** Se a atualização falhar, consulte os logs antes de tentar novamente.

As demais empresas continuam no release anterior até serem atualizadas individualmente. O código-fonte é único, mas as implantações e a programação de atualização são independentes.

## Conferir todas as instalações

```bash
sudo bash deploy/instances/list.sh
sudo bash deploy/instances/list.sh --check
```

Essa é a primeira camada de administração central **pela VPS**. Um painel web comercial, cobrança/assinatura, criação pelo navegador, atualizações agendadas e supervisão central de erros ainda **não** fazem parte deste pacote.

## Limites e próximos passos

1. O código fiscal ainda tem validações específicas de BA em `NFCePreflightService`, `NFCeTransmissionService` e na inutilização. As configurações de grupos e regras já podem contemplar outras UFs, mas isso **não** equivale a homologar NFC-e em SP ou outros estados.
2. A ACBr usada na VPS depende da biblioteca Linux e do comando de diagnóstico. O fluxo testado no Laragon/Windows não prova por si só que a chamada FFI Linux vai funcionar.
3. O cadastro fiscal deve ser aprovado por cenário (roupas, suplementos, ST, descontos e formas de pagamento). O piloto assistido não elimina requisitos tributários.
4. Ainda são necessários monitoramento externo do worker, backup **automático e externo**, teste de restauração, testes de recuperação após queda e documentação da impressora local para operação contínua.
5. Para registrar um novo cliente, gere **outra** instância com outro ID, CNPJ e domínio. **Nunca** copie um `.env`, certificado, CSC, storage ou banco de outro contratante.
