# Integração com o Lumeron Control — agente da instalação (opt-in)

Este documento pertence ao **ERP**, branch `develop`. O painel central é uma **aplicação Laravel separada**, mantida temporariamente na branch `control-center` do mesmo repositório GitHub até ter repositório independente. Jamais mesclar a árvore da branch control-center na develop/main.

## Segurança e isolamento

Uma instalação do Lumeron = um CNPJ. O serviço central recebe só **CNPJ e versão** do ERP, e devolve status/plano/módulos/validade autenticados via HMAC. Não transmite vendas, clientes do comércio, A1, XML, CSC ou dados fiscais. Cada CNPJ recebe UUID e segredo gerado separadamente no painel.

O agente está **desativado por padrão**. A simples atualização do ERP não muda acessos nem bloqueia clientes. Para testar localmente use configuração de testes sem credenciais reais.

## Novo vínculo automático, sem acesso do cliente ao ERP

**Decisão permanente de 21/10/2026:** cadastro, ativação, suspensão e renovação são feitos só no Lumeron Control; nenhum cliente precisa confirmar no ERP, copiar UUID/chave ou configurar `.env`.

A empresa existente no ERP é identificada pelo **CNPJ já cadastrado** em `company_settings.document`, confirmado localmente pelo comando read-only:

```bash
php artisan lumeron:license-identity
```

O operador do serviço central executa **uma vez por instalação existente** a rotina de infraestrutura:

```powershell
cd C:\laragon\www\lumeron-control
php artisan control:bind-local "C:\laragon\www\nextor" --url=http://127.0.0.1:8000
```

A porta deve ser ajustada para o endereço real do Control. O comando não exibe nem pede credenciais e não acessa a interface do ERP: executa a identidade read-only, compara com a conta CNPJ já cadastrada, verifica a disponibilidade do Control, grava `storage/app/private/lumeron-control.json` com permissão restrita e exige **sincronização HMAC online comprovada** antes de registrar auditoria. Na falha, desfaz o novo vínculo (ou restaura o arquivo anterior), evitando bloquear a aplicação por erro de configuração. Não sobrescreve vínculo de outra empresa. Para novos deploys, o gerenciador de implantação deve executar essa operação internamente, após instalar/migrar o ERP, quando possuir acesso privilegiado a ambas as aplicações; não há provisionamento remoto automático implementado.

A integração **continua desativada para instalações antigas que não receberam o arquivo**. A instalação passa a aplicar licença central quando seu backend lê o vínculo privado, sem `.env` nem participação do usuário. Os campos `LUMERON_CONTROL_*` legados continuam suportados somente para compatibilidade; não orientar novos clientes a copiá-los manualmente.

Antes do primeiro vínculo, a instalação deve usar uma versão `develop` aprovada para teste, ter o CNPJ empresarial preenchido e a migração `central_license_states` aplicada. Isso é trabalho do operador de deploy, não uma etapa do cadastro do cliente. Instalações `main`/`v1.0.0` ainda não possuem o agente.


O comando usa assinatura HMAC por requisição (UUID, timestamp Unix, nonce único e SHA-256 do corpo), confere a assinatura da resposta com o segredo da instância, UUID/CNPJ/status/horário e guarda a última resposta assinada no próprio banco da instalação. Solicitações repetidas são rejeitadas pelo servidor.

A interface usa cache de 5 minutos. Sem rede, aceita a última licença validada por até 72 horas (configurável); passado esse limite, **a interface comercial requer regularização**. Falhas de comunicação nunca devem fabricar estado ativo. Consultas, cancelamentos e regularizações fiscais permanecem liberadas às permissões normais do ERP.

## Efeitos de status — modo de consulta (21/10/2026)

A suspensão não expulsa o cliente nem mostra uma tela de licença cobrindo o ERP. Em vez disso, o sistema mantém dashboard, buscas, listagens, detalhes, relatórios, impressões e downloads de dados existentes, conforme as permissões do próprio usuário. Nos layouts `app.blade.php` e `pdv.blade.php`, o **chip discreto no cabeçalho** é incluído por `partials/commercial-readonly.blade.php`, com estilos em `public/css/commercial-readonly.css` e interação/modal em `public/js/commercial-readonly.js`. Não há banner fixo sobre o conteúdo; o chip pode ser acionado para consultar o motivo. Ações de escrita são acinzentadas (não recebem atributo `disabled`, para ainda exibirem o modal), enquanto consultas, impressão e exportação permanecem normalmente disponíveis.

- `active`: acesso operacional completo para os módulos contratados, sujeito às permissões normais.
- `suspended`: consultas disponíveis; `POST/PUT/PATCH/DELETE` comerciais bloqueados em `RequireCentralLicense` no backend.
- `expired`: mesmas restrições comerciais, com mensagem específica de renovação.
- `unverified`: após o limite de tolerância offline, consultas disponíveis e escrita comercial suspensa por motivo técnico (não afirmar inadimplência).
- `disabled`: instalação ainda não vinculada; segue sem licença central, mantendo a política anterior.
- Desativar o bloqueio no painel não exige logout ou novo cadastro. As alterações entram em vigor após a próxima atualização assinada do cache (normalmente até cinco minutos, sujeita à conectividade).

