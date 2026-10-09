# NEXTOR — Cobertura fiscal e fontes (09/10/2026)

O sistema utiliza exclusivamente CFOP, CST, CSOSN e alíquotas configurados pelo responsável. Campos de cadastro não são prova de emissão homologada. A ACBrLib assina, valida e transmite: o cálculo fiscal e a escolha correta da parametrização pertencem ao NEXTOR e ao responsável pela configuração.

## NFC-e 65, Bahia, CRT 1/2/3/4 (nesta branch)

| Grupo | Implementação | Situação |
|---|---|---|
| CSOSN 102 | ICMS Simples sem crédito, sem imposto próprio calculado | testes automatizados |
| CSOSN 103 / 300 / 400 | Grupos sem ICMS próprio; 103 e 400 sujeitos à regra opcional da UF | testes automatizados; homologação pendente |
| CSOSN 500 | Recupera valores informados para vBCSTRet e vICMSSTRet; exige CFOP de ST retida | testes unitários; homologação pendente |
| CSOSN 101 / 201 / 202 / 203 / 900 | Grupos mais complexos, crédito/ST e combinações de ICMS | não suportado neste emissor |
| PIS/COFINS 01 / 02 | Alíquota percentual configurada, valor líquido de desconto | testes unitários e de INI |
| PIS/COFINS 03 | Quantidade multiplicada pela alíquota unitária configurada | testes unitários |
| PIS/COFINS 04 / 06 / 07 / 08 / 09 | Grupos não tributados, sem inventar valores | testes unitários |
| PIS/COFINS 49 / 99 | Com cálculo configurado ou compatibilidade explícita com padrão legado sem alíquota | testes unitários |
| FCP, ICMS-ST nova, IPI, DIFAL, IBS/CBS/IS | Requer cálculo, XML e validação completos | bloqueado |
| CRT 2/3 (CST 00, 20, 40 e 41) | Base modo 3, ICMS percentual e redução explícita para CST 20 | testes automatizados; homologação pendente |
| CRT 4 (MEI) | NFC-e restrita a CSOSN 102/300 e CFOP 5102 | testes automatizados; UF pendente |
| Outros CST do regime normal, outras UFs e NF-e 55 | Exigem fluxo, regra e emissão específicos | bloqueado |

Regras de compatibilidade CFOP/CSOSN na NFC-e:
- 102, 103, 300, 400: CFOP 5101, 5102, 5103, 5104 e 5115 no subconjunto implementado.
- 500: 5405, 5656 e 5667 no subconjunto implementado.
- Não presumir valores de ST anteriormente recolhido. Configurar no produto quando pertinentes.
- Aceitar tecnicamente um código não significa que o enquadramento seja adequado.

## Manuais de referência

1. MOC 7.0 — Leiaute NF-e/NFC-e, regras de validação: https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=J+I+v4eN00E%3D
2. Notas Técnicas vigentes (NT 2025.002 v1.52, 01/10/2026): https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=04BIflQt1aY%3D
3. Informes Técnicos e tabelas oficiais, inclusive cClassTrib: https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=B%2F6oigHgyAw%3D
4. ACBrLib — NFC-e.INI RTC: https://acbr.sourceforge.io/ACBrLib/Modelo2-NFCeINIReformaTributaria.html
5. ACBrLib — NF-e.INI RTC: https://acbr.sourceforge.io/ACBrLib/ModeloNFeINIReformaTributaria.html
6. CONFAZ Ajuste SINIEF 39/2023 (MEI/Simples): https://www.confaz.fazenda.gov.br/legislacao/ajustes/2023/ajuste-sinief-39-23

Verificar versões de tabela e schemas no momento de cada publicação. A tabela comunitária parcial de cClassTrib não pode ser tratada como catálogo oficial integral.

## Ampliação arquitetural

1. Resolvedor de classificação por empresa, CRT, documento, UF origem/destino, data, natureza, NCM/CEST, produto e grupo cadastrado.
2. Calculadoras específicas para ICMS normal, Simples, ST e FCP, PIS/COFINS, IPI, DIFAL, IBS/CBS/IS com decimais de precisão controlada.
3. Tradutores ACBr INI para NF-e 55 e NFC-e 65 isolados; geração de cada grupo somente quando as tags forem permitidas para a situação.
4. Testes unitários, XML/schema, rejeições em homologação e reconciliação dos totalizadores e pagamentos.
5. Congelamento da configuração e dos valores calculados por item no snapshot antes da assinatura, sem alterar notas já transmitidas.
6. Liberação granular por cenário/UF/schema, preservando bloqueio de produção até homologação de cada um.

O caixa não escolhe códigos fiscais e não recebe etapa de conferência tributária. Configurações ausentes geram pendência objetiva para correção pelo responsável.

Rodar a suíte fiscal, PHP lint, compilação das views e testes em homologação antes de qualquer ativação de produção. Não confundir cStat 107 (serviço operante) com autorização efetiva de uma nota.


## Configuração da produção no NEXTOR

A permissão de produção não depende mais de modificar .env nem de listas
estáticas de perfis. Está nas configurações de cada documento:
NF-e, NFC-e, NFS-e, CT-e e MDF-e.

Os cinco tipos utilizam a mesma política de configurações
(`App/Services/Fiscal/FiscalDocumentSettings.php`):
- recursos fiscais habilitados globalmente;
- documento habilitado;
- ambiente escolhido;
- permissão de produção, inicialmente desativada.

Quando um transmissor estiver implementado, deverá usar a política central
para impedir envio não autorizado. Hoje apenas a NFC-e possui transmissor ACBr
nesta branch; NF-e, NFS-e, CT-e e MDF-e continuam com telas, cadastro de
parâmetros e preparação de documentos, sem transmissão final implementada.
Ativar opções na tela não cria um emissor nem garante autorização SEFAZ.

Para NFC-e, a validação tributária permanece por produto, CST/CSOSN, CFOP,
NCM, alíquotas, pagamentos e totalização. Não permitir CST ou tributo não
implementado, nem contingência offline, sem cálculo e XML correspondente.
O emissor atual ainda é específico para BA — UF diferente exige efetiva
adaptação do XML, SEFAZ e QR Code, não só uma opção visual.

A escolha de produção deve seguir homologação real dos cenários relevantes.
Testes automatizados não substituem autorização da SEFAZ.

## CSOSN 500 — valores de retenção

No produto, informar vBCSTRet e vICMSSTRet previamente apurados, além de
"Valores ICMS-ST retido" = "Por unidade" ou "Total do item". Por unidade,
o cálculo multiplica o valor informado pela quantidade vendida. Por linha,
utiliza o total informado como já correspondente à linha fiscal.
Sem essa indicação, bloquear a nota, evitando ST retido incorreto quando a
venda tem múltiplas unidades. Valores e aplicabilidade continuam sendo
responsabilidade de quem parametriza e dependem das exigências da UF.
