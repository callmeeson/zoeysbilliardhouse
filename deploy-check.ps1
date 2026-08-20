<#
    Zoeys Billiard House - local deployment readiness check

    Read-only diagnostic. Changes nothing; just reports whether this machine
    can serve the app right now. Works with Laragon or XAMPP (auto-detected).

    Usage:    powershell -NoProfile -ExecutionPolicy Bypass -File deploy-check.ps1
    Options:  -DbUser root      MySQL user   (default: ZB_DB_USER or root)
              -DbPass ""        MySQL pass   (default: ZB_DB_PASS or empty)
              -DbName           database     (default: ZB_DB_NAME or zoeys_billiard)
              -BaseUrl          override the URL to probe
              -SkipApi          do not run the authenticated API smoke test

    Exit code: 0 = ready, 1 = blocking problem found.
#>
param(
    [string]$DbUser = $env:ZB_DB_USER,
    [string]$DbPass = $env:ZB_DB_PASS,
    [string]$DbName = $env:ZB_DB_NAME,
    [string]$BaseUrl,
    [switch]$SkipApi
)
$ErrorActionPreference = 'Continue'
if (-not $DbUser) { $DbUser = 'root' }
if (-not $DbName) { $DbName = 'zoeys_billiard' }

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$FolderName  = Split-Path -Leaf $ProjectRoot
if (-not $BaseUrl) { $BaseUrl = "http://localhost/$FolderName/" }
if (-not $BaseUrl.EndsWith('/')) { $BaseUrl += '/' }

$script:Fails = 0
$script:Warns = 0
function Section([string]$m) { Write-Host ''; Write-Host "== $m" -ForegroundColor Cyan }
function Pass([string]$m)    { Write-Host "  [ OK ] $m" -ForegroundColor Green }
function Warn([string]$m)    { Write-Host "  [WARN] $m" -ForegroundColor Yellow; $script:Warns++ }
function Fail([string]$m)    { Write-Host "  [FAIL] $m" -ForegroundColor Red;    $script:Fails++ }
function Info([string]$m)    { Write-Host "         $m" -ForegroundColor DarkGray }

# ---------------------------------------------------------------------------
# Tool discovery: Laragon first (versioned subfolders), then XAMPP, then PATH.
# ---------------------------------------------------------------------------
function Find-Tool([string]$exe) {
    $globs = @(
        "C:\laragon\bin\mysql\*\bin\$exe",
        "C:\laragon\bin\php\*\$exe",
        "C:\xampp\mysql\bin\$exe",
        "C:\xampp\php\$exe"
    )
    foreach ($g in $globs) {
        $hit = Get-ChildItem -Path $g -ErrorAction SilentlyContinue |
               Sort-Object FullName -Descending | Select-Object -First 1
        if ($hit) { return $hit.FullName }
    }
    $cmd = Get-Command ([IO.Path]::GetFileNameWithoutExtension($exe)) -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    return $null
}

Write-Host ''
Write-Host '===============================================' -ForegroundColor Cyan
Write-Host ' Zoeys Billiard House - deployment readiness' -ForegroundColor Cyan
Write-Host '===============================================' -ForegroundColor Cyan
Info "Project : $ProjectRoot"
Info "App URL : $BaseUrl"

# ---- 1. Web server --------------------------------------------------------
Section 'Web server'
$httpd = Get-Process httpd  -ErrorAction SilentlyContinue
$nginx = Get-Process nginx  -ErrorAction SilentlyContinue
if ($httpd)     { Pass "Apache running ($($httpd.Count) process(es))" }
elseif ($nginx) { Warn "Nginx is running but Apache is not - .htaccess rewrites (/api, /assets) will NOT work. Switch Laragon to Apache." }
else            { Fail 'No web server running. Start Apache (Laragon: Menu > Apache > Start, or "Start All").' }

# ---- 2. PHP ---------------------------------------------------------------
Section 'PHP'
$php = Find-Tool 'php.exe'
if (-not $php) {
    Warn 'php.exe not found for CLI checks (the web server may still have PHP). Skipping PHP checks.'
} else {
    $ver = (& $php -r 'echo PHP_VERSION;' 2>$null)
    if ($ver -match '^(\d+)\.(\d+)') {
        $maj = [int]$Matches[1]; $min = [int]$Matches[2]
        if ($maj -gt 8 -or ($maj -eq 8 -and $min -ge 1)) { Pass "PHP $ver" } else { Fail "PHP $ver is too old - 8.1+ required." }
    } else { Warn 'Could not determine the PHP version.' }
    Info $php

    $mods = @(& $php -m 2>$null)
    foreach ($m in @('pdo_mysql', 'curl', 'gd', 'mbstring', 'json', 'zip')) {
        if ($mods -contains $m) { Pass "extension: $m" } else { Fail "extension missing: $m (enable it in php.ini)" }
    }

    $bad = 0; $n = 0
    Get-ChildItem -Path $ProjectRoot -Recurse -File -Include *.php |
        Where-Object { $_.FullName -notmatch '\\(node_modules|dist)\\' } |
        ForEach-Object {
            $n++
            $out = & $php -l $_.FullName 2>&1
            if ($LASTEXITCODE -ne 0) { $bad++; Fail "syntax error: $($_.FullName)"; Info ($out -join ' ') }
        }
    if ($bad -eq 0) { Pass "PHP syntax clean ($n files)" }
}

