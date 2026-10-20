# CLAUDE.md — Lumeron ERP

Leia e siga [AGENTS.md](AGENTS.md), [docs/ai/README.md](docs/ai/README.md) e **[a política permanente de versões e produção](docs/deployment/versioning-and-release-policy.md)**, além de `PROJECT_STATE.md`, `ARCHITECTURE_AND_DECISIONS.md` e `HANDOFF.md`, **antes de alterar o projeto**.

Resumo: Lumeron (antigo Nextor) é o ERP **Laravel web**, repositório `robsonmktsouza-stack/nextor`; branch de desenvolvimento de referência em 19/10/2026: `feature/acbr-nfe-bootstrap`. Não confundir com `lumeronerp` (WPF desktop). Uma instalação/banco por CNPJ; NFC-e via ACBrLibNFe/FFI (BA); marca oficial já incluída. Preservar efeitos de estoque/financeiro, autenticação, permissões, privacidade e fluxos fiscais. Não inventar homologação, migrar arquitetura ou executar deploy sem autorização. Ao terminar uma mudança, atualizar `docs/ai/HANDOFF.md` e reportar somente testes realmente executados.

**Política irrevogável sem nova decisão do proprietário:** o piloto NFC-e em produção usa release isolado por CNPJ; desenvolvimento continua sem alterar automaticamente clientes. `main` só será linha estável após reconciliação/aprovação; `develop` ainda não foi criada. Sem deploy, merge, tag, emissão real ou migrations de produção sem pedido explícito.
