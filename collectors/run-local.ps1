<#
Run a collector on Windows without Docker, against the local Laravel app
(php artisan serve on http://127.0.0.1:8000).

Settings come from collectors\.env.local (copy .env.local.example; the file is
gitignored and must never be committed).

  .\run-local.ps1 telegram.login          # first Telegram login: you type phone + OTP
  .\run-local.ps1 telegram.listener       # read-only Telegram listener (Ctrl+C to stop)
  .\run-local.ps1 forum.expat_com --once  # one Expat.com round
  .\run-local.ps1 facebook.run --once     # one Apify round
#>
param(
    [Parameter(Mandatory = $true, Position = 0)][string]$Module,
    [Parameter(ValueFromRemainingArguments = $true)][string[]]$Rest
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$envFile = Join-Path $PSScriptRoot '.env.local'
if (-not (Test-Path $envFile)) {
    Write-Error "Missing $envFile. Copy .env.local.example to .env.local and fill it in."
}

foreach ($line in Get-Content -Path $envFile -Encoding UTF8) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
    $pair = $trimmed -split '=', 2
    if ($pair.Count -eq 2) {
        $value = ($pair[1] -replace '\s+#.*$', '').Trim().Trim('"')
        [Environment]::SetEnvironmentVariable($pair[0].Trim(), $value, 'Process')
    }
}

$dataDir = Join-Path (Split-Path $PSScriptRoot -Parent) 'data'
New-Item -ItemType Directory -Force -Path (Join-Path $dataDir 'spool') | Out-Null
if (-not $env:SPOOL_DIR) { $env:SPOOL_DIR = Join-Path $dataDir 'spool' }
if (-not $env:TG_SESSION_PATH) { $env:TG_SESSION_PATH = Join-Path $dataDir 'telegram.session' }
$env:PYTHONIOENCODING = 'utf-8'

& (Join-Path $PSScriptRoot '.venv\Scripts\python.exe') -m $Module @Rest
exit $LASTEXITCODE
