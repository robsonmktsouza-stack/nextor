# Inicia o Lumeron ERP em rede local para testes da interface mobile.
# Executar no terminal do Laragon/PowerShell, a partir de qualquer pasta:
# powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\Start-Lumeron-Mobile.ps1
# Nao altera branch, banco, ambiente .env, firewall ou configuracoes fiscais.

param(
    [ValidateRange(1024,65535)]
    [int]$Port = 8001
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $root

try {
    if (-not (Test-Path (Join-Path $root 'artisan'))) {
        throw 'A pasta selecionada nao contem o arquivo artisan do Lumeron.'
    }

    $git = Get-Command 'git' -ErrorAction SilentlyContinue
    if (-not $git) {
        throw 'Git nao encontrado. Abra o terminal do Laragon ou instale o Git no Windows.'
    }

    $branch = (& git branch --show-current 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $branch -ne 'feature/lumeron-mobile') {
        throw ("Branch atual: '{0}'. Entre antes na feature/lumeron-mobile (git fetch origin; git switch feature/lumeron-mobile; git pull --ff-only origin feature/lumeron-mobile). Nenhum arquivo foi alterado." -f $branch)
    }

    if (-not (Test-Path (Join-Path $root '.env'))) {
        throw 'O arquivo .env local nao existe. Configure a instalacao de desenvolvimento antes de iniciar.'
    }
    if (-not (Test-Path (Join-Path $root 'vendor/autoload.php'))) {
        throw 'Dependencias Composer ausentes. Execute: composer install'
    }

    $php = Get-Command 'php' -ErrorAction SilentlyContinue
    if (-not $php) {
        throw 'PHP nao encontrado. Use o terminal do Laragon com o PHP no PATH.'
    }

    $listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    if ($listener) {
        throw ("A porta {0} ja esta em uso. Verifique se o Laravel ja esta rodando; senao, finalize o processo ou use -Port 8002." -f $Port)
    }

    Write-Host ''
    Write-Host '===============================================' -ForegroundColor Cyan
    Write-Host ' LUMERON MOBILE - TESTE NA REDE LOCAL' -ForegroundColor Cyan
    Write-Host '===============================================' -ForegroundColor Cyan
    Write-Host ('Branch: {0}' -f $branch)
    Write-Host ('Diretorio: {0}' -f $root)
    Write-Host ''
    Write-Host 'No computador:'
    Write-Host ("  http://127.0.0.1:{0}/login" -f $Port) -ForegroundColor Green
    Write-Host ''
    Write-Host 'No celular (mesmo Wi-Fi do computador):'

    $ips = @()
    try {
        $ips = @(Get-NetIPConfiguration -ErrorAction Stop |
            Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address } |
            ForEach-Object { $_.IPv4Address.IPAddress } |
            Where-Object {
                $_ -match '^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)'
            } |
            Select-Object -Unique)
    } catch {
        # O servidor tambem funciona sem deteccao automatica do IP.
    }

    if ($ips.Count -eq 0) {
        Write-Host '  Nao foi possivel detectar o IPv4. Consulte ipconfig e procure IPv4 do Wi-Fi.' -ForegroundColor Yellow
        Write-Host ("  http://SEU-IP:{0}/login" -f $Port) -ForegroundColor Yellow
    } else {
        foreach ($ip in $ips) {
            Write-Host ("  http://{0}:{1}/login" -f $ip, $Port) -ForegroundColor Green
        }
    }

    Write-Host ''
    Write-Host 'No login local, mantenha o subdominio preselecionado (geralmente: local).' -ForegroundColor Gray
    Write-Host 'HTTP LAN testa a interface, mas a PWA so pode instalar com HTTPS valido.' -ForegroundColor Yellow
    Write-Host 'Use somente uma rede Wi-Fi confiavel e dados de teste.' -ForegroundColor Yellow
    Write-Host 'Se o Firewall do Windows perguntar, permita somente Redes privadas.' -ForegroundColor Yellow
    Write-Host 'Pressione Ctrl+C para desligar o servidor.' -ForegroundColor Gray
    Write-Host ''
    & php artisan serve --host=0.0.0.0 --port=$Port
    if ($LASTEXITCODE -ne 0) {
        throw ("Servidor PHP encerrou com codigo {0}." -f $LASTEXITCODE)
    }
} finally {
    Pop-Location
}
