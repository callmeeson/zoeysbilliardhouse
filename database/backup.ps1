<#
    Nightly database dump. Works with Laragon or XAMPP: mysqldump.exe is
    auto-detected (Laragon's versioned bin folder first, then XAMPP, then PATH)
    so the scheduled task keeps working after a MySQL version upgrade.
    Override with -Mysqldump if your stack lives somewhere else.
#>
param(
    [string]$Mysqldump,
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

if (-not $Mysqldump) {
    # Laragon keeps MySQL in a versioned folder (bin\mysql\mysql-8.4.3-winx64\bin);
    # sort descending so the newest version wins.
    foreach ($glob in @('C:\laragon\bin\mysql\*\bin\mysqldump.exe', 'C:\xampp\mysql\bin\mysqldump.exe')) {
        $hit = Get-ChildItem -Path $glob -ErrorAction SilentlyContinue |
               Sort-Object FullName -Descending | Select-Object -First 1
        if ($hit) { $Mysqldump = $hit.FullName; break }
    }
}
if (-not $Mysqldump) {
    $cmd = Get-Command mysqldump -ErrorAction SilentlyContinue
    if ($cmd) { $Mysqldump = $cmd.Source }
}
if (-not $Mysqldump -or -not (Test-Path -LiteralPath $Mysqldump)) {
    Write-Error 'mysqldump.exe not found. Looked in Laragon (C:\laragon\bin\mysql\*\bin), XAMPP (C:\xampp\mysql\bin) and PATH. Pass -Mysqldump "<full path>".'
    exit 1
}
Write-Host "Using mysqldump: $Mysqldump"


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