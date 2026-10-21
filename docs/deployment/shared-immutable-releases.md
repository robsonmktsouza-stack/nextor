# Lumeron SaaS — releases imutáveis deduplicados (Ubuntu)

**Escopo:** apenas ERP, branch develop; não altera Control, main nem a VPS.
Implementação: deploy/instances/{release,approve,lib,provision,update,list,prune}.sh.

## Arquitetura efetiva

- Banco MySQL e usuário MySQL únicos por CNPJ (lum_<id>), usuário Unix diferente, pool PHP-FPM e socket separados. Não é multitenancy de banco compartilhado.
- Código + vendor preparados uma única vez por **SHA aprovado**, root-owned e sem permissão de escrita. As instâncias utilizam HARDLINKS do mesmo inode, portanto APP/Composer/Laravel têm caminhos físicos individuais: public/index.php, bootstrap/app.php, vendor/autoload.php e base_path() apontam sempre para o runtime de cada empresa. Não compartilhamos a raiz da aplicação via symlink.
- Dentro de cada runtime, bootstrap/cache é PRIVADO e pertence ao UID da empresa; storage e .env são symlinks para o diretório PRIVADO da própria empresa. APP_KEY, CSC, A1, XMLs, imagens, sessões, logs e cache não entram no release global.
- Hardlinks exigem code e instances no **mesmo filesystem**. O processo falha se não conseguir hardlink; não cai em cópia duplicada. Os executáveis são root-owned, não graváveis pelos usuários das empresas.
- Variáveis APP_, DB_, LUMERON_, etc. não passam do Control para o runtime; o comando Artisan por cliente roda com ambiente limpo e carrega exclusivamente seu .env. Laravel config/route/view cache são gerados dentro do bootstrap/cache e storage próprios. PHP-FPM usa clear_env=yes.
- Versões aprovadas diferentes coexistem porque cada instalação possui current -> releases/<snapshot> e previous -> releases/<snapshot>. Mudanças de código nunca ocorrem in-place. OPcache pode manter cache distinto por caminho apesar de hardlinks idênticos; não declarar ganho automático de RAM do OPcache.

Layout:

~~~text
/srv/lumeron/code/
  approved-default                      SHA aprovado para NOVAS contas
  releases/<sha>/                       fontes+vendor imutáveis root-owned
/srv/lumeron/instances/c_2/
  current -> releases/20261021-...
  previous -> releases/20261019-...
  releases/20261021-.../
    app/, vendor/, artisan              hardlinks do SHA compartilhado
    bootstrap/cache/                    exclusivo de c_2
    .env -> ../../shared/.env           APP_KEY/DB somente da empresa c_2
    storage -> ../../shared/storage     XML, arquivos, sessões, logs privados
    public/uploads -> ../../uploads    arquivos públicos só de c_2
  shared/.env                           modo 0600, lum_c_2
  shared/storage/                      modo 0700, lum_c_2
  shared/acbr/                         configuração fiscal privada
  uploads/                             isolados
/root/lumeron/c_2.mysql.cnf           credenciais backup exclusivamente root
~~~

## Aprovação e comandos (sempre simulam antes de --apply)

Apenas tag ou commit de 40 dígitos deliberadamente aprovado, composer.lock fixo.
Um push, pull, merge ou commit de develop NUNCA instala automaticamente.

~~~bash
# Preparar uma versão, sem publicar clientes:
bash deploy/instances/release.sh --source /opt/lumeron/source --approved-ref v1.2.0
sudo bash deploy/instances/release.sh --source /opt/lumeron/source --approved-ref v1.2.0 --apply

# Fixar SHA padrão de NOVOS cadastros, NÃO atualizar os existentes:
sudo bash deploy/instances/approve.sh --release SHA_COMPLETO --apply

# Provisionamento da empresa utiliza script provision.sh com --managed e
# a identidade do Control. Se não passar --release, só usa approved-default.

# Atualizar UM cliente com release preparado, backup individual e migração:
bash deploy/instances/update.sh --id c_2 --release SHA_COMPLETO
sudo bash deploy/instances/update.sh --id c_2 --release SHA_COMPLETO --apply

# Reversão EXCLUSIVAMENTE DO CÓDIGO: nunca desfaz migrations automaticamente.
sudo bash deploy/instances/update.sh --id c_2 --rollback --allow-code-only-rollback --apply

# Inventário + limpeza; padrão é read-only:
bash deploy/instances/list.sh
bash deploy/instances/prune.sh --min-age-days 30
sudo bash deploy/instances/prune.sh --min-age-days 30 --apply
~~~

