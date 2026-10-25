## Continuação — RTC, DIFAL, ICMS residual e conferência de XML

Publicação de código: `899ba66` (após `70be57f`, grupos federais).

Suíte final completa: **824 testes, 5181 assertions, zero falhas/erros e dois
ignorados** (geração de PDF real requer Chromium local). Duração 1m56s, PHP
8.5.12/SQLite. Após corrigir o texto da mensagem RTC e completar as fixtures de
regras residuais, foram aprovados quatro testes direcionados (15 assertions)
e a suíte completa acima. Compilação Blade, lint das classes alteradas e
sintaxe JavaScript aprovados. Evidências:
[saída PHPUnit](evidence/nfe55-tests-2026-10-25.txt),
[resumo e hash JUnit](evidence/nfe55-tests-2026-10-25.json),
[arquivos alterados nesta continuação](evidence/nfe55-files-2026-10-25.txt).
A versão PHP 8.3 e o MySQL de produção ainda não foram ensaiados aqui.
O estado desta seção substitui as limitações históricas abaixo somente nos
pontos explicitamente descritos. O módulo integral continua em desenvolvimento.

- Catálogo oficial SVRS incorporado: 18 CST e 173 classificações, das quais 104
  permitem NF-e. Isso é validação cadastral, **não** significa 104 cenários
  implementados. Verificação de par CST/cClassTrib, vigência, modelo, reduções
  oficiais e indicadores obrigatórios; sem inferir enquadramento por NCM.
- Cálculo e INI de IBS estadual, municipal e CBS nos grupos admitidos de
  000/200/410/510/515/550; reduções conferidas com a classificação, diferimento
  e tributação regular separada quando exigida. Grupos monofásicos, ZFM,
  estorno/créditos presumidos e diferença de biocombustível continuam bloqueados.
  Transferência de crédito e ajuste têm cálculo, mas continuam bloqueados no
  emissor até integrar suas finalidades. Nem toda classe desses CST é suportada.
- DIFAL na saída interestadual normal a consumidor final não contribuinte de
  CRT 2/3 exige parâmetros expressos, partilha e verificação de origem/UF.
  A dispensa do grupo para CRT 1 segue a exceção 12 da regra NA01-20 do MOC;
  não constitui declaração genérica de dispensa da obrigação tributária.
- CST 90/CSOSN 900 integrados com regra rastreada, grupos próprio/ST/crédito
  opcionais expressos. Crédito SN exige contribuinte e destinação compatível.
  CSOSN 900 não gera FCP próprio incompatível com o schema. CRT 4 permanece
  limitado aos cenários anteriores.
- IS percentual, por quantidade e combinado ligado ao formulário, regras,
  prévia, totais e INI, com vigência explícita a partir de 2027, fonte e referência
  obrigatórias. **Sem catálogo oficial IS verificado**: validação de formato não
  comprova incidência/enquadramento; testes futuros são fixtures aritméticas.
  Exige base final IBS/CBS expressa. Não é cenário homologado para produção.
- Totais de 2027+ separam vNF legado e vNFTot. Alíquotas de transição nunca são
  preenchidas automaticamente. A matriz legal de todos os períodos/regimes
  ainda exige revisão; SN com opção RTC específica continua bloqueado.
- XML assinado retornado pelo ACBr é confrontado com os tributos e totais do INI
  antes do envio. A conferência não substitui XSD, assinatura ou homologação.
  Totalizadores obrigatórios ausentes são recusados; zeros opcionais seguem XSD.
- Formulários de itens e regras incluem os novos parâmetros, sem taxas padrão.

### Fontes desta ampliação (consulta 25/10/2026)

1. SVRS, Classificação Tributária:
   https://dfe-portal.svrs.rs.gov.br/CFF/ClassificacaoTributaria
   Snapshot e metadados: `resources/fiscal/rtc-classifications.json`.
   Página sem número de versão; SHA-256 do conteúdo-fonte registrado no JSON.
   Não atribuímos a versão IT 2025.002 v1.70 listada em outra página a esse
   snapshot. Datas finais ambíguas são tratadas conservadoramente como exclusivas.
   O snapshot atual não comprova versões históricas nem enquadramento jurídico.
