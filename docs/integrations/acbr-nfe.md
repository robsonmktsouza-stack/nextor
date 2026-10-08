# NFC-e integrada ao PDV do NEXTOR (ACBrLibNFe)

## Funcionamento

A NFC-e modelo 65 utiliza o mesmo pedido, venda, estoque e financeiro do PDV. Quando **Fiscal** e **NFC-e** estão habilitados, cada venda de produtos cria um único registro em `fiscal_document_jobs`, com série/número reservado e snapshot de produtos/tributação/pagamentos.

- **Emissão manual:** após a venda, o comprovante interno leva à tela fiscal; `Emitir NFC-e` inicia o processamento.
- **Emissão automática:** habilitando a opção no PDV/NFC-e, a fila processa o documento depois da confirmação da venda. Não há segunda venda nem nova movimentação de estoque.
- **SEFAZ autorizou (cStat 100/150 individual):** exige chave de 44 dígitos, protocolo de 15 dígitos e chave igual à do XML assinado. Persiste status, XML assinado, retorno SEFAZ e, quando há dados completos, `authorized.xml` com `nfeProc`.
- **Falha após a transmissão:** status `pending`; **não há reenvio automático**. Na tela fiscal, `Consultar SEFAZ` verifica a chave já preservada.
- **Baixar XML:** documento fiscal → `XML autorizado` ou `XML assinado`, conforme arquivo disponível. Acesso limitado à permissão fiscal.

O recibo atual do PDV é comprovante **não fiscal**, não DANFE NFC-e. Impressão oficial com QR Code, eventos de cancelamento/inutilização e contingência offline ainda exigem desenvolvimento antes da produção.

## Execução local e publicação

Ambiente homologação BA já teve retorno **cStat 107**, indicando conexão com a SEFAZ. A biblioteca é a variante ACBrLibNFe MT Cdecl x64, instalada no Windows local. Use os binários equivalentes para Linux caso o worker fiscal execute em servidor Linux.

Variáveis de configuração:
- `ACBr_NFE_LIBRARY_PATH`: DLL/SO nativa.
- `ACBr_NFE_CONFIG_PATH`: INI exclusivo para os comandos de diagnóstico; o processamento da NFC-e cria um INI privado por execução e o descarta ao terminar.
- `ACBr_NFE_SCHEMAS_PATH`: schemas NFe, se não estiverem nos caminhos padrão.
- `QUEUE_CONNECTION=database`: fila fiscal persistente.
- `ACBr_NFE_PRODUCTION_ENABLED=false`: produção bloqueada até validação ponta a ponta.

Após atualizar o projeto, rodar a migração se pendente e iniciar o worker fiscal em um processo separado, configurado para reiniciar automaticamente no ambiente operacional:

```bat
php artisan queue:work database --queue=fiscal --tries=1 --timeout=180
```

O timeout da fila do banco é de **pelo menos 240 segundos**, evitando a liberação prematura de documentos em processamento. Cada job fiscal permite uma única execução.

## Validação integrada única

O workflow `acbr-integration-checks.yml` verifica sintaxe PHP e a suíte de regressão. No Laragon, a conferência consolidada pode ser executada em uma única rodada:

```bat
git pull
php artisan optimize:clear
vendor\bin\phpunit --filter NFCe
php artisan acbr:nfce-status
php artisan route:list --name=fiscal.nfce
```

Não é preciso repetir os testes unitários anteriores: estes já foram concluídos. Depois de verificar a suíte consolidada, habilitar **Fiscal** e **NFC-e** em homologação, manter transmissão automática desativada, verificar NCM/CFOP/CSOSN/PIS/COFINS corretos, manter o worker ativo e concluir **uma venda controlada pelo PDV existente**. A venda movimenta estoque e financeiro reais do NEXTOR, mesmo com NFC-e em homologação.

## Limitações deliberadas

- Só estão implementados e pré-validados, por ora, **CRT 1, CSOSN 102 e PIS/COFINS CST 49**. O sistema bloqueia tributação não mapeada para evitar inventar impostos; isso não significa que esses códigos sejam corretos para todos os produtos.
- O modelo de emissão cobre NFC-e BA em modo normal. Outras UFs, natureza fiscal especial, contingência, tributação completa e novos grupos da reforma tributária requerem suporte específico.
- A comunicação de status `107` **não equivale a uma NFC-e autorizada**. A autorização real precisa retornar `100` ou `150`, com chave/protocolo da nota enviados e validados.
- Não executar `NFE_Enviar` novamente para uma nota pendente. Consultar a situação pela chave primeiro.
- Não liberar produção até emitir/autorizá-la em homologação, revisar o XML `nfeProc` e confirmar a impressão oficial do DANFE NFC-e.

## Documentação ACBr

- https://acbr.sourceforge.io/ACBrLib/Modelo2-NFCeINI.html
- https://acbr.sourceforge.io/ACBrLib/ConfiguracoesdaBiblioteca16.html
- https://acbr.sourceforge.io/ACBrLib/NFE_Enviar.html
- https://acbr.sourceforge.io/ACBrLib/NFE_Consultar.html
