# Lumeron Mobile — entrega técnica na branch de desenvolvimento

**Branch:** `feature/lumeron-mobile`  
**Base:** `develop`  
**Data:** 26/10/2026  
**Estado:** código implementado no GitHub; testes de CI/dispositivos e publicação dependem de validação.

## Recursos implementados

- `public/manifest.webmanifest`: manifesto PWA com escopo e origem relativos, título, idioma, cores e modo standalone. O arquivo estático é servido pela instância/subdomínio; o navegador não compartilha uma PWA com outro CNPJ.
- `public/pwa/icons/`: PNGs quadrados reais 192×192, 512×512, maskable 512×512 e Apple touch 180×180 gerados da marca oficial com `docs/mobile/generate-icons.py`. A marca original não foi alterada. Os SVGs continuam disponíveis como alternativa. A instalação e a qualidade visual em Android/iOS precisam de teste real.
- `public/sw.js`: registra apenas página offline genérica e pública no Cache Storage; páginas HTML autenticadas e documentos/solicitações ficam em rede, sem API ou armazenamento de dados do ERP. Não faz reenvio de vendas, escrituração, NF-e/NFC-e nem operações financeiras.
- `public/pwa/offline.html`: comunicação de indisponibilidade sem informações comerciais.
- `public/js/mobile-pwa.js`: registro em HTTPS/localhost, sincronização de menu acessível, fechamento de navegação, adaptação progressiva de tabelas de consulta com rótulos e seleção em massa existente.
- `public/css/mobile.css`: barra inferior de navegação baseada em permissões comerciais, formulários e alvos táteis, rolagem segura, listagens em cartões, modais, áreas com safe-area e preservação de impressão.
- `public/css/mobile-pdv.css`: grade de atalhos do PDV adaptada a toque e conteúdo menor, sem alterar regras do caixa/fiscal.
- Layouts `app.blade.php`, `pdv.blade.php`, `auth/login.blade.php`: manifesto, links para ícones e registro da PWA.
- `resources/views/partials/mobile-navigation.blade.php`: navegação com permissões do usuário e módulos licenciados idênticos aos do layout principal.
- Listas de produtos, serviços, clientes, vendas, compras e estoque: cartões mobile opcionais, sem converter tabelas de edição, fiscais ou PDV.
- `tests/Feature/LumeronMobilePwaTest.php`: testes para manifesto, cache de informações sensíveis, assets, login e layout administrativo.

## Regra de segurança

O PWA é **online-first**. O Service Worker intercepta somente navegação GET e tenta a rede; caso não haja conexão, oferece página pública sem dados da empresa. Não armazena dados privados no Cache Storage. Endpoints comerciais/fiscais, permissões, idempotência, CSRF, identidade da instalação e licença continuam aplicados do lado do servidor. A emissão contingente NFC-e existente continua sendo uma regra fiscal do backend, não um mecanismo de sincronização PWA.

## Verificações ainda necessárias

1. Executar `php artisan test --filter=LumeronMobilePwaTest`, depois toda a suíte; verificar CI e corrigir falhas observadas.
2. Testar navegação e ações reais no Chrome Android, Safari iOS e Edge/Chrome desktop, incluindo instalação e abertura standalone.
3. Conferir manifest em `application/manifest+json`/JSON e Service Worker em `text/javascript` no servidor HTTPS (mime types corretos).
4. Verificar **Application > Cache Storage**: só `/pwa/offline.html`; o login, produtos, XML, PDF, fiscal, vendas e API jamais aparecem no cache.
5. Testar as telas em 320, 360, 375, 390, 430, 768, 1024 e 1366px, rotação, zoom, teclado virtual e barras de ações com botões persistentes.
6. Exercitar fluxo completo de PDV, orçamento, estoque, financeiro e fiscal online — criação, validações, correções, erros, impressão e bloqueios.
7. Verificar a experiência da PWA instalada em navegadores que exigem PNG quadrado 192/512 e `maskable`. Arquivos e dimensões estão versionados e testados, mas **não substituem a verificação prática de instalação**.
8. Validar que o mecanismo de atualização do SW não interrompa processos de edição.
9. Garantir que mudanças nesta branch não impactem main, develop, control-center, instalações clientes ou VPS sem autorização.

## Como conferir localmente

```bash
git fetch origin
git switch feature/lumeron-mobile
git pull --ff-only origin feature/lumeron-mobile
composer install
php artisan optimize:clear
php artisan test --filter=LumeronMobilePwaTest
php artisan test
php artisan serve --host=127.0.0.1 --port=8001
```

Abra `http://127.0.0.1:8001`; para instalação externa use **HTTPS**. Testes de usuário/de produção ficam bloqueados até validação de fluxos e aceite de release.

## CI observado em 26/10/2026
- Workflow **Lumeron Mobile PWA** concluído com sucesso na revisão `550b2e6`: validação do manifesto, sintaxe de JavaScript e testes de interface/PWA (incluindo dimensões reais dos ícones PNG).
- Workflows de isolamento por CNPJ e regressão RTC/NFC-e também concluíram com sucesso nessa revisão.
- A suíte PHPUnit **completa** da primeira revisão reportou 4 falhas em `FiscalConciseLayoutTest` e `NfeEditorTest`. Esses arquivos fiscais não foram modificados pelo mobile; não houve execução independente da `develop` para atribuir oficialmente a origem das falhas. Não corrigir módulos fiscais fora do escopo sem revisão própria.
- Execução física Chrome Android/Safari iOS e instalação standalone ainda não realizadas.

**Não feito aqui:** publicação/instalação na VPS, modificação de banco, migrações, teste visual em aparelho físico, verificação de SEFAZ, provas de installability no navegador.
