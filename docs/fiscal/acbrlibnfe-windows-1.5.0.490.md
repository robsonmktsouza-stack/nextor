# ACBrLibNFe Windows 1.5.0.490 — conferência do pacote recebido

Pacote examinado: `ACBrLibNFe-Windows-1.5.0.490.7z` (fornecido pelo proprietário em 23/10/2026).

## Inspeção estática real

- Arquivo 7z válido: **245 entradas**, aproximadamente **80.438.298 bytes descompactados**.
- Biblioteca recomendada para o Lumeron Laravel/PHP 8.3 x64: `Windows/MT/Cdecl/ACBrNFe64.dll`, DLL PE32+ x86-64, **7.983.224 bytes**.
- SHA-256 verificado dessa DLL: `a5c355c67913253dbce28c7f4157c4460321440a0e73cdc66fce28d938be47ba`.
- O PE exporta `NFE_Inicializar`, `NFE_Finalizar`, `NFE_CarregarINI`, `NFE_Assinar`, `NFE_Validar`, `NFE_ObterXml`, `NFE_Enviar`, `NFE_Consultar`, `NFE_Cancelar`, `NFE_EnviarEvento`, `NFE_Inutilizar`, `NFE_SalvarPDF` e outros símbolos usados pelo adaptador atual.
- A DLL também exporta `NFE_ValidarRegrasdeNegocios`, agora chamado no processamento da NF-e entre `NFE_Validar` e `NFE_ObterXml`. O código PHP exige ausência de alertas fiscais (ou retorno textual de sucesso explícito) antes de liberar `NFE_Enviar`. É uma conferência adicional **local**, não substitui rejeições da SEFAZ.
- Schemas em `dep/Schemas/NFe` incluem `nfe_v4.00.xsd`, `leiauteNFe_v4.00.xsd` e `DFeTiposBasicos_v1.00.xsd`; a estrutura encontrada inclui `IBSCBS`, `IBSCBSTot`, `TTribNFe`, `TIBSCBSTot` e `IS`.
- Dependências para x64 disponíveis nas pastas `dep/LibXml2/x64` e `dep/OpenSSL/x64`. O pacote ainda distribui OpenSSL 1.1; não considerar isso aprovação de segurança/produção.
- Verificação estática adicional: dos **202 XSDs** extraídos, os schemas de NF-e 4.00 (`nfe_v4.00.xsd` e `leiauteNFe_v4.00.xsd`) e `envEventoCancNFe_v1.00.xsd` compilaram com `lxml`. **`envCCe_v1.00.xsd` e `CCe_v1.00.xsd` falharam na compilação isolada** por declaração duplicada de `TCOrgaoIBGE`. É necessário conferir o conjunto vigente da carta de correção com a ACBr e os schemas oficiais antes de usar CC-e; isso não comprova que o serviço SEFAZ rejeitará o evento, somente que este teste offline falhou.
- **Não há compilação Linux nem pacote de exemplos PHP neste arquivo**. O `LEIA-ME.pdf` indica links separados para demos e arquivos Linux.
- Inspeção estática em Linux: **a DLL Windows não foi carregada nem executada**. Ter o símbolo exportado NÃO valida a assinatura FFI em tempo de execução, a geração fiscal, a vigência dos schemas nem a autorização SEFAZ.

### Incompatibilidade encontrada no Laragon e corrigida no instalador