2. MOC 7, Anexo I, regras NA01-20 e NA09-10/20/30 (DIFAL):
   https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=1&nomeArquivo=moc7-anexo-i-leiaute-e-rv.pdf
   Aplicação delimitada à saída normal interestadual descrita acima; demais
   exceções e sobreposições de NT/legislação estadual exigem homologação.
3. ACBr, leitor RTC oficial, campos e grupos INI:
   https://svn.code.sf.net/p/acbr/code/trunk2/Fontes/ACBrDFe/ACBrDFe.RTC.IniReader.pas
   e escritor `ACBrDFe.RTC.IniWriter.pas` na mesma pasta (competência INI).
   Fonte consultada não fornece tabela legal de CST/classificação do IS.
4. Schemas oficiais distribuídos pelo ACBr:
   https://svn.code.sf.net/p/acbr/code/trunk2/Exemplos/ACBrDFe/Schemas/NFe/leiauteNFe_v4.00.xsd
   e `DFeTiposBasicos_v1.00.xsd` na mesma pasta. Leiaute NF-e 4.00;
   estrutura/domínios conferidos em fonte, sem execução nativa nesta máquina.

Nenhuma migration adicional nesta ampliação; permanecem as duas migrations
listadas nas instruções de atualização abaixo. Produção continua bloqueada até
homologação. Sem biblioteca ACBr Linux/certificado A1 configurados, não houve
validação nativa, assinatura/transmissão ou protocolo SEFAZ reais. Continuam
pendentes contingência 55, complemento/ajuste/débito/crédito, importação Simples/
DUIMP/terceiros, exportação indireta, regras específicas de todas as UFs e os
grupos RTC acima bloqueados. Não liberar todos esses cenários como prontos.

## Continuação — grupos federais integrados

PIS-ST e COFINS-ST percentuais/quantidade agora são calculados, editáveis no
formulário/regras e serializados nos grupos oficiais. O indicador de soma ao
valor da nota é explícito por tributo. IPI devolvido usa o valor original do item
e o percentual de devolução, exige finalidade/referência e compõe vIPIDevol/vNF.
Prévia, rascunho e INI usam os mesmos valores em centavos.

Executados: 58 testes direcionados (209 assertions) e 40 testes de integração e
regressão (323 assertions), aprovados em PHP 8.5.12/SQLite. A nova suíte completa
será registrada após integrar a ampliação RTC. Não houve homologação nativa.

Fontes consultadas nesta continuação:
- ACBr INI reader, revisão SVN consultada 48801:
  https://svn.code.sf.net/p/acbr/code/trunk2/Fontes/ACBrDFe/ACBrNFe/Base/ACBrNFe.IniReader.pas
  (indSomaPISST, indSomaCOFINSST, indDeduzDeson, impostoDevol e totalizadores).
- XSD leiauteNFe_v4.00.xsd:
  https://svn.code.sf.net/p/acbr/code/trunk2/Exemplos/ACBrDFe/Schemas/NFe/leiauteNFe_v4.00.xsd
  (pDevol: duas casas; indicadores ST: 0/1).

O histórico abaixo descreve o estado anterior e suas limitações, superadas
somente nos pontos explicitamente registrados acima.

# NF-e 55 — entrega de desenvolvimento em 25/10/2026

Branch exclusiva: `feature/nfe55-motor-fiscal-completo`. Sem merge ou deploy.

Implementação integrada: regras fiscais por vigência/contexto com revisões e
rastreio; calculadores decimais ICMS/ICMS-ST/FCP, retenção anterior,
diferimento, desoneração, DIFAL, IPI e PIS/COFINS percentuais/quantidade;
adaptador IBS/CBS com centavos; builder ACBr; formulário e prévia calculada
no servidor; comércio exterior direto; rascunhos com locks; snapshot e
numeração atômica; filas sem retransmissão cega; cancelamento e inutilização 55.

## Evidência e limites

