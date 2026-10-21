# Lumeron ERP — primeiro acesso gerenciado

## Contrato com o Lumeron Control

A empresa é cadastrada apenas uma vez na interface administrativa do
Control, com CNPJ, subdomínio, contato, e-mail, login e plano. O
**provisionador da VPS** consome uma tarefa confiável e cria uma instância
ERP independente no domínio `https://<subdominio>.<dominio-base>`.
O usuário final só vê o endereço, seu usuário e um convite de senha.

`deploy/instances/provision.sh --managed` é chamado exclusivamente pelo
agente autorizado do Control no próprio servidor. A opção verifica
CNPJ/domínio, cria banco MySQL e usuário Linux privados e executa
`erp:bootstrap-managed` depois das migrations. Esse comando:
- verifica que o CNPJ solicitado corresponde à identidade fixa do
  ambiente e que a URL pública é a daquela instalação;
- cadastra a razão social/CNPJ básicos do emitente para o vínculo HMAC;
- cria um único administrador ERP com **senha aleatória desconhecida**;
- gera token Laravel padrão para definição da senha pelo usuário;
- guarda a URL de primeiro acesso temporariamente em arquivo privado
  de permissão 0600, lido e eliminado pelo provisionador após envio.
- rejeita instalações que já possuam usuários incompatíveis, impedindo
  que um cadastro do Control substitua senhas de outra empresa.

O mesmo instalador chama `control:bind-local` de dentro da
infraestrutura, sem solicitar código técnico ao usuário. O agente
responsável envia o convite exclusivamente pelo SMTP real configurado no
Control e somente declara a tarefa concluída após receber resposta do
ERP e do servidor de e-mail. Em caso de falha, o cliente não é marcado
como pronto; o banco instalado não é eliminado automaticamente.

## Pré-requisitos de produção

- Domínio do cliente aponta para a VPS via DNS curinga.
- Certificado TLS curinga que cubra os subdomínios, informado ao
  provisionador como `--tls-cert-name` com o nome do diretório LetsEncrypt.
- Fonte ERP limpa, com `composer.lock` versionado, PHP CLI/FPM, Nginx,
  MySQL, composer, rsync, runuser, TLS e banco administrativo com
  privilégios delimitados.
- Lumeron Control confiável instalado na mesma VPS e com
  `control:provision-accounts` autorizado por worker separado.
- SMTP real no Control. Sem SMTP não há convite e a tarefa não é
  declarada pronta; o Laravel ERP não envia e-mail nem imprime tokens.
- O certificado fiscal A1/CSC e liberação SEFAZ **não** são provisionados
  automaticamente. O ERP pode ser usado nos módulos não fiscais
  habilitados; emissões fiscais exigem configuração e homologação.

Nenhuma migração fiscal ou dados do ERP anterior é compartilhada com
outra empresa. Não habilitar o serviço privilegiado no servidor antes
de revisar permissões, DNS, certificados e a execução piloto ponta a
ponta.