- Em 23/10/2026 o `php artisan acbr:nfe-doctor` localizou uma DLL existente em `C:/laragon/acbr/Windows/MT/Cdecl/ACBrNFe64.dll` e informou a versão interna `1.5.0.489`, com FFI inicializada e 0 falhas.
- O SHA-256 da DLL ativa informado pelo usuário foi `391B8E7FC1BE92FFF81A6D37428484DF98DDCA2777DF8EAE8BD47AC1CD3B58A1`, diferente da DLL do pacote fornecido (`A5C355C67913253DBCE28C7F4157C4460321440A0E73CDC66FCE28D938BE47BA`). **São binários diferentes; não é seguro afirmar que a instalação usa o pacote recebido.**
- Instalador corrigido para substituir **a DLL que o ERP efetivamente carrega** no caminho `Windows/MT/Cdecl/ACBrNFe64.dll`, gerando backup da anterior e verificando o hash após cópia.
- O instalador preserva `ACBr_NFE_CONFIG_PATH` existente (no Laragon era `C:/laragon/acbr/config/acbr-nfe.ini`) e atualiza apenas `ACBr_NFE_LIBRARY_PATH` e `ACBr_NFE_SCHEMAS_PATH`. Os schemas são instalados em pasta isolada `releases/1.5.0.490/dep/Schemas/NFe` para evitar mistura com versões antigas.
- Antes de substituir a DLL, encerre o worker fiscal e o Laragon para evitar arquivos bloqueados. Reinicie Laragon e o worker após instalar. Não copie DLL para `System32`.
- Revalide com `Get-FileHash "C:\\laragon\\acbr\\Windows\\MT\\Cdecl\\ACBrNFe64.dll" -Algorithm SHA256` e com `php artisan acbr:nfe-doctor`. Compare o hash **completo**; a string de versão interna pode não coincidir com a versão do arquivo compactado.
## Instalação no Laragon (Windows)

Use o instalador versionado, que extrai seu próprio `.7z`, confere o hash da DLL MT/Cdecl x64, valida os schemas, cria backup do `.env`, copia a biblioteca MT/Cdecl x64 no mesmo caminho já utilizado pelo PHP, preserva o INI existente, isola os schemas por versão e instala as dependências x64:

```powershell
cd C:\laragon\www\nextor
git switch develop
git pull --ff-only origin develop

powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\fiscal\Instalar-ACBrLibNFe-Windows.ps1 -Pacote "C:\Downloads\ACBrLibNFe-Windows-1.5.0.490.7z" -PersistirPath

php artisan optimize:clear
php artisan acbr:nfe-doctor
```

Altere apenas o caminho do `.7z` para o local real do arquivo baixado no PC. Necessário o **7-Zip** instalado e PHP x64. Reinicie Laragon e o worker após a atualização do PATH. O instalador escreve somente no sistema local e no `.env` do clone; **não sobe certificado e não altera banco ou VPS**.

**Observação:** para um worker configurado por outro usuário Windows, o PATH precisa estar disponível para aquele processo. Não mover DLLs para `System32`.

## Etapas fiscais ainda necessárias

1. Confirmar que `php artisan acbr:nfe-doctor` consegue inicializar a DLL Windows no PHP CLI do Laragon; configurar extensão FFI no CLI/worker se necessário.
2. Verificar a versão do binário e alinhar schemas às notas técnicas oficiais vigentes da SEFAZ; a presença de grupos IBS/CBS por si só não garante atualidade normativa.
3. Em **homologação**, testar XML modelo 55 completo com a ACBr instalada, certificado A1 do emitente local e conferência do XML assinado. Evitar inserir certificados/senhas em commits, logs ou mensagens.
4. Testar cStat, protocolo, consulta por chave, DANFE, cancelamento, CC-e e inutilização, sempre sem reenvio automático em situação incerta. Fluxos tributários especiais permanecem bloqueados até prova.
5. Para produção na VPS será necessária a edição **Linux x64 da mesma família da biblioteca** e verificação de deps, schemas, permissões, isolamento por empresa, filas e certificados. Não copiar a DLL Windows para o Ubuntu.
6. O gate de produção da NF-e em `Nfe55EmissionService` segue propositalmente bloqueado.

## Segurança e controle de versão

Nenhum binário ACBr, certificado digital ou chave privada deve ser adicionado ao repositório. O instalador utiliza arquivo local externo ao Git, e a integração continua na branch `develop`.
