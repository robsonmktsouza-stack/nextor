# Integração com o Lumeron Control — agente da instalação (opt-in)

Este documento pertence ao **ERP**, branch `develop`. O painel central é uma **aplicação Laravel separada**, mantida temporariamente na branch `control-center` do mesmo repositório GitHub até ter repositório independente. Jamais mesclar a árvore da branch control-center na develop/main.

## Segurança e isolamento

Uma instalação do Lumeron = um CNPJ. O serviço central recebe só **CNPJ e versão** do ERP, e devolve status/plano/módulos/validade autenticados via HMAC. Não transmite vendas, clientes do comércio, A1, XML, CSC ou dados fiscais. Cada CNPJ recebe UUID e segredo gerado separadamente no painel.

O agente está **desativado por padrão**. A simples atualização do ERP não muda acessos nem bloqueia clientes. Para testar localmente use configuração de testes sem credenciais reais.

## Configuração por instância (arquivo .env privado, nunca Git)

```ini
LUMERON_CONTROL_ENABLED=true
LUMERON_CONTROL_URL=https://admin.seu-dominio-real.com.br
LUMERON_CONTROL_INSTANCE_ID=<UUID fornecido no painel>
LUMERON_CONTROL_SECRET=<chave hexadecimal exibida uma vez>
LUMERON_CONTROL_GRACE_HOURS=72
LUMERON_INSTANCE_CNPJ=<CNPJ da instalação com 14 dígitos>
```

No servidor real, usar HTTPS. Em `APP_ENV=local` é permitido HTTP para testes no Laragon. Após configurar: `php artisan optimize:clear`, `php artisan migrate --force` **somente na instância autorizada**, e `php artisan lumeron:license-sync`.

O comando usa assinatura HMAC por requisição (UUID, timestamp Unix, nonce único e SHA-256 do corpo), confere a assinatura da resposta com o segredo da instância, UUID/CNPJ/status/horário e guarda a última resposta assinada no próprio banco da instalação. Solicitações repetidas são rejeitadas pelo servidor.

A interface usa cache de 5 minutos. Sem rede, aceita a última licença validada por até 72 horas (configurável); passado esse limite, **a interface comercial requer regularização**. Falhas de comunicação nunca devem fabricar estado ativo. Consultas, cancelamentos e regularizações fiscais permanecem liberadas às permissões normais do ERP.

## Efeitos de status

- `active`: permite módulos comercialmente contratados, sempre respeitando as permissões do usuário.
- `suspended` e `expired`: impedem acessos comerciais na interface e direcionam para mensagem de licença; **não apagam dados**.
- `unverified`: quando ainda não existe verificação válida ou se ultrapassou a tolerância offline, restringe acesso comercial, preservando fiscal.
- `disabled`: não aplica política central (instalações anteriores e ambiente local padrão).

**Atenção:** a exigência remota só funciona nas instalações que receberam este código em uma versão futura, foram migradas e configuradas por autorização expressa. A `main`/`v1.0.0` continua intocada. O painel **não** instala usuários/servidores nem publica releases automaticamente.

Não ativar em cliente real sem confirmar a URL, backup, segurança, autorização do proprietário e política de continuidade operacional do estabelecimento.
