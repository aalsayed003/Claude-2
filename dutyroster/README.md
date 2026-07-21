# Duty Roster — On-Premise Rebuild

A PHP rebuild of Al Salam Hospital's in-house **Duty Roster & Attendance** system
that sits on top of the ZKTeco / BioTime biometric feed. It reproduces the nine
screens of the legacy desktop application as a web app you can host on-prem.

> Rebuilt from the storyboard, the `CheckInOut` data feed, and the data-flow
> diagram — the original source code was unavailable.

## What it does

```
Biometric machines (ZKTeco)
      │  BioTime pulls IP / Serial / Area
      ▼
  checkinout  (BioTime's raw punch table — unchanged)
      │  ingest/load_punches.php  (runs every 5 min — replaces sp_HRMS_LoadPunching)
      ▼
  punches  →  attendance  (this app's DB)
      ▼
  Duty Roster web app  (dashboard, roster, approvals, corrections, OT …)
```

### Modules (mirrors the legacy screens)
| Screen | Route |
|---|---|
| Main Dashboard + Pending Approvals | `/dashboard` |
| Duty Roster Master (shifts) | `/shifts` |
| Duty Roster (allot shift) | `/roster`, `/roster/allot` |
| Submit Duty Roster | `/roster/submit` |
| Approve Request (Head → FA → MRD → COO) | `/approvals` |
| View Attendance (actual vs scheduled) | `/attendance` |
| Attendance Correction | `/correction` |
| Change Schedule | `/schedule-change` |
| Overtime | `/overtime` |
| Employees / Departments (admin) | `/employees`, `/departments` |

## Tech
- **Plain PHP 8.1+** (no framework/Composer), PDO data layer, small MVC core.
- **MySQL / MariaDB** app database (SQL Server or PostgreSQL also supported via config).
- Reads the biometric feed from **any** engine (SQL Server / MySQL / PostgreSQL).
- Role-based access: `employee`, `dept_head`, `fa`, `mrd`, `coo`, `admin`.
- Attendance runs on a configurable **cutoff cycle** (default 16th → 15th).

## Quick start (local test)
```bash
cp config/config.example.php config/config.php   # then edit DB settings
php ingest/install.php                            # create schema + seed
php -S 127.0.0.1:8080 -t public                   # dev server
# open http://127.0.0.1:8080  →  login: admin / admin123  (change it!)
```

## Production deploy
See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for Apache, IIS, Nginx and Docker
setups, plus the every-5-minute punch-ingest scheduling.

## Repo layout
```
dutyroster/
  public/          web root (index.php front controller, assets, .htaccess/web.config)
  app/
    Core/          Config, Database, Router, Auth, View, Controller, helpers
    Controllers/   one per screen
    Services/      AttendanceEngine (derives attendance from punches + roster)
    Views/         templates
  ingest/
    install.php        one-time schema + seed installer
    load_punches.php   the 5-minute punch importer (cron / Task Scheduler)
  database/        schema.sql, seed.sql
  config/          config.example.php  (copy to config.php)
  docs/            DEPLOYMENT.md
```

## Configuration highlights (`config/config.php`)
- `attendance.cutoff_day` — payroll cutoff (default 16).
- `attendance.grace_late_min` / `grace_early_min` — grace before late/early is counted.
- `attendance.ot_min_threshold` — ignore OT shorter than N minutes.
- `punch_source` — connection + SELECT that reads new rows from `checkinout`.
  Left **disabled** by default until you provide BioTime credentials.

## Security notes
- Change the `admin` password immediately (`php ingest/install.php --admin-pass=...`).
- `config/config.php` is git-ignored — never commit real credentials.
- All forms are CSRF-protected; passwords are bcrypt-hashed.
