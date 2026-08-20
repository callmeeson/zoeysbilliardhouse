# Zoeys Billiard House Management System

Billiard house POS + management system. Vue 3 single-page frontend (Vite build, Tailwind CSS) with a PHP 8 + MySQL JSON API (`ajax/`).

## Requirements

| Software | Version |
|----------|---------|
| Laragon (Apache + PHP + MySQL) — recommended | PHP 8.1+ (tested on 8.3), MySQL 8.x |
| XAMPP (Apache + PHP + MySQL) — also supported | PHP 8.1+ |
| Node.js | 18+ (with `npm`) |
| Windows / macOS / Linux | any (Windows + Laragon assumed below) |

> **Apache is required** (not Nginx). The app relies on `.htaccess` for the SPA/API
> rewrites and the security rules. In Laragon: *Menu → Apache* must be the active web server.

## 0. Is my machine ready? (health check)

Double-click **`deploy-check.bat`** (or run `powershell -File deploy-check.ps1`) any time.
It changes nothing and reports one of `READY` / `NOT READY`, checking: Apache + MySQL running,
PHP version and extensions, PHP syntax, database present with all migrations applied,
`dist/` built and newer than `src/`, writable `uploads/`/`backups/`, the `.htaccess`
hardening/rewrites, an authenticated API smoke test, and deployment hygiene
(`ZB_ENV`, mysqldump, nightly backup task, uncommitted git changes).

```powershell
powershell -File deploy-check.ps1                     # full check
powershell -File deploy-check.ps1 -SkipApi            # skip the API smoke test
powershell -File deploy-check.ps1 -DbUser root -DbPass "secret"
```

## 1. Fresh installation

### 1.0 One-tap installer (Windows)

Double-click **`install.bat`** (or run `powershell -File install.ps1`) in the project
folder. It finds MySQL automatically (Laragon → XAMPP → PATH), creates + seeds the
database (never touches existing data), builds the frontend, runs a login check and
opens the app.

```powershell
powershell -File install.ps1                 # normal run
powershell -File install.ps1 -Reinstall      # DESTRUCTIVE: wipe DB + reseed (asks first)
powershell -File install.ps1 -ForceRebuild   # force npm install + rebuild frontend
powershell -File install.ps1 -DbUser root -DbPass "secret"
powershell -File install.ps1 -MysqlCli "D:\mysql\bin\mysql.exe"   # non-standard install
```

### 1.1 Copy the project

Place the project folder into the web root:

```
C:\laragon\www\ZoeysBilliardHouseManagementSystem      (Laragon)
C:\xampp\htdocs\ZoeysBilliardHouseManagementSystem     (XAMPP)
```

