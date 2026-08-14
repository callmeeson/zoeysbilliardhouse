# Zoeys Billiard House Management System

Billiard house POS + management system. Vue 3 single-page frontend (Vite build, Tailwind CSS) with a PHP 8 + MySQL JSON API (`ajax/`).

## Requirements

| Software | Version |
|----------|---------|
| XAMPP (Apache + PHP + MySQL) | PHP 8.1+ (tested on 8.2) |
| Node.js | 18+ (with `npm`) |
| Windows / macOS / Linux | any (XAMPP assumed below) |

## 1. Fresh installation

### 1.0 One-tap installer (Windows)

Double-click **`install.bat`** (or run `powershell -File install.ps1`) in the project
folder. It checks XAMPP/Node, creates + seeds the database (never touches existing
data), builds the frontend, runs a login check and opens the app.

```powershell
powershell -File install.ps1                 # normal run
powershell -File install.ps1 -Reinstall      # DESTRUCTIVE: wipe DB + reseed (asks first)
powershell -File install.ps1 -ForceRebuild   # force npm install + rebuild frontend
powershell -File install.ps1 -DbUser root -DbPass "secret"
```

### 1.1 Copy the project

Place the project folder into the web root:

```
C:\xampp\htdocs\ZoeysBilliardHouseManagementSystem
```

The app must be reachable at `http://localhost/ZoeysBilliardHouseManagementSystem/`.
`config.php` detects the host/folder automatically — no URL editing needed. If you
move it elsewhere (or use HTTPS), everything still works.

### 1.2 Start the services

Start **Apache** and **MySQL** from the XAMPP Control Panel. Both must run.

### 1.3 Create the database

Open a terminal and import the base schema:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS zoeys_billiard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content "C:\xampp\htdocs\ZoeysBilliardHouseManagementSystem\database\install.sql" | & "C:\xampp\mysql\bin\mysql.exe" -u root zoeys_billiard
```

> Using a different MySQL user or password? See [Configuration](#3-configuration) — the API reads
> `ZB_DB_USER` / `ZB_DB_PASS` from environment variables.

### 1.4 Install frontend dependencies and build

```powershell
cd C:\xampp\htdocs\ZoeysBilliardHouseManagementSystem
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

## 2. Upgrading an existing install

1. **Back up first** — run the backup script (see [Backups](#5-backups)).
2. Replace the code files (keep `uploads/`, `backups/`).
3. Apply any pending migrations from `database/migrate_*.sql` **in filename order**:
   ```powershell
   Get-Content "database\migrate_shifts.sql" | & "C:\xampp\mysql\bin\mysql.exe" -u root zoeys_billiard
   ```
4. Rebuild the frontend: `npm run build`.
5. Verify with a hard refresh (Ctrl+F5).

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

`BASE_URL` is derived from the request automatically (scheme + host + folder).

Business name, address, phone, receipt logo, promos and shifts are managed inside the app:
**Settings** page (superadmin only).

## 4. Development mode (optional)

For live-reload editing of the frontend:

```powershell
npm run dev
```

Then open `http://localhost:5173/`. The dev server proxies `/api` to the PHP backend.
Do not use `npm run dev` for normal use — it is for developers only.

## 5. Backups

### Manual

**Settings → Backup** (superadmin) downloads a full SQL dump.

### Automated (nightly)

```powershell
schtasks /create /tn "ZoeysDB" /tr "C:\xampp\htdocs\ZoeysBilliardHouseManagementSystem\database\backup.bat" /sc daily /st 03:00
```

Backups land in `backups\` (14 days kept, `.sql` files). `database\backup.ps1` accepts
`-KeepDays`, `-DbUser`, `-DbPass` etc. if you need to change the behavior.

## 6. Deployment checklist (moving off localhost)

- [ ] Copy to the production machine (Apache + mod_rewrite required — `.htaccess` is part of the app)
- [ ] Set `ZB_ENV=production` (and `ZB_DB_*` if MySQL differs)
- [ ] `npm run build` on the server
- [ ] Change all seeded passwords
- [ ] Use HTTPS — session cookies automatically become `Secure`
- [ ] Schedule the nightly backup
- [ ] Test: login, create a sale, print a receipt, open Reports

## 7. Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| Page shows *"Frontend not built yet. Run npm run build"* | `dist/` is missing → run `npm run build` |
| Blank page after clicking the sidebar | Stale bundle (app was rebuilt since the tab opened) → hard refresh; if it persists, it auto-reloads via the router error handler |
| `403` on `includes/`, `database/`, `backups/`, `config.php`, `uploads/` listing | Expected — hardened by `.htaccess` |
| `400 Unknown action` on ajax calls | Read-only endpoints return it for bad `action` params; oversized JSON bodies are rejected by PHP limits |
| Login says *"Too many failed attempts"* | 5 failed logins lock the browser session for 60 s |
| Logo upload fails | Must be PNG/JPG/WEBP; PNGs are recommended for thermal receipt printing |

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
.htaccess        SPA/API routing + security hardening (Apache)
```