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

- A cobertura evoluiu além do cenário inicial. Consultar **docs/integrations/fiscal-coverage-2026.md** para a matriz por CRT, CSOSN/CST, modalidade de cálculo e respectivo status. Cenários não mapeados continuam bloqueados: códigos disponíveis em cadastro não significam autorização em homologação.
- O modelo de emissão cobre NFC-e BA em modo normal. Outras UFs, natureza fiscal especial, contingência, tributação completa e novos grupos da reforma tributária requerem suporte específico.
- A comunicação de status `107` **não equivale a uma NFC-e autorizada**. A autorização real precisa retornar `100` ou `150`, com chave/protocolo da nota enviados e validados.
- Não executar `NFE_Enviar` novamente para uma nota pendente. Consultar a situação pela chave primeiro.
- Não liberar produção até emitir/autorizá-la em homologação, revisar o XML `nfeProc` e confirmar a impressão oficial do DANFE NFC-e.

## Documentação ACBr

- https://acbr.sourceforge.io/ACBrLib/Modelo2-NFCeINI.html
- https://acbr.sourceforge.io/ACBrLib/ConfiguracoesdaBiblioteca16.html
- https://acbr.sourceforge.io/ACBrLib/NFE_Enviar.html
- https://acbr.sourceforge.io/ACBrLib/NFE_Consultar.html


## DANFE NFC-e térmico (58 e 80 mm)

O NEXTOR disponibiliza o DANFE NFC-e somente para notas autorizadas, gerado a partir do arquivo nfeProc arquivado. A página não utiliza o comprovante interno do PDV, não cria outra venda e não aciona ACBr ou SEFAZ.

- Fiscal → NFC-e → documento autorizado → **Imprimir DANFE NFC-e** abre a prévia.
- PDV → Última venda: quando a nota está autorizada, também oferece **Imprimir DANFE NFC-e**.
- Comprovante interno: mantém seu botão separado e não substitui o DANFE.
- Na prévia, selecione 58 mm ou 80 mm e clique em **Imprimir DANFE NFC-e**.
- Windows: instale o driver da impressora térmica USB/rede, selecione a impressora no Chrome, configure a largura do papel, escala 100%, margens nenhuma e desative cabeçalho/rodapé.
- Papel com largura mínima de 56 mm e margens laterais de 2 mm; QR Code de no mínimo 25 × 25 mm (o layout usa 32/36 mm).
- O QR Code é gerado localmente no navegador pela biblioteca MIT qrcode-generator com exatamente a URL infNFeSupl/qrCode arquivada no XML. Não reconstitui CSC nem envia a URL a serviços externos. Impressão bloqueada se o QR não puder ser gerado.
- O documento mostra emitente, itens, totais, pagamentos, troco, consulta por chave (11 grupos de 4 dígitos), QR Code, consumidor, emissão, série/número, protocolo e a mensagem obrigatória de homologação quando tpAmb=2.
- Por enquanto, os endereços de consulta e QR Code são restritos a *.sefaz.ba.gov.br, pois o emissor é específico da Bahia.
- A impressão ocorre pelo diálogo do navegador. Impressão silenciosa dependeria de aplicativo local ou de configuração administrada do navegador; não existe instalação de driver pelo site.

Se o arquivo ainda é só assinado ou falta nfeProc, use **Recuperar XML autorizado** sem transmitir a nota novamente.

Fonte: Manual de Padrões Técnicos do DANFE NFC-e e QR Code, versão 6.0, março/2025 (Portal Nacional da NF-e).