# ---- 3. Database ----------------------------------------------------------
Section 'Database'
$mysql = Find-Tool 'mysql.exe'
$dbOk  = $false
if (-not $mysql) {
    Fail 'mysql.exe not found (looked in Laragon, XAMPP and PATH). Cannot verify the database.'
} else {
    Info $mysql
    $args = @("--user=$DbUser"); if ($DbPass) { $args += "--password=$DbPass" }
    $sv = @(& $mysql @args -N -e 'SELECT VERSION()' 2>$null)
    if ($LASTEXITCODE -ne 0 -or $sv.Count -eq 0) {
        Fail "Cannot connect to MySQL as '$DbUser'. Is MySQL started? (Laragon: Menu > MySQL > Start)"
    } else {
        Pass "MySQL reachable (server $($sv[0]))"
        $tc = @(& $mysql @args -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DbName'" 2>$null)
        $count = if ($tc.Count) { [int]$tc[0] } else { 0 }
        if ($count -eq 0) {
            Fail "Database '$DbName' is empty or missing. Import database\install.sql (or run install.ps1)."
        } else {
            Pass "Database '$DbName' has $count tables"
            $dbOk = $true
        }
    }

    if ($dbOk) {
        # Schema drift: columns the current code requires (added by migrate_*.sql).
        $required = @(
            @{ t = 'sales';             c = 'added_missing';   m = 'migrate_sales_corrections.sql' },
            @{ t = 'sales';             c = 'edited_at';       m = 'migrate_sales_corrections.sql' },
            @{ t = 'sales';             c = 'reference';       m = 'migrate_sales_reference.sql' },
            @{ t = 'billiard_sessions'; c = 'karaoke';         m = 'migrate_remove_ktv_add_karaoke.sql' },
            @{ t = 'billiard_sessions'; c = 'extended_hours';  m = 'migrate_add_extended_hours.sql' },
            @{ t = 'billiard_sessions'; c = 'free_hour_used';  m = 'migrate_loyalty.sql' },
            @{ t = 'tables';            c = 'type';            m = 'migrate_add_table_type.sql' },
            @{ t = 'users';             c = 'last_login';      m = 'migrate_users_last_login.sql' }
        )
        $missing = 0
        foreach ($r in $required) {
            $q = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$DbName' AND table_name='$($r.t)' AND column_name='$($r.c)'"
            $hit = @(& $mysql @args -N -e $q 2>$null)
            if (-not $hit.Count -or [int]$hit[0] -eq 0) { $missing++; Fail "missing column $($r.t).$($r.c) - apply database\$($r.m)" }
        }
        if ($missing -eq 0) { Pass 'Schema up to date (all required migrations applied)' }

        foreach ($t in @('seq_sales_reference', 'shifts', 'promos', 'login_attempts', 'audit_logs', 'suppliers')) {
            $hit = @(& $mysql @args -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DbName' AND table_name='$t'" 2>$null)
            if (-not $hit.Count -or [int]$hit[0] -eq 0) { Fail "missing table: $t" }
        }

        $stuck = @(& $mysql @args -N -e "SELECT COUNT(*) FROM $DbName.billiard_sessions WHERE status='open'" 2>$null)
        if ($stuck.Count -and [int]$stuck[0] -gt 0) { Warn "$($stuck[0]) billiard session(s) still open - close them before a clean handover." }

        $cats = @(& $mysql @args -N -e "SELECT COUNT(*) FROM $DbName.categories" 2>$null)
        if ($cats.Count -and [int]$cats[0] -eq 0) { Warn 'No product categories exist - the POS category filter will be empty.' }

        $test = @(& $mysql @args -N -e "SELECT COUNT(*) FROM $DbName.products WHERE name LIKE '\_\_%'" 2>$null)
        if ($test.Count -and [int]$test[0] -gt 0) { Warn "$($test[0]) test/demo product(s) still in the catalog (names starting with __)." }
    }
}

# ---- 4. Frontend build ----------------------------------------------------
Section 'Frontend build'
$distIndex = Join-Path $ProjectRoot 'dist\index.html'
if (-not (Test-Path -LiteralPath $distIndex)) {
    Fail 'dist\index.html missing - run "npm run build".'
} else {
    $built = (Get-Item -LiteralPath $distIndex).LastWriteTime
    Pass "dist\index.html present (built $built)"
    $assets = Get-ChildItem -Path (Join-Path $ProjectRoot 'dist\assets') -File -ErrorAction SilentlyContinue
    if (-not $assets -or $assets.Count -eq 0) { Fail 'dist\assets is empty - rebuild with "npm run build".' }
    else { Pass "dist\assets has $($assets.Count) files" }

    # Every asset referenced by index.html must actually exist on disk.
    $html = Get-Content -Raw -LiteralPath $distIndex
    $refs = [regex]::Matches($html, '(?:src|href)="\./(assets/[^"]+)"') | ForEach-Object { $_.Groups[1].Value }
    $broken = @($refs | Where-Object { -not (Test-Path -LiteralPath (Join-Path $ProjectRoot ("dist\" + $_.Replace('/', '\')))) })
    if ($broken.Count -gt 0) { $broken | ForEach-Object { Fail "index.html references a missing asset: $_" } }
    elseif ($refs.Count -gt 0) { Pass "index.html asset references resolve ($($refs.Count))" }

    $stale = @(Get-ChildItem -Path (Join-Path $ProjectRoot 'src') -Recurse -File -ErrorAction SilentlyContinue |
               Where-Object { $_.LastWriteTime -gt $built })
    if ($stale.Count -gt 0) {
        Warn "$($stale.Count) file(s) in src\ are newer than the build - run 'npm run build'."
        $stale | Select-Object -First 3 | ForEach-Object { Info "newer: $($_.Name) ($($_.LastWriteTime))" }
    } else { Pass 'Build is newer than every source file' }
}
if (Test-Path -LiteralPath (Join-Path $ProjectRoot 'node_modules')) { Pass 'node_modules installed' }
else { Warn 'node_modules missing - "npm install" needed before you can rebuild.' }

# ---- 5. Files and folders -------------------------------------------------
Section 'Files and folders'
foreach ($f in @('index.php', 'config.php', '.htaccess', 'database\install.sql')) {
    if (Test-Path -LiteralPath (Join-Path $ProjectRoot $f)) { Pass "present: $f" } else { Fail "missing: $f" }
}
foreach ($d in @('uploads', 'backups')) {
    $p = Join-Path $ProjectRoot $d
    if (-not (Test-Path -LiteralPath $p)) { Warn "folder '$d' does not exist yet (it is created on first use)." }
    else {
        try {
            $probe = Join-Path $p ('.write_probe_' + [guid]::NewGuid().ToString('N') + '.tmp')
            [IO.File]::WriteAllText($probe, 'x'); Remove-Item -LiteralPath $probe -Force
            Pass "writable: $d\"
        } catch { Fail "folder '$d\' is not writable by this account." }
    }
}

# ---- 6. HTTP ---------------------------------------------------------------
Section 'HTTP responses'
$httpOk = $false
try {
    $r = Invoke-WebRequest -UseBasicParsing -Uri $BaseUrl -TimeoutSec 15
    if ($r.StatusCode -eq 200 -and $r.Content -match 'id="app"') { Pass "app root 200 and serves the SPA shell"; $httpOk = $true }
    elseif ($r.StatusCode -eq 200) { Warn 'app root returned 200 but does not look like the SPA shell.' ; $httpOk = $true }
    else { Fail "app root returned HTTP $($r.StatusCode)." }
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    if ($code) { Fail "app root returned HTTP $code." } else { Fail "cannot reach $BaseUrl - is Apache running and the folder inside the web root?" }
}

if ($httpOk) {
    # .htaccess hardening must actually be in effect (needs AllowOverride + mod_rewrite).
    foreach ($path in @('config.php', 'includes/auth.php', 'database/install.sql')) {
        $code = 0
        try { $null = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + $path) -TimeoutSec 10; $code = 200 }
        catch { $code = $_.Exception.Response.StatusCode.value__ }
        if ($code -eq 403 -or $code -eq 404) { Pass "blocked from the web: /$path ($code)" }
        else { Fail "/$path is web-readable (HTTP $code) - .htaccess is not being applied (check AllowOverride All + mod_rewrite)." }
    }

    $code = 0
    try { $rr = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + 'api/ajax/auth.php?action=me') -TimeoutSec 10; $code = $rr.StatusCode }
    catch { $code = $_.Exception.Response.StatusCode.value__ }
    if ($code -eq 200 -or $code -eq 401) { Pass "API rewrite works: /api/ajax/... -> ajax/ ($code)" }
    else { Fail "API rewrite not working (HTTP $code on /api/ajax/auth.php) - mod_rewrite/.htaccess problem." }
}

# ---- 7. API smoke test ----------------------------------------------------
if (-not $SkipApi -and $httpOk) {
    Section 'API smoke test'
    $sess = $null
    $loggedIn = $false
    foreach ($cred in @(
        @{ u = 'superadmin'; p = 'SuperAdmin@123' },
        @{ u = 'admin';      p = 'admin123' }
    )) {
        if ($loggedIn) { break }
        try {
            $body = "action=login&username=$($cred.u)&password=" + [uri]::EscapeDataString($cred.p)
            $lr = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + 'api/ajax/auth.php') -Method Post -Body $body `
                    -ContentType 'application/x-www-form-urlencoded' -SessionVariable s -TimeoutSec 20
            if ($lr.Content -match '"ok"\s*:\s*true') {
                $sess = $s; $loggedIn = $true
                Warn "Default password still active for '$($cred.u)' - change it before real use (Users > reset password)."
            }
        } catch { }
    }
    if (-not $loggedIn) {
        Info 'Seeded passwords no longer work (good) - skipping the authenticated smoke test.'
        Info 'Re-run with your own credentials if you want the endpoint checks.'
    } else {
        $endpoints = @(
            'ajax/tables.php?action=list',
            'ajax/products.php?action=list',
            'ajax/products.php?action=categories',
            'ajax/products.php?action=suppliers',
            'ajax/pos.php?action=products',
            'ajax/pos.php?action=categories',
            'ajax/customers.php?action=search',
            'ajax/reservations.php?action=list',
            'ajax/promos.php?action=list',
            'ajax/users.php?action=list',
            'ajax/audit.php?action=list',
            'ajax/notifications.php?action=list',
            'ajax/settings.php?action=get',
            'ajax/settings.php?action=shifts',
            'ajax/reports.php?action=summary',
            'ajax/reports.php?action=transactions',
            'ajax/reports.php?action=inventory',
            'ajax/reports.php?action=products',
            'ajax/reports.php?action=table_dead_time'
        )
        $bad = 0
        foreach ($e in $endpoints) {
            try {
                $r = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + 'api/' + $e) -WebSession $sess -TimeoutSec 30
                if ($r.Content -match '"ok"\s*:\s*true') { Pass $e } else { $bad++; Fail "$e -> unexpected payload" }
            } catch { $bad++; Fail "$e -> HTTP $($_.Exception.Response.StatusCode.value__)" }
        }
        if ($bad -eq 0) { Pass "all $($endpoints.Count) endpoints responded ok" }
        try { $null = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + 'api/ajax/auth.php') -Method Post -Body 'action=logout' `
                        -ContentType 'application/x-www-form-urlencoded' -WebSession $sess -TimeoutSec 10 } catch { }
    }
}

# ---- 8. Deployment hygiene ------------------------------------------------
Section 'Deployment hygiene'
$zbEnv = [Environment]::GetEnvironmentVariable('ZB_ENV')
if ($zbEnv -eq 'production') {
    Pass 'ZB_ENV=production (PHP errors hidden)'
} else {
    $shown = if ([string]::IsNullOrEmpty($zbEnv)) { 'unset' } else { $zbEnv }
    Warn "ZB_ENV is '$shown' - PHP errors are shown on screen. Set ZB_ENV=production for live use."
}


$dump = Find-Tool 'mysqldump.exe'
if ($dump) { Pass "mysqldump available for backups"; Info $dump }
else { Warn 'mysqldump.exe not found - database\backup.ps1 cannot run. Use Settings > Backup in the app instead.' }

$task = & schtasks /query /tn 'ZoeysDB' 2>$null
if ($LASTEXITCODE -eq 0) { Pass 'scheduled backup task "ZoeysDB" exists' }
else { Warn 'no nightly backup task registered (see README section 5).' }

if (Get-Command git -ErrorAction SilentlyContinue) {
    Push-Location $ProjectRoot
    $dirty = @(& git status --porcelain 2>$null)
    Pop-Location
    if ($dirty.Count -gt 0) { Warn "$($dirty.Count) uncommitted change(s) in git - the running code is not committed." }
    else { Pass 'git working tree clean' }
}

# ---- Verdict --------------------------------------------------------------
Write-Host ''
Write-Host '===============================================' -ForegroundColor Cyan
if ($script:Fails -eq 0 -and $script:Warns -eq 0) {
    Write-Host ' READY - no problems found.' -ForegroundColor Green
} elseif ($script:Fails -eq 0) {
    Write-Host " READY with $($script:Warns) warning(s) - safe to run, review the [WARN] lines." -ForegroundColor Yellow
} else {
    Write-Host " NOT READY - $($script:Fails) blocking problem(s), $($script:Warns) warning(s)." -ForegroundColor Red
}
Write-Host '===============================================' -ForegroundColor Cyan
Write-Host " Open: $BaseUrl"
Write-Host ''
if ($script:Fails -gt 0) { exit 1 } else { exit 0 }


