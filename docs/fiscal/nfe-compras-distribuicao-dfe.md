# Fiscal → Compras: recepção automática de NF-e (modelo 55)

## Entrega na branch `develop` (23/10/2026)

- Nova aba **Compras** na navegação Fiscal, com lista de NF-e emitidas para o CNPJ configurado, consulta manual, chave/fornecedor/valor, resumo ou XML completo, filtros, detalhes e download privado.
- Serviço ACBrLibNFe MT/Cdecl `NFE_DistribuicaoDFePorUltNSU` documentado, com UF e CNPJ do destinatário, certificado A1 da própria instância (mesma configuração de Ajustes → Fiscal), schemas configurados e INI temporário privado por execução.
- Cursor NSU persistido na tabela `incoming_nfe_sync_states`. Uma chamada não é repetida para redimensionar buffer da ACBr. Bloqueio compartilhado por CNPJ entre consultas manual/programada. `cStat=137` e NSU alcançado exigem intervalo mínimo de 65 minutos; `cStat=656` impõe 24 horas. Havendo backlog, próximo lote após 5 minutos, não em loop.
- `incoming_nfes` armazena notas por chave de acesso exclusiva: resumos não apagam XML completo importado anteriormente; XML de NF-e modelo 55 só é aceito quando CNPJ do destinatário confere exatamente com o da empresa. XML em `Storage::disk('local')`, pasta `fiscal/incoming-nfe/<chave>.xml` não pública, download exige `permission:fiscal`. Sem segredos de certificado em erros exibidos ou registrados.
- Importação manual de XML completo é alternativa quando o fornecedor entrega o arquivo ou o serviço ainda retorna somente resumo.
- A opção **Ativar busca automática** só funciona com A1 válido. O agendador da instância executa `fiscal:sync-incoming-nfe` a cada 15 minutos, mas a integração bloqueia consultas repetidas conforme prazo calculado. A máquina/servidor precisa executar o agendador Laravel (por exemplo, `php artisan schedule:work` em desenvolvimento).
- **Não há manifestação do destinatário automática.** A SEFAZ pode retornar apenas `resNFe` até uma ciência/confirmação formal da operação. Nunca dar confirmação fictícia de compra, nem declarar entrada de mercadorias que não chegaram. A eventual função de manifestação é uma operação fiscal distinta e deve exigir confirmação do responsável.
- A importação **não** gera estoque, contas a pagar, cadastro automático de fornecedores ou pedido de compras. Essas ações exigem conferência e conciliação com produto cadastrado para não criar mercadorias e dívidas indevidas. O XML completo exibe quantidade, descrição, código, NCM, CFOP e valores para futura recepção conferida.
- A distribuição é sujeita às janelas e restrições da SEFAZ e **não é garantia de todo o histórico** de notas da empresa.

## Preparação local (Laragon)

1. `git switch develop && git pull origin develop && php artisan migrate && php artisan optimize:clear`.
2. Confirmar CNPJ e UF da empresa em Ajustes. Enviar seu certificado digital A1 (.pfx) em **Ajustes → Fiscal**, com senha; o sistema guarda senha criptografada.
3. Certificar que `ACBr_NFE_LIBRARY_PATH`, `ACBr_NFE_SCHEMAS_PATH` e bibliotecas nativas/dependências da ACBr estão configurados para o processo PHP. O worker pode ser Windows em desenvolvimento; Linux exige lib Linux.
4. Abrir **Fiscal → Compras**, clicar **Buscar notas** ou **Ativar busca automática**.
5. Em um terminal separado, `php artisan schedule:work` para disparo periódico. Em produção, habilitar o cron do Laravel por instalação apenas após homologação e consentimento.
6. Verificar as notas com XML/resumo; nunca pressupor que o documento completo chegou sem manifestação.

## Testes

`php artisan test --filter=FiscalPurchasesTest`, depois regressão geral. Os 10 testes cobrem: menu e acesso, parsing de NFe, bloqueio de CNPJ errado, importação idempotente, ausência de movimento de estoque, certificado obrigatório, cursor NSU, cooldown, resumo → XML completo, controle de erro 656 e permissão fiscal.

Não há chamada real à SEFAZ em CI: gateway simulado. Portanto funcionamento real com o A1/ACBr deve ser validado em instalação de desenvolvimento antes de afirmar que documentos reais foram recebidos.

## Fontes técnicas

- https://acbr.sourceforge.io/ACBrLib/NFE_DistribuicaoDFePorUltNSU.html
- https://www.nfe.fazenda.gov.br/portal/perguntasFrequentes.aspx
- https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=155Wx5%2FJB5A%3D
