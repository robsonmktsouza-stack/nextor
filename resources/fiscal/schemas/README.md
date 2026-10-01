# Schemas fiscais do Nextor

Esta pasta é reservada exclusivamente para cópias íntegras dos XSD oficiais publicados no Portal Nacional da NF-e.

Base auditada em 01/10/2026:
- Leiaute NF-e/NFC-e: 4.00.
- Pacote listado no Portal: `010e_v.1.02` (NT 2025.002 v1.40, NT 2026.002 v1.0 e NT 2026.003 v1.0), publicado em 10/07/2026.
- Pacote CNPJ alfanumérico listado no Portal: `010d_v.1.03` (NT 2026.004 v1.01), publicado em 10/07/2026.
- Schemas de eventos RTC da NT 2025.002 v1.40, publicados em 27/07/2026.

Regras:
1. Nunca editar XSD oficial manualmente.
2. Cada pacote importado deve ficar em diretório versionado.
3. Registrar origem, data de publicação e hash dos arquivos.
4. O validador deverá selecionar a versão por manifesto, nunca por caminho hardcoded no gerador XML.
5. Notas Técnicas que alterem apenas regras de validação também devem ser registradas no catálogo documental, ainda que não publiquem novo pacote XSD.

Notas vigentes que devem acompanhar o catálogo de regras, mesmo quando não publicam novo ZIP de schema:
- NT 2025.002 v1.51 (publicada em 04/08/2026): alterações de regras de validação da RTC.
- NT 2026.002 v1.10 (publicada em 04/08/2026): alterações de regras de validação.