**Migrações:** update.sh pára os serviços da empresa selecionada, espera trabalho
fiscal em execução, ativa modo manutenção, faz dump do banco exclusivo e executa
migrate com o usuário daquele CNPJ. Migrações precisam ser retrocompatíveis
para rollback de código; restauração automática de DB poderia perder venda ou NFC-e
autorizada. Backups EXTERNOS cifrados e restauração testada continuam obrigatórios.
Releases current e previous e código referido por qualquer snapshot são protegidos
da limpeza. Prune nunca varre bancos, XMLs, A1/CSC, uploads, storage nem backups.

**Falhas:** se uma migração falha, current não é substituído; o script retorna
o modo manutenção ao estado normal e reativa os serviços que estavam ativos.
Se uma ativação posterior falha, current retorna ao código anterior e conserva
o ponto de rollback prévio. Banco pode já ter recebido migrations; exigir auditoria.
Falha parcial de provisionamento novo exige revisão administrativa, sem limpeza
automática do banco ou de arquivos privados. Instalações legadas anteriores aos
hardlinks não são migradas automaticamente; requerem plano assistido por CNPJ.

## RAM e processos

- PHP-FPM: ondemand, clear_env=yes, max_children=6 para não reduzir capacidade
  do PDV em pico, idle_timeout=10s. O sistema ANTIGO JÁ usava ondemand, por isso
  não contabilizar economia grande de RAM ociosa FPM como novidade.
- Fiscal: serviço oneshot que processa fila e encerra; timer opt-in a cada ~30s
  após conclusão. Para NFC-e interativa/alta demanda, worker persistente
  lumeron-fiscal-fast-<id>.service, opt-in, com baixa latência. Ativar apenas
  um modo por CNPJ e validar em staging. Nenhum é ativado ao cadastrar cliente.
- Agendador: systemd oneshot por minuto, opt-in e executado no banco individual;
  zero daemon PHP permanente para clientes sem uso.
- Logs Laravel por CNPJ: driver daily, LOG_DAILY_DAYS=14. Modelo de logrotate
  para Nginx e PHP-FPM em deploy/instances/lumeron.logrotate, a instalar após
  revisão; nunca remover XML, certificados, backups e dados fiscais.

## Armazenamento — cenário ilustrativo, NÃO medição real

100 CNPJs, dois snapshots por cliente, somente duas versões distintas,
180 MiB de código+vendor por versão, 0,5 MiB de metadados por snapshot e
30 MiB de armazenamento privado por cliente (sem MySQL, XMLs de volume,
certificados ou backups).

| | Layout copiado | Layout com hardlinks |
|---|---:|---:|
| Código+vendor | 100 x 2 x 180 = 36.000 MiB | 2 x 180 = 360 MiB |
| Metadados extras | inclusos acima | 100 x 2 x 0,5 = 100 MiB |
| Dados privados | 3.000 MiB | 3.000 MiB |
| Total modelado | **39.000 MiB = 38,1 GiB** | **3.460 MiB = 3,38 GiB** |

Economia modelada ~91%, **excluindo crescimento real de MySQL, XMLs e backups**.
Quantidade de versões aprovadas diferentes e filesystem determinam ganho real.

## RAM — comparação condicional

Se 100 workers permanentes estivessem ATIVOS em clientes pouco usados e
cada worker consumisse 40 MiB (hipótese), eram ~4.000 MiB de RSS agregado;
5 persistentes restantes seriam ~200 MiB MAIS os picos transitórios dos 95
clientes em lote. Economia potencial ilustrativa ~3.800 MiB. Se os workers
antigos já estavam desligados, a economia ociosa é próxima de ZERO.
Não foi realizado benchmark de RAM, PHP-FPM, PDV ou fila com centenas de
clientes. O MySQL e OPcache também exigem medição real.

## Pendências antes de implantar (não executar nesta etapa)

Staging Ubuntu com um único filesystem para code/instances; testar permissões
Unix e FPM reais, concorrência do PDV, fila ACBr Linux/NFC-e no modo batch
e rápido, certificados por CNPJ e credenciamento. Aprovar tags/SHA, wildcard
TLS, MySQL, SMTP/control, logrotate, backup externo cifrado com restauração,
alertas e política fiscal de retenção. Testar atualização assistida de uma
instalação LEGADA separadamente. Nenhuma configuração foi aplicada na VPS.
