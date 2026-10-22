# Identificadores exclusivos de acesso

- Identificador pode ser nome, código ou CNPJ de 14 dígitos. Normalizamos letras maiúsculas e CNPJ formatado.
- Control valida com App\Support\Subdomain; índice UNIQUE existente no banco impede duplicações, inclusive simultâneas.
- Reservados como www, control, login e api, além de hífens consecutivos ou nas pontas, são recusados.
- Identificador permanece fixo após cadastro. Não se move instalação de outro CNPJ pela edição de um campo.
- Cada instalação continua tendo banco, usuários, arquivos e sessões exclusivos.
- Login público será https://lumeron.com.br/login, servido pelo ERP em modo portal, após configurar DNS/TLS/proxy.
- Nenhuma alteração é implantada automaticamente na VPS.
