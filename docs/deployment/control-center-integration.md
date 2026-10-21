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

A porta deve ser ajustada para o endereço real do Control. O comando não exibe nem pede credenciais e não acessa a interface do ERP: executa a identidade read-only, compara com a conta CNPJ já cadastrada, grava `storage/app/private/lumeron-control.json` com permissão restrita e registra auditoria. Não sobrescreve vínculo de outra empresa. Para novos deploys, o gerenciador de implantação deve executar essa operação internamente, após instalar/migrar o ERP, quando possuir acesso privilegiado a ambas as aplicações; não há provisionamento remoto automático implementado.

A integração **continua desativada para instalações antigas que não receberam o arquivo**. A instalação passa a aplicar licença central quando seu backend lê o vínculo privado, sem `.env` nem participação do usuário. Os campos `LUMERON_CONTROL_*` legados continuam suportados somente para compatibilidade; não orientar novos clientes a copiá-los manualmente.

Antes do primeiro vínculo, a instalação deve usar uma versão `develop` aprovada para teste, ter o CNPJ empresarial preenchido e a migração `central_license_states` aplicada. Isso é trabalho do operador de deploy, não uma etapa do cadastro do cliente. Instalações `main`/`v1.0.0` ainda não possuem o agente.


O comando usa assinatura HMAC por requisição (UUID, timestamp Unix, nonce único e SHA-256 do corpo), confere a assinatura da resposta com o segredo da instância, UUID/CNPJ/status/horário e guarda a última resposta assinada no próprio banco da instalação. Solicitações repetidas são rejeitadas pelo servidor.

A interface usa cache de 5 minutos. Sem rede, aceita a última licença validada por até 72 horas (configurável); passado esse limite, **a interface comercial requer regularização**. Falhas de comunicação nunca devem fabricar estado ativo. Consultas, cancelamentos e regularizações fiscais permanecem liberadas às permissões normais do ERP.

## Efeitos de status

- `active`: permite módulos comercialmente contratados, sempre respeitando as permissões do usuário.
- `suspended` e `expired`: impedem acessos comerciais na interface e direcionam para mensagem de licença; **não apagam dados**.
- `unverified`: quando ainda não existe verificação válida ou se ultrapassou a tolerância offline, restringe acesso comercial, preservando fiscal.
- `disabled`: não aplica política central (instalações anteriores e ambiente local padrão).

**Atenção:** a exigência remota só funciona nas instalações que receberam este código em uma versão futura, foram migradas e configuradas por autorização expressa. A `main`/`v1.0.0` continua intocada. O painel **não** instala usuários/servidores nem publica releases automaticamente.

Não ativar em cliente real sem confirmar a URL, backup, segurança, autorização do proprietário e política de continuidade operacional do estabelecimento.