- Dependências instaladas de `composer.lock`, sem atualização de versões.
- Runtime de teste: PHP 8.5.12, PHPUnit 12.5.40, SQLite, contêiner `composer:2`.
- Baseline original: 619 testes, 4 falhas de expectativas antigas de interface,
  2 ignorados. As expectativas foram atualizadas preservando os controles.
- Primeira integração: 190 testes; encontrados erros Blade e integração,
  corrigidos. Segunda integração: 192 testes, somente 3 expectativas antigas
  remanescentes; corrigidas antes da execução completa.
- Suíte completa: 737 testes, 4695 assertions, 1 falha em expectativas antigas
  de texto da interface e 2 ignorados. Corrigidos os textos; reteste direcionado
  passou: 1 teste, 87 assertions. A suíte completa não foi repetida depois
  dessa última correção somente de teste.
- Lint PHP de app/migrations/tests, compilação Blade e `node --check` aprovados.
- SQLite/cache array não comprovam locks entre processos MySQL. Falta ensaio
  InnoDB com trabalhadores concorrentes e cache compartilhado.
- Não houve chamada nativa ACBr ou transmissão SEFAZ: ambiente sem biblioteca
  Linux ACBr, schemas e certificado A1 configurados. Não há homologação fiscal.

O módulo **não está completo nem liberado para produção**. Permanecem bloqueados
ou sem validação integral: Imposto Seletivo; demais CST/cClassTrib e créditos/
diferimentos RTC; PIS/COFINS-ST; complemento/ajuste/débito/crédito; devolução de
IPI; contingência 55; importação Simples, DUIMP e terceiros; exportação indireta;
matriz tributária de 2027 e períodos seguintes. O DIFAL está calculado e
serializado, mas o bloqueio conservador da operação permanece até validação
normativa e nativa. Não confundir classes existentes com cenários homologados.

Importação exige bases expressas e DI/adições; exportação normal em 2026 ainda
depende de classificação RTC específica. O teste de exportação de 2026 usa
Simples Nacional parametrizado, não uma classe RTC inventada. Valores dos testes
são fixtures aritméticas e não constituem enquadramento jurídico de clientes.

## Atualização em desenvolvimento/homologação

1. Atualizar esta branch por fast-forward; preservar backup e configuração local.
2. Executar `composer install --no-interaction --prefer-dist`.
3. Executar `php artisan migrate` em base de homologação. Novas migrations:
   `2026_10_25_000001_extend_fiscal_rules_for_nfe55.php` e
   `2026_10_25_000003_create_nfe55_inutilizations.php`.
4. Executar `php artisan optimize:clear` e `vendor/bin/phpunit`.
5. Revisar regras com fonte, referência, versão e vigência; informar alíquotas,
   bases e enquadramento IPI explicitamente. CST residual não presume taxa zero.
6. Usar worker `database/fiscal` com timeout 180 s e retry_after mínimo 240 s.
   Os jobs fazem retentativa de disputa de lock, não de transmissão incerta.
7. Configurar ACBr e certificado local de homologação; executar
   `php artisan nfe:preflight ID` e depois o fluxo de emissão de homologação.
   Conferir XML/XSD/assinatura/protocolo/eventos e DANFE com evidência real.

Notas antigas já reservadas sem fingerprint não recebem hash fabricado. Exigem
análise de recuperação e consulta antes de qualquer transmissão. Documentos
autorizados e XMLs originais permanecem preservados.

## Fontes efetivamente acessadas nesta sessão

- ACBr, modelo INI oficial, consulta 25/10/2026:
  https://acbr.sourceforge.io/ACBrLib/ModeloNFeINI.html
  (grupos ICMS, ICMSUFDEST, IPI, II, DI/LADI e Exporta).
- Fonte oficial ACBr `ACBrNFeNotasFiscais.pas`, consulta 25/10/2026:
  https://svn.code.sf.net/p/acbr/code/trunk2/Fontes/ACBrDFe/ACBrNFe/ACBrNFeNotasFiscais.pas

Esses documentos descrevem a integração, não determinam incidência, benefício ou
alíquota. A consulta atualizada integral das normas, tabelas RTC e exigências por
UF permanece pendente e impede declarar conformidade fiscal comprovada.
