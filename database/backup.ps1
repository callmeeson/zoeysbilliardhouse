param(
    [string]$Mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$DbHost = $env:ZB_DB_HOST,
    [string]$DbName = $env:ZB_DB_NAME,
    [string]$DbUser = $env:ZB_DB_USER,
    [string]$DbPass = $env:ZB_DB_PASS,
    [int]$KeepDays = 14
)
$ErrorActionPreference = 'Stop'

if (-not $DbHost) { $DbHost = 'localhost' }
if (-not $DbName) { $DbName = 'zoeys_billiard' }
if (-not $DbUser) { $DbUser = 'root' }

$outDir = Join-Path (Split-Path $PSScriptRoot -Parent) 'backups'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$outFile = Join-Path $outDir ("$DbName`_$stamp.sql")

$args = @("--host=$DbHost", "--user=$DbUser", '--single-transaction', '--routines', '--skip-extended-insert', $DbName)
if ($DbPass) { $args += "--password=$DbPass" }

& $Mysqldump @args *> $outFile
if ($LASTEXITCODE -ne 0) {
    Remove-Item -Force $outFile -ErrorAction SilentlyContinue
    Write-Error "mysqldump failed (exit $LASTEXITCODE). No backup written."
    exit 1
}
Write-Host "Backup written: $outFile"

$cutoff = (Get-Date).AddDays(-$KeepDays)
Get-ChildItem $outDir -Filter '*.sql' -File |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object { Remove-Item -Force $_.FullName; Write-Host "Removed old backup: $($_.Name)" }