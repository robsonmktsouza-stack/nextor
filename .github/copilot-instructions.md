# Instruções do GitHub Copilot — Lumeron ERP

**Leia primeiro `AGENTS.md` na raiz** e `docs/ai/README.md`, `PROJECT_STATE.md`, `ARCHITECTURE_AND_DECISIONS.md`, `HANDOFF.md`. Essas são as instruções persistentes do projeto e devem ser seguidas em propostas, revisões e edições.

Contexto mínimo: Lumeron ERP é o antigo Nextor, mas o código Laravel continua em `robsonmktsouza-stack/nextor` na branch de desenvolvimento `feature/acbr-nfe-bootstrap` (confirmar branch/commit antes de agir). Não confundir com `lumeronerp` WPF. O sistema usa Laravel 13/PHP ^8.3, Blade, CSS, JS e MySQL; 1 CNPJ por instalação isolada; NFC-e via ACBrLibNFe/FFI com validações da BA. Marca oficial em `public/images/lumeron/`.

Não recriar implementações fiscais em paralelo, não duplicar efeitos de vendas/estoque/financeiro, não aplicar redesign não solicitado, não implantar VPS sem autorização, não incluir dados secretos. Alterações precisam de testes relevantes e atualização do `docs/ai/HANDOFF.md` com o que foi realmente verificado.

A solicitação atual do proprietário e o código da branch ativa prevalecem sobre notas históricas. **Não alegar acesso automático ao contexto de outros chats**: consultar arquivos do repositório ao iniciar cada sessão.
