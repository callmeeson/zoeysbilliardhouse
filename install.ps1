<#
    Zoeys Billiard House - one-tap installer (Windows + XAMPP)

    Checks prerequisites, creates/imports the database (never overwrites
    existing data), builds the frontend and opens the app.

    Usage:        powershell -File install.ps1
    Options:      -DbUser root        MySQL user (default: root / ZB_DB_USER)
                  -DbPass ""          MySQL password (default: empty / ZB_DB_PASS)
                  -XamppRoot C:\xampp (default)
                  -ForceRebuild       run npm install + npm run build even when fresh
                  -Reinstall          DESTRUCTIVE: drop DB and re-import seeds (asks first)
                  -NoBrowser          do not open the browser at the end
#>
param(
    [string]$DbUser = $env:ZB_DB_USER,
    [string]$DbPass = $env:ZB_DB_PASS,
    [string]$XamppRoot = 'C:\xampp',
    [switch]$ForceRebuild,
    [switch]$Reinstall,
    [switch]$NoBrowser
)
$ErrorActionPreference = 'Stop'
if (-not $DbUser) { $DbUser = 'root' }

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$FolderName  = Split-Path -Leaf $ProjectRoot
$MysqlCli    = Join-Path $XamppRoot 'mysql\bin\mysql.exe'
$InstallSql  = Join-Path $ProjectRoot 'database\install.sql'
$BaseUrl     = "http://localhost/$FolderName/"