O middleware registra o estado da licença nos atributos da requisição para os layouts, sem publicar chaves. Um formulário comum bloqueado retorna à tela com mensagem `warning`, sem realizar a ação. Chamadas AJAX/API protegidas recebem **HTTP 423** com `code=LUMERON_LICENSE_READ_ONLY`. A interface mostra o modal explicativo; o JavaScript NÃO é o mecanismo de segurança. A rota antiga `/license-status` redireciona usuários suspensos ao ERP.

**Exceções fiscais explícitas**, além dos `GET/HEAD` de consulta: download de XML autorizado, consulta de NFC-e pendente, cancelamento, transmissão de contingência de documento pré-existente, inutilização legal e recuperação de XML autorizado. As exceções são mantidas por nome em `CommercialLicensePolicy::CONTINUITY_ROUTES`; **não** liberar globalmente `fiscal/*`, pois isso permitiria novas emissões, edição de tabelas ou geração de dados durante a suspensão. As permissões `permission:fiscal` continuam obrigatórias.

Não permitir alteração por requisição HTTP direta ou AJAX, nem excluir banco, histórico, estoque, XMLs, certificados ou autorização fiscal. Background jobs previamente agendados exigem revisão separada antes de implantação comercial, pois o middleware HTTP não controla workers e filas.

**Testar em homologação:** suspender no painel, aguardar o cache, conferir `/products`, `/sales`, `/finance`, POST comercial 423, download/regularização fiscal autorizados e reativação. Fazer backup antes de atualizar um ambiente com dados reais; somente a branch `develop` foi modificada.

**Atenção:** a exigência remota só funciona nas instalações que receberam este código em uma versão futura, foram migradas e configuradas por autorização expressa. A `main`/`v1.0.0` continua intocada. O painel **não** instala usuários/servidores nem publica releases automaticamente.

Não ativar em cliente real sem confirmar a URL, backup, segurança, autorização do proprietário e política de continuidade operacional do estabelecimento.

## Planos comerciais definem acesso por módulo (21/10/2026)

- A licença assinada aceita qualquer código de plano válido `[a-z][a-z0-9_-]{2,29}`; não limitar a `standard/advanced/full`. O array de módulos assinado pelo Control é a autoridade comercial. Nunca usar o antigo `companies.modules` para conceder direitos.
- `CommercialModuleAccess` vincula PDV/Vendas a `pdv`, Produtos/Estoque a `products`, Financeiro a `finance`, Serviços a `services` e Fiscal a `fiscal`. O middleware `RequireCentralLicense` impõe HTTP **403** em telas/ações não contratadas (JSON usa `LUMERON_MODULE_UNAVAILABLE`); ocultar os links no layout, incluindo atalhos do cabeçalho. Credenciais API (`api/lumeron` e `api/nextor`) também não burlam essas licenças.
- Exceções de regularização fiscal são taxativas, inclusive consulta de notas existentes, downloads legais e cancelamento/transmissão de contingência. **Nunca** liberar `fiscal/*` indiscriminadamente ou bloquear obrigação fiscal legal por não contratação. Uma opção de Regularizações fiscais fica no menu de usuário quando a aba Fiscal completa não foi contratada.
- Instalações sem vínculo continuam com o comportamento antigo; suspensas, vencidas ou não verificadas após tolerância mantêm consulta e bloqueiam gravação, independentemente dos módulos, conforme política anterior. Permissões próprias dos usuários nunca são ampliadas por um plano.
- Antes da atualização do ERP, executar a migration `2026_10_22_000001_preserve_legacy_entitlements_as_plans.php` no **Control** para impedir a perda de autorizações individuais antigas. Não tocar os documentos XML, certificados, CNPJ, estoque, bancos, filas, `main` ou `v1.0.0`.

O cadastro compartilhado **Clientes** não tem módulo comercial próprio no catálogo, portanto segue disponível conforme permissões do usuário mesmo sem o módulo PDV. Não inventar uma restrição de Clientes baseada em PDV; Vendas/PDV e seus fluxos seguem bloqueados quando não contratados.

## Atualização e processos automáticos (21/10/2026)

O agente ERP mantém um cache curto de **60 segundos** por instalação. Assim, alterações
de plano, suspensão e renovação passam a ser percebidas automaticamente na próxima
requisição após o cache expirar, desde que a central esteja alcançável. A
sincronização manual `php artisan lumeron:license-sync` continua disponível.
Sem rede, vale a tolerância offline contratada; não prometer efeito instantâneo
para uma instalação desconectada. A validade da licença é reavaliada a cada acesso
pelo calendário local, mesmo com resposta ativa em cache/offline.

O agendador `finance:generate-recurring` chama
`CommercialBackgroundAccess::allows('finance')` antes de criar qualquer
lançamento, porque comandos Artisan não passam pelo middleware HTTP. Em licença
suspensa, vencida, sem verificação, ou sem Financeiro contratado, não gera novas
recorrências; após reativação, ocorrências vencidas podem ser geradas pelo
processamento idempotente existente. Instalações não vinculadas continuam sem
controle central para compatibilidade.

Webhooks pendentes são entregas de eventos históricos, e jobs fiscais já
enfileirados tratam fatos/documentos anteriores: NÃO devem ser descartados
indiscriminadamente por uma suspensão comercial. Consultas, cancelamentos,
contingência e DANFE de XML autorizado permanecem disponíveis. Antes de adotar
bloqueio de nova emissão em workers, deve-se distinguir operações fiscais
pendentes obrigatórias de novas emissões e criar retomada durável sem perda da
fila ou do documento. Não afirmar que o middleware HTTP controla tais workers.
