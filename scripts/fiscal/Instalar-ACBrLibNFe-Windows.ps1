# Instalador local da ACBrLibNFe MT/Cdecl x64 para o Lumeron.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Pacote,
    [string]$Destino = 'C:\laragon\acbr',
    [switch]$NaoConfigurarEnv,
    [switch]$PersistirPath
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$zip = (Resolve-Path -LiteralPath $Pacote).Path
if (-not $zip.EndsWith('.7z', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Informe o arquivo .7z da ACBrLibNFe.'
}
$zip7 = @(
    (Get-Command '7z.exe' -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue),
    (Join-Path $env:ProgramFiles '7-Zip\7z.exe'),
    (Join-Path ([Environment]::GetFolderPath('ProgramFilesX86')) '7-Zip\7z.exe')
) | Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Leaf) } | Select-Object -First 1
if (-not $zip7) { throw 'Instale o 7-Zip antes de continuar.' }
$tmp = Join-Path ([IO.Path]::GetTempPath()) ('lumeron-acbr-' + [Guid]::NewGuid().ToString('N'))
$raiz = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
try {
    New-Item -ItemType Directory -Path $tmp -Force | Out-Null
    & $zip7 x -y "-o$tmp" $zip | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Falha na extracao do pacote ACBrLibNFe.' }
    $dll = Join-Path $tmp 'Windows\MT\Cdecl\ACBrNFe64.dll'
    $schemas = Join-Path $tmp 'dep\Schemas\NFe'
    $depsXml = Join-Path $tmp 'dep\LibXml2\x64'
    $depsSsl = Join-Path $tmp 'dep\OpenSSL\x64'
    foreach ($c in @($dll,$schemas,$depsXml,$depsSsl)) {
        if (-not (Test-Path -LiteralPath $c)) { throw "Arquivo exigido nao encontrado: $c" }
    }
    # Hash da DLL efetivamente inspecionada no pacote 1.5.0.490 enviado.
    $hash = (Get-FileHash -LiteralPath $dll -Algorithm SHA256).Hash
    if ($hash -ne 'A5C355C67913253DBCE28C7F4157C4460321440A0E73CDC66FCE28D938BE47BA') {
        throw 'DLL diferente da MT/Cdecl x64 verificada. Nao sera instalada automaticamente.'
    }
    $layout = Join-Path $schemas 'leiauteNFe_v4.00.xsd'
    $tipos = Join-Path $schemas 'DFeTiposBasicos_v1.00.xsd'
    if (-not (Test-Path $layout) -or -not (Test-Path $tipos)) {
        throw 'Schemas NFe 4.00 e IBS/CBS ausentes.'
    }
    if (-not ([IO.File]::ReadAllText($layout)).Contains('name="IBSCBS"') -or
        -not ([IO.File]::ReadAllText($tipos)).Contains('name="TTribNFe"')) {
        throw 'Schemas incompativeis com a NF-e RTC.'
    }
    $destDll = Join-Path $Destino 'ACBrNFe64.dll'
    $destSchema = Join-Path $Destino 'dep\Schemas\NFe'
    $destDeps = Join-Path $Destino 'deps\x64'
    foreach ($d in @($Destino,$destSchema,$destDeps)) {
        New-Item -ItemType Directory -Path $d -Force | Out-Null
    }
    if (Test-Path $destDll) {
        Copy-Item -LiteralPath $destDll -Destination ($destDll + '.backup-' + (Get-Date -Format 'yyyyMMddHHmmss')) -Force
    }
    Copy-Item -LiteralPath $dll -Destination $destDll -Force
    Copy-Item -Path (Join-Path $schemas '*') -Destination $destSchema -Recurse -Force
    Copy-Item -Path (Join-Path $depsXml '*.dll') -Destination $destDeps -Force
    Copy-Item -Path (Join-Path $depsSsl '*.dll') -Destination $destDeps -Force

    # PATH para este processo: o PHP worker tambem precisara localizar estas dependencias.
    $env:Path = $destDeps + ';' + $Destino + ';' + $env:Path
    if ($PersistirPath) {
        $partes = @([Environment]::GetEnvironmentVariable('Path','User') -split ';' | Where-Object { $_ })
        foreach ($pasta in @($Destino,$destDeps)) {
            if (-not ($partes | Where-Object { $_.TrimEnd('\') -ieq $pasta.TrimEnd('\') })) {
                $partes += $pasta
            }
        }
        [Environment]::SetEnvironmentVariable('Path',($partes -join ';'),'User')
    }

    if (-not $NaoConfigurarEnv) {
        $envFile = Join-Path $raiz '.env'
        if (-not (Test-Path -LiteralPath $envFile)) { throw 'Arquivo .env do Lumeron nao encontrado.' }
        Copy-Item -LiteralPath $envFile -Destination ($envFile + '.acbr-backup-' + (Get-Date -Format 'yyyyMMddHHmmss')) -Force
        $texto = [IO.File]::ReadAllText($envFile)
        $variaveis = @{
            ACBr_NFE_LIBRARY_PATH = ($destDll -replace '\','/')
            ACBr_NFE_SCHEMAS_PATH = ($destSchema -replace '\','/')
            ACBr_NFE_CONFIG_PATH = ((Join-Path $Destino 'acbr-nfe-diagnostico.ini') -replace '\','/')
        }
        foreach ($nome in @('ACBr_NFE_LIBRARY_PATH','ACBr_NFE_SCHEMAS_PATH','ACBr_NFE_CONFIG_PATH')) {
            $linha = $nome + '="' + $variaveis[$nome] + '"'
            $regex = '(?m)^' + [regex]::Escape($nome) + '=.*$'
            if ([regex]::IsMatch($texto,$regex)) {
                $texto = [regex]::Replace($texto,$regex,$linha)
            } else {
                $texto = $texto.TrimEnd([char]13,[char]10) + [Environment]::NewLine + $linha + [Environment]::NewLine
            }
        }
        [IO.File]::WriteAllText($envFile,$texto,(New-Object System.Text.UTF8Encoding($false)))
    }
    Write-Host 'ACBrLibNFe MT/Cdecl x64 verificada e instalada.' -ForegroundColor Green
    Write-Host ('DLL: ' + $destDll)
    Write-Host ('Schemas: ' + $destSchema)
    Write-Host 'No ERP: php artisan config:clear; php artisan acbr:nfe-doctor'
    if (-not $PersistirPath) { Write-Warning 'Dependencias no PATH apenas desta sessao; use -PersistirPath e reinicie Laragon/worker.' }
    Write-Warning 'Instalacao local NAO significa NF-e homologada pela SEFAZ. Producao continua bloqueada.'
} finally {
    if (Test-Path -LiteralPath $tmp) { Remove-Item -LiteralPath $tmp -Recurse -Force }
}
