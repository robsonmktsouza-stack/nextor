# NF-e 55 — execução no Laragon existente

Esta sequência utiliza o projeto e a integração ACBr existentes. Nenhum
certificado, senha, `.env` ou INI precisa ser copiado para a nuvem.

Configuração informada pelo proprietário:

| Componente | Configuração local |
| --- | --- |
| Projeto | `C:\laragon\www\nextor` |
| PHP | 8.3, x64, FFI habilitada |
| ACBrLibNFe | 1.5.0.490, Windows MT/Cdecl |
| Biblioteca | `C:\laragon\acbr\Windows\MT\Cdecl\ACBrNFe64.dll` |
| INI existente | `C:\laragon\acbr\config\acbr-nfe.ini` |
| Schemas | `C:\laragon\acbr\releases\1.5.0.490\dep\Schemas\NFe` |
| Emitente de teste | BA, CRT 1, A1 já cadastrado, homologação |

O terminal desta sessão é Linux e não acessa o disco C: nem o PHP/FFI do
Windows. Essa é a razão objetiva para executar os comandos nativos abaixo
localmente. Os testes automatizados na nuvem não são autorizações SEFAZ.

## Atualizar a branch local

No terminal do Laragon (PowerShell), preservando alterações locais:

```powershell
Set-Location C:\laragon\www\nextor
git status --short
git fetch origin
git switch feature/nfe55-motor-fiscal-completo
git pull --ff-only origin feature/nfe55-motor-fiscal-completo
composer install --no-interaction --prefer-dist
php artisan migrate
php artisan optimize:clear
```

Não executar `composer update`, substituir `.env`, recadastrar o A1 ou trocar
o INI que já funciona. As migrations desta implementação são aditivas:
`2026_10_25_000001_extend_fiscal_rules_for_nfe55.php` e
`2026_10_25_000003_create_nfe55_inutilizations.php`.

## Verificação nativa sem transmissão

Salve e valide um rascunho de NF-e no ERP, em homologação, com produtos e regras
fiscais efetivamente cadastrados. Use seu ID (visível na URL da edição).

```powershell
php -v
php -d ffi.enable=true artisan acbr:nfe-doctor
$nota = Read-Host 'ID do rascunho de homologacao ja validado'
php -d ffi.enable=true artisan nfe:preflight $nota
```

O diagnóstico existente informa biblioteca/INI realmente carregados e versão
nativa. O preflight assina com o A1 da instalação, valida XSD e regras nativas,
verifica a assinatura, a identidade fiscal e os valores do XML contra o INI.
Não reserva número e não transmite. Confira se a biblioteca apresentada é a
informada acima e se o schema configurado é o da release instalada.

## Transmissão, autorização, protocolo, XML e DANFE

Para executar a NF-e 4 no mesmo terminal, após atualizar esta branch:

```powershell
php -d ffi.enable=true artisan nfe:verify-flow 4 --enqueue --process --wait=180 --consult
```

`--process` chama o mesmo serviço do job fiscal, direcionado ao ID informado,
com os locks e a reivindicação atômica existentes. Processa a nota quando está
na fila; estados com tentativa fiscal registrada seguem para acompanhamento/
consulta. O job original permanece seguro para o worker existente: encontra
o estado já reivindicado e não repete transmissão. Os demais jobs ficam na fila.
Esta opção dispensa iniciar outro worker para a nota informada. No Windows,
`--wait` limita o acompanhamento posterior; não interrompe uma chamada nativa
ACBr em execução. O resultado final depende do estado e das provas da nota.

O fluxo de dois terminais abaixo continua disponível:

Mantenha o worker fiscal existente. Se não houver worker ativo, em outro terminal
do Laragon, no mesmo projeto e com o mesmo PHP:

```powershell
Set-Location C:\laragon\www\nextor
php -d ffi.enable=true artisan queue:work database --queue=fiscal --tries=1 --timeout=180
```

No terminal em que definiu `$nota`:

```powershell
php -d ffi.enable=true artisan nfe:verify-flow $nota --enqueue --wait=180 --consult
```

`--enqueue` agenda uma única NF-e de homologação validada pelo serviço existente.
Sem `--process`, o comando não inicia worker nem transmite diretamente; não reenfileira documentos
já reservados e recusa produção antes de qualquer ação. `--consult` usa a chave
existente e o serviço de consulta já utilizado no ERP.

Sucesso completo exige estado autorizado, data de autorização, protocolo,
`nfeProc` privado correspondente ao emitente/chave/modelo/ambiente, cStat de
autorização e geração efetiva do DANFE pela ACBr. O comando preserva:

- `fiscal/nfe55/ID/verification.json`: etapas realmente verificadas e resultado;
- `fiscal/nfe55/ID/verified-danfe.pdf`: DANFE gerado localmente;
- XML e retornos originais nos caminhos privados do fluxo existente.

Os caminhos acima são relativos ao disco Laravel `local`, não à pasta pública.
O resumo deve registrar `full_flow_verified: true`. Estado aguardando fila ou
SEFAZ é pendência, não autorização. Falha/recusa retorna código 1; acompanhamento
pendente retorna código 2. Pré-validação sem transmissão pode retornar 0 sem
`full_flow_verified: true`.

Se o comando terminar com nota pendente, acompanhe o mesmo ID; para consultar
novamente, sem agendar outro envio:

```powershell
php -d ffi.enable=true artisan nfe:verify-flow $nota --wait=180 --consult
```

Nota rejeitada é corrigida no fluxo do ERP; situação incerta exige consulta.
Não criar outra nota apenas para contornar erro de comunicação. Cancelamento
e inutilização continuam nas ações existentes, com suas confirmações e provas
individuais SEFAZ. Este comando de verificação não executa esses eventos.

## Configuração de produção

A opção existente **Permitir emissão em produção** controla o ambiente real.
O emissor usa série e próximo número de produção separados, agora editáveis em
Configurações → Notas fiscais → NF-e. Não herda série/numeração de homologação,
não permite retroceder sobre número reservado/inutilizado e faz preflight
nativo antes de reservar/transmitir. O ambiente dos rascunhos existentes é
preservado ao mudar a configuração global.

O código não contém mais o bloqueio incondicional que rejeitava toda produção.
Ativar a opção é uma autorização operacional; não registra uma homologação
SEFAZ fictícia e não amplia os cenários tributários implementados. A cobertura
e os grupos ainda recusados estão na documentação de entrega.

Para revisão remota, compartilhe somente a saída de erro saneada ou os campos
`status`, `stages`, `full_flow_verified` e `failure_type` do resumo. Não envie
certificado, senha, INI completo, XML privado ou banco.
