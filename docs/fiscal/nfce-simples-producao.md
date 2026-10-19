# NFC-e modelo 65 — fechamento técnico para o Simples Nacional (BA)

Revisado em 19/10/2026 · branch `feature/acbr-nfe-bootstrap` · finalidade: emissão em **produção** por empresas do Simples Nacional no Lumeron. Não mudar dados dos clientes durante a auditoria.

## Escopo efetivamente implementado

- **CRT 1:** operações internas de varejo com CSOSN 102, 103, 300, 400 e 500, CFOPs permitidos pelo serviço e PIS/COFINS cadastrados por CST. A regra do produto não é deduzida pelo NCM: o grupo fiscal e os documentos de entrada continuam sendo a fonte de enquadramento.
- **CSOSN 500, ICMS-ST anterior:** usa a base, o ICMS retido, a unidade de referência e a alíquota que constam dos documentos fiscais de entrada. Quando existe FCP-ST anteriormente retido, aceita `fcp_st_retained_base`, `fcp_st_retained_rate` e `fcp_st_retained_value`; preserva `vBCFCPSTRet`, `pFCPSTRet` e `vFCPSTRet` no INI da ACBr sem supor valores.
- **PIS/COFINS da revenda monofásica:** permite CST 04 sem alíquota no revendedor, desde que o cadastro fiscal de cada produto esteja correto; não altera o CST automaticamente a partir do NCM. Contribuições e cenários não tributados (CST 06/07/08/09) permanecem disponíveis. Não confundir monofasia de PIS/COFINS com monofasia de **ICMS sobre combustíveis**.
- **RTC 2026:** optantes CRT 1/MEI CRT 4 não recebem alíquotas de IBS/CBS inventadas. CRT 2 (excesso de sublimite) segue tratamento de ICMS de regime normal e requer revisão por perfil. O cronograma RTC do Simples para 2027 deverá ser revisto conforme atos vigentes.
- **Pagamento:** conciliação de valores, troco, PIX, TEF/POS, dados da adquirente e local do pagamento. CPF/CNPJ com dígito verificador.
- **Operação:** numeração, CSC e série separados entre homologação/produção, certificado A1, fila `database/fiscal`, consulta/rejeição/cancelamento/inutilização/contingência e armazenamento do XML/DANFE, sem confundir comprovante não fiscal com DANFE.
- **XML:** `NFCeItemValueGuard` verifica precisão de quantidade (3 casas), preço unitário (4 casas), desconto e valor da linha (2 casas), além do dígito verificador GS1 do GTIN/EAN informado; usado no preflight, na construção do INI, no formulário de emissão e na auditoria.
- **Auditoria pré-produção:** `NFCeCatalogAuditService` percorre mercadorias ativas e usa o mesmo classificador, calculadora ICMS/PIS/COFINS, RTC e validador de valores que o emissor. Relatório somente leitura, com quantidade de produtos auditados e exemplos de inconsistência.

## Procedimento para comprovar a liberação em uma instalação REAL

1. Atualize a branch e rode `vendor/bin/phpunit`. No GitHub Actions, conferir a suíte **ACBr integration checks** e o histórico das mudanças; os testes em SQLite/CI não substituem a ACBr Windows.
2. Rode `php artisan acbr:nfce-check --environment=production --catalog`. O comando **não altera** configuração, certificados, estoque, série nem XML; retorna falha enquanto houver pendência. Não exibe CSC, PFX ou senha.
3. Corrija todos os produtos ativos sinalizados pelo relatório (NCM, origem, CSOSN, CFOP, CST de PIS/COFINS, ST anterior quando aplicável, códigos GTIN, preço). Não mude arbitrariamente classificações tributárias.
4. Rode `php artisan acbr:nfe-doctor` no Laragon para validar a DLL/FFI e `php artisan acbr:nfce-status` para consultar a disponibilidade do serviço **no ambiente atualmente escolhido**. O código 107 indica serviço em operação, não nota autorizada.
5. Como já há notas anteriores autorizadas em **homologação**, faça regressão **somente dos cenários alterados desde então** com a versão atual da ACBr: emissão com dinheiro, PIX, cartão, 102/500/ST/FCP-ST, consulta após timeout, XML nfeProc autorizado, QR Code/DANFE e eventos fiscais habilitados. Não há necessidade de recriar todos os testes históricos.
6. Confira a **primeira NFC-e real** de cada cenário tributário com protocolo e cStat 100 no Portal NF-e/SEFAZ-BA e XML arquivado. Não cadastrar uma segunda venda para corrigir nota rejeitada.

## Limites que exigem conferência por mercadoria

O programa não pode adivinhar regimes a partir da descrição/NCM. **CSOSN 201/202/203/900, FCP próprio em cenários especiais, ICMS monofásico de combustíveis, operações interestaduais e classificações RTC específicas** ainda exigem regra particular antes de serem usadas em produção. Se houver produtos dessas categorias, o auditor os deve apontar e a implementação correspondente deve ser completada para eles.

## Referências verificáveis

- [Portal Nacional NF-e — notas técnicas e schemas](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=04BIflQt1aY%3D): versões RTC, CNPJ alfanumérico, QR Code e validação fiscal.
- [ACBr — modelo completo NFe.INI](https://acbr.sourceforge.io/ACBrMonitor/ModeloNFeINICompleto.html): CSOSN 500, CST de PIS/COFINS, ICMS e retenções FCP-ST.
- [LC 214/2025 (texto legal)](https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp214.htm): IBS/CBS e transição.
- [SEFAZ Bahia](https://www.sefaz.ba.gov.br/): autorização, CSC, ambiente e credenciamento do emitente.

**Importante:** até que os comandos sejam executados com a ACBr, certificado e banco de dados do emitente no Laragon, a instalação individual não tem laudo local de aptidão; a CI prova a lógica testada no repositório, não a autorização em produção.
