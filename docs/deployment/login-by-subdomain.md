# Lumeron — login com subdomínio, usuário e senha

## Fluxo

Na tela **Entrar**, os campos são:

1. **Subdomínio**: identifica a instalação contratada (um CNPJ por instalação).
2. **Nome de usuário**: exclusivo do banco desta instalação.
3. **Senha**: verificada no banco exclusivo da instalação.

A tela inclui exibição/ocultação da senha, opção de não lembrar o subdomínio e o nome de usuário neste navegador e recuperação de senha pelo e-mail cadastrado. O JavaScript **nunca** salva senhas no armazenamento local. O navegador poderá oferecer o seu próprio gerenciador de senhas.

Os usuários são cadastrados em **Configurações → Usuários**. Usuários anteriores à migração continuam podendo entrar usando o e-mail no campo "Nome de usuário" até que um administrador atribua um login próprio. A migração adiciona um índice único `users.username`, permitindo identificadores repetidos em instalações diferentes, mas nunca dentro do mesmo banco.

## Testar no Laragon, sem VPS

1. Execute `git pull`, `php artisan migrate`, `php artisan optimize:clear`.
2. Opcionalmente, no `.env` local, configure `LUMERON_INSTANCE_SUBDOMAIN=autonunes` (ou o nome do ambiente de teste), **sem** configurar `LUMERON_INSTANCE_CNPJ` apenas para a demonstração local. Não use um CNPJ de cliente sem o isolamento da VPS.
3. Execute `php artisan optimize:clear` após editar o `.env`.
4. Acesse `http://localhost:8000/login`. A tela mostrará `autonunes` ou `local` se nenhum slug estiver definido.
5. Entre com o usuário da tela de Configurações. Usuários antigos sem `username` poderão usar o e-mail no mesmo campo.
6. Para criar administrador novo por CLI: `php artisan erp:make-admin` — o comando solicitará nome, e-mail, usuário e senha.
7. Teste `php artisan test --filter=LumeronLoginTest`.

## Portal central futuro

Na configuração desejada para a VPS:

- `https://login.seudominio.com.br`: portal central de apresentação do formulário (com `LUMERON_LOGIN_PORTAL=true`).
- `https://autonunes.seudominio.com.br`: instalação da empresa A, com `LUMERON_INSTANCE_SUBDOMAIN=autonunes` e `LUMERON_BASE_DOMAIN=seudominio.com.br`.
- `https://academia.seudominio.com.br`: outra instalação, com banco e credenciais diferentes.

O portal **não autentica diretamente** e **não recebe senhas**: seu JavaScript obtém um CSRF de curta duração da instalação escolhida (`GET /login/bootstrap`), envia o login com sessão própria e `credentials: include` apenas a esse host e, em caso de sucesso, redireciona para o sistema daquela empresa. A CORS permite exclusivamente a origem `https://login.seudominio.com.br`; não há curinga para outras origens. As sessões são cookies próprios de cada endereço.

`LUMERON_BASE_DOMAIN` e `LUMERON_LOGIN_PORTAL_HOST` devem refletir **domínios realmente registrados**, não uma suposição de disponibilidade. Este repositório inclui o fluxo de autenticação, mas **não instala automaticamente o portal, o DNS ou o certificado HTTPS**. Essa fase só ocorrerá quando o proprietário autorizar a implantação.

## Segurança operacional

- Cada empresa mantém usuários, senhas, e-mails de recuperação, sessões e banco separados.
- `LUMERON_INSTANCE_SUBDOMAIN` deve corresponder ao nome publicado no DNS e no Nginx. Em produção, subdomínio incorreto não autentica.
- As rotas de login possuem limite de tentativas.
- A recuperação de senha usa o token do Laravel no banco específico do CNPJ. **Configure um serviço de e-mail de verdade na VPS**: `MAIL_MAILER=log` é apenas desenvolvimento e não entrega mensagens para o usuário.
- A opção de não lembrar dados remove somente subdomínio/usuário armazenados pelo próprio Lumeron; não pode desabilitar, por garantia, recursos de preenchimento automático do navegador.
- Cada ambiente deve ter `SESSION_COOKIE` distinto e `SESSION_DOMAIN` vazio, sem cookies compartilhados entre os CNPJs.
- O nome do usuário pode ser alterado na administração, mas e-mail permanece para identificação e recuperação.