function Step([string]$msg)  { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Ok([string]$msg)    { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Warn([string]$msg)  { Write-Host "  [!]  $msg" -ForegroundColor Yellow }
function Fail([string]$msg)  { Write-Host "  [X]  $msg" -ForegroundColor Red; Write-Host "`nInstallation aborted." -ForegroundColor Red; exit 1 }

$mysqlArgs = @("--user=$DbUser")
if ($DbPass) { $mysqlArgs += "--password=$DbPass" }

Write-Host ""
Write-Host "=============================================" -ForegroundColor Cyan
Write-Host " Zoeys Billiard House - Installation" -ForegroundColor Cyan
Write-Host "=============================================" -ForegroundColor Cyan
Write-Host " Project : $ProjectRoot"
Write-Host " App URL : $BaseUrl"

# ---- 1. Prerequisites -----------------------------------------------------
Step "Checking prerequisites"

if (-not (Test-Path -LiteralPath $MysqlCli)) {
    if (Get-Command mysql -ErrorAction SilentlyContinue) { $MysqlCli = (Get-Command mysql).Source }
    else { Fail "MySQL client not found in '$MysqlCli'. Start XAMPP (or pass -XamppRoot <path>)." }
}
Ok "MySQL client: $MysqlCli"

$mysql = @()
try { $mysql = & $MysqlCli @mysqlArgs -N -e 'SELECT 1' 2>$null }
catch { $mysql = @() }
if ($LASTEXITCODE -ne 0 -or $mysql.Count -eq 0) {
    Fail "Cannot reach MySQL as user '$DbUser'. Is MySQL running in the XAMPP Control Panel? Does the user/password exist? (retry with -DbUser/-DbPass)"
}
Ok "MySQL server reachable"

$hasNode = [bool](Get-Command node -ErrorAction SilentlyContinue)
$hasNpm  = [bool](Get-Command npm  -ErrorAction SilentlyContinue)
$hasDist = Test-Path -LiteralPath (Join-Path $ProjectRoot 'dist\index.html')
if (-not $hasNode -or -not $hasNpm) {
    if (-not $hasDist) {
        Fail "Node.js/npm not found and no built frontend exists. Install Node.js 18+ from https://nodejs.org, then rerun."
    }
    Warn "Node.js/npm not found - skipping rebuild (existing dist/ will be used)."
} else {
    Ok "Node: $(node --version)  npm: $(npm --version)"
}

# ---- 2. Database ----------------------------------------------------------
Step "Preparing database"

$tables = @(& $MysqlCli @mysqlArgs -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'zoeys_billiard'" 2>$null)
$hasDb   = -not ($LASTEXITCODE -ne 0) -and $tables.Count -gt 0 -and [int]$tables[0] -gt 0

if ($hasDb) {
    if ($Reinstall) {
        $ans = Read-Host "  Database zoeys_billiard already has $($tables[0]) tables. RE-INSTALL DROPS ALL DATA. Type 'RESET' to continue"
        if ($ans -ne 'RESET') { Fail 'Aborted by user.' }
        @(Get-Content -LiteralPath $InstallSql) | & $MysqlCli @mysqlArgs
        if ($LASTEXITCODE -ne 0) { Fail 'Could not import database schema.' }
        Ok 'Database dropped and re-imported (fresh seeds).'
    } else {
        Warn "Database zoeys_billiard already has $($tables[0]) tables - existing data preserved, skipping import."
        Warn 'To wipe and reseed anyway, rerun with: -Reinstall'
    }
} else {
    & $MysqlCli @mysqlArgs -e "CREATE DATABASE IF NOT EXISTS zoeys_billiard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    if ($LASTEXITCODE -ne 0) { Fail 'Could not create database zoeys_billiard.' }
    @(Get-Content -LiteralPath $InstallSql) | & $MysqlCli @mysqlArgs
    if ($LASTEXITCODE -ne 0) { Fail 'Could not import database schema.' }
    Ok 'Database created and seeded from install.sql.'
}

# ---- 3. Frontend build ----------------------------------------------------
if ($hasNode -and $hasNpm) {
    Step 'Building frontend'
    Push-Location $ProjectRoot
    try {
        if (-not (Test-Path node_modules) -or $ForceRebuild) {
            cmd /c "npm install --no-audit --no-fund" | Out-Host
            if ($LASTEXITCODE -ne 0) { Fail 'npm install failed (check internet / Node version).' }
        } else {
            Ok 'node_modules present (npm install skipped)'
        }
        if (-not $ForceRebuild -and $hasDist) {
            Warn 'dist/ already exists - run with -ForceRebuild to rebuild from source.'
        } else {
            cmd /c "npm run build" | Out-Host
            if ($LASTEXITCODE -ne 0) { Fail 'npm run build failed.' }
        }
    } finally {
        Pop-Location
    }
}

# ---- 4. Verify ------------------------------------------------------------
Step 'Verifying installation'
$appOk = $false
try {
    $resp = Invoke-WebRequest -UseBasicParsing -Uri $BaseUrl -TimeoutSec 10
    if ($resp.StatusCode -eq 200) { $appOk = $true }
} catch { $appOk = $false }
if ($appOk) {
    Ok "App responds at $BaseUrl"
} else {
    Warn "App not responding at $BaseUrl yet. If this is the first run, start Apache in the XAMPP Control Panel and retry."
}

try {
    $login = Invoke-RestMethod -Method Post -Uri "${BaseUrl}api/ajax/auth.php" -Body 'action=login&username=superadmin&password=SuperAdmin%40123' -ContentType 'application/x-www-form-urlencoded' -TimeoutSec 10
    if ($login.ok) {
        Ok "API + seeded login work (superadmin)."
        Warn "Change the seeded passwords now: admin123 / SuperAdmin@123 (Users page)."
    }
} catch {
    Warn 'API login check skipped (passwords may already have been changed - this is fine).'
}

# ---- 5. Done --------------------------------------------------------------
Write-Host ""
Write-Host "=============================================" -ForegroundColor Green
Write-Host " Installation finished" -ForegroundColor Green
Write-Host "=============================================" -ForegroundColor Green
Write-Host " Open: $BaseUrl"
Write-Host " Logins: superadmin / admin / staff  (see README.md)"
Write-Host ""
if (-not $NoBrowser) { Start-Process $BaseUrl }