The app is then reachable at `http://localhost/ZoeysBilliardHouseManagementSystem/`.
Laragon also auto-creates a pretty host: `http://zoeysbilliardhousemanagementsystem.test/`
(use *Menu → Reload* if it doesn't appear). `config.php` detects the host/folder
automatically — no URL editing needed. If you move it elsewhere (or use HTTPS),
everything still works.

### 1.2 Start the services

- **Laragon:** click **Start All** (Apache + MySQL both green).
- **XAMPP:** start **Apache** and **MySQL** in the Control Panel.

### 1.3 Create the database

Open **Laragon → Terminal** (its PATH already contains `mysql`) and import the base schema:

```powershell
mysql -u root -e "CREATE DATABASE IF NOT EXISTS zoeys_billiard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root zoeys_billiard < database\install.sql
```

From a normal PowerShell window (no Laragon PATH), call the binary directly — Laragon
keeps MySQL in a versioned folder, so tab-complete the version:

```powershell
$mysql = (Get-ChildItem 'C:\laragon\bin\mysql\*\bin\mysql.exe' | Select-Object -Last 1).FullName
& $mysql -u root -e "CREATE DATABASE IF NOT EXISTS zoeys_billiard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content "database\install.sql" | & $mysql -u root zoeys_billiard
```

<details>
<summary>XAMPP equivalent</summary>

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS zoeys_billiard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content "C:\xampp\htdocs\ZoeysBilliardHouseManagementSystem\database\install.sql" | & "C:\xampp\mysql\bin\mysql.exe" -u root zoeys_billiard
```
</details>

You can also import `database\install.sql` through **HeidiSQL** (bundled with Laragon)
or phpMyAdmin.

> Using a different MySQL user or password? See [Configuration](#3-configuration) — the API reads
> `ZB_DB_USER` / `ZB_DB_PASS` from environment variables.

### 1.4 Install frontend dependencies and build

```powershell
cd C:\laragon\www\ZoeysBilliardHouseManagementSystem
npm install
npm run build
```

This creates the production bundle in `dist/` (includes all tables, settings and the
seeded users). PHP does **not** need a restart — plain files.

### 1.5 Verify

Open `http://localhost/ZoeysBilliardHouseManagementSystem/` and sign in with a seeded account:

| Role | Username | Password |
|------|----------|----------|
| Super Admin | `superadmin` | `SuperAdmin@123` |
| Admin | `admin` | `admin123` |
| Staff | `staff` | `admin123` |

> **Important:** change these passwords immediately (Users → reset password) before real use.
> `deploy-check.ps1` warns while any seeded password still works.

## 2. Upgrading an existing install

1. **Back up first** — run the backup script (see [Backups](#5-backups)).
2. Replace the code files (keep `uploads/`, `backups/`).
3. Apply any pending migrations from `database/migrate_*.sql` **in filename order**:
   ```powershell
   mysql -u root zoeys_billiard < database\migrate_shifts.sql
   ```
   (`deploy-check.ps1` names the exact migration file for any column it finds missing.)
4. Rebuild the frontend: `npm run build`.
5. Verify with a hard refresh (Ctrl+F5), then run `deploy-check.bat`.

## 3. Configuration

All settings live in `config.php` and can be overridden with environment variables — no code edits
on the server:

| Env var | Default | Purpose |
|---------|---------|---------|
| `ZB_ENV` | `development` | `production` hides PHP error output |
| `ZB_DB_HOST` | `localhost` | MySQL host |
| `ZB_DB_NAME` | `zoeys_billiard` | Database name |
| `ZB_DB_USER` | `root` | MySQL user |
| `ZB_DB_PASS` | *(empty)* | MySQL password |
| `ZB_ALLOWED_HOSTS` | *(empty)* | Comma-separated host whitelist for `BASE_URL` |

`BASE_URL` is derived from the request automatically (scheme + host + folder).

On Laragon/XAMPP the value PHP sees comes from Apache, so set it in the vhost
(`C:\laragon\etc\apache2\sites-enabled\auto.ZoeysBilliardHouseManagementSystem.test.conf`)
and reload Apache:

```apache
SetEnv ZB_ENV production
```

Business name, address, phone, receipt logo, promos and shifts are managed inside the app:
**Settings** page (superadmin only).

## 4. Development mode (optional)

For live-reload editing of the frontend:

```powershell
npm run dev
```

Then open `http://localhost:5173/`. The dev server proxies `/api` to the PHP backend
at `http://localhost/ZoeysBilliardHouseManagementSystem` (adjust `vite.config.js` if you
renamed the folder). Do not use `npm run dev` for normal use — it is for developers only.

## 5. Backups

### Manual

**Settings → Backup** (superadmin) downloads a full SQL dump — no CLI tools needed.

### Automated (nightly)

```powershell
schtasks /create /tn "ZoeysDB" /tr "C:\laragon\www\ZoeysBilliardHouseManagementSystem\database\backup.bat" /sc daily /st 03:00
```

`database\backup.ps1` locates `mysqldump.exe` automatically (Laragon → XAMPP → PATH), so it
keeps working after a MySQL version upgrade. Backups land in `backups\` (14 days kept,
`.sql` files); it also accepts `-KeepDays`, `-DbUser`, `-DbPass`, `-Mysqldump` if you need
to change the behavior.

## 6. Deployment checklist (moving off localhost)

- [ ] Copy to the production machine (Apache + mod_rewrite required — `.htaccess` is part of the app)
- [ ] Set `ZB_ENV=production` (and `ZB_DB_*` if MySQL differs)
- [ ] `npm run build` on the server
- [ ] Change all seeded passwords
- [ ] Use HTTPS — session cookies automatically become `Secure`
- [ ] Schedule the nightly backup
- [ ] Run `deploy-check.bat` — it must report `READY`
- [ ] Test: login, create a sale, print a receipt, open Reports

## 7. Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| Page shows *"Frontend not built yet. Run npm run build"* | `dist/` is missing → run `npm run build` |
| `404`/plain file listing instead of the app | Nginx is serving instead of Apache → switch Laragon to Apache (`.htaccess` is ignored by Nginx) |
| Blank page after clicking the sidebar | Stale bundle (app was rebuilt since the tab opened) → hard refresh; if it persists, it auto-reloads via the router error handler |
| `403` on `includes/`, `database/`, `backups/`, `config.php`, `uploads/` listing | Expected — hardened by `.htaccess` |
| `400 Unknown action` on ajax calls | Read-only endpoints return it for bad `action` params; oversized JSON bodies are rejected by PHP limits |
| Login says *"Too many failed attempts"* | 5 failed logins lock the browser session for 60 s |
| Logo upload fails | Must be PNG/JPG/WEBP; PNGs are recommended for thermal receipt printing |
| `mysql`/`php` "not recognized" in PowerShell | Use **Laragon → Terminal**, or call the full path under `C:\laragon\bin\...` |
| Backup task produces no file | `mysqldump.exe` not found → pass `-Mysqldump "<path>"` to `backup.ps1` |

## 8. Project structure

```
ajax/            PHP JSON API (auth, tables, pos, products, reports, ...)
backups/         SQL dumps (created by the backup script)
database/        install.sql (fresh install), migrate_*.sql (upgrades), backup.ps1/.bat
dist/            Built frontend (created by npm run build) — regenerated, never edit by hand
includes/        Shared PHP (auth guards, DB helpers) — not web-accessible
public/          Static assets copied into dist (logo.png)
src/             Vue 3 source (views, stores, api client)
uploads/         Uploaded files (receipt logo)
config.php       Environment + session hardening + DB bootstrap
deploy-check.ps1 Read-only readiness/health check (deploy-check.bat = double-click)
install.ps1      One-tap installer (install.bat = double-click)
.htaccess        SPA/API routing + security hardening (Apache)
```
