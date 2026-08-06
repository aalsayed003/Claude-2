# Payroll — Al Salam Hospital

A standalone in-house **payroll** application. It computes the monthly salary
register (gross → deductions → net), handles GOSI, loans, salary hold/release,
leave encashment, increments and end-of-service settlements, produces payslips
and a WPS / bank transfer file.

It is a separate app from the Duty Roster system. It connects to the SQL Server
database that holds the HR masters and the legacy payroll register
(`Employee`, `Department`, `CurrentDetails`, `CurrentMonth`) and creates its own
`Pay_*` tables there. It does **not** modify any legacy table.

## The Duty Roster link (later)

Payroll needs attendance — rostered shifts, punches, approved overtime, leave —
which live in the Duty Roster / HRMS database. That dependency sits behind one
switch, `roster_link.enabled`, and is **off** by default.

- **Off (now):** payroll assumes full attendance. Everyone is paid their full
  structure, prorated only for a mid-month join/leave; no absence, lates,
  early-outs or overtime. A banner says so on every screen.
- **On (later):** the calculation reads the roster tables and becomes
  attendance-driven. Flipping the switch is a config change, no code change.

This lets salary structures, GOSI, loans, holds, encashment, increments,
settlements, the register, payslips and the bank file all be set up and used
now, and the roster wired in when it is ready.

## Tech

- Plain **PHP 8.1+** (no framework/Composer), PDO, small MVC core.
- **SQL Server** (the shared HRMS database). MySQL/PostgreSQL also supported by
  the data layer if you point it elsewhere.
- Own login (`Pay_Users`), role-gated: `employee`, `fa`, `coo`, `admin`.
  Salary figures are visible only to `fa` and above.

## Quick start

```bash
cp config/config.example.php config/config.php     # then edit the db + payroll blocks
php ingest/install.php --admin-pass=YOUR_PASSWORD  # create Pay_Users + Pay_* tables, seed
php -S 127.0.0.1:8080 -t public                    # dev server
# open http://127.0.0.1:8080  →  login: admin / (the password you set)
```

The installer is idempotent — run it against `TestASSH` first. See
[`docs/payroll.md`](docs/payroll.md) for the full design, the component map, the
run lifecycle, and the **ten assumptions to confirm before the first live run**.

## Repo layout

```
Payroll/
  public/          web root (index.php front controller, assets, .htaccess/web.config)
  app/
    Core/          Config, Database, Router, Auth, View, Controller, helpers
    Controllers/   Dashboard, Payroll, SalaryStructure, Payslip, Loan,
                   Settlement, SalaryHold, LeaveEncashment, Auth
    Repositories/  Payroll, SalaryStructure, Statutory, Loan, SalaryHold,
                   LeaveEncashment, Employee
    Services/      PayrollEngine, PayrollAttendance, GosiCalculator,
                   SettlementCalculator, WpsExporter, RosterLink
    Views/         layouts, auth, dashboard, payroll/*
  ingest/
    install.php    one-time schema + seed installer
  database/        schema.sqlserver.sql, seed.sqlserver.sql
  config/          config.example.php  (copy to config.php)
  docs/            payroll.md
```

## Screens

| Screen | Route |
|---|---|
| Home (roster-link status, recent runs) | `/dashboard` |
| Payroll months — calculate / approve / lock | `/payroll`, `/payroll/run` |
| Monthly register (+ CSV) | `/payroll/register` |
| Salary structures & statutory details | `/payroll/structures` |
| Salary increment | `/payroll/increment` |
| Payslip (own slip for any user) + print | `/payroll/payslip` |
| Loans & advances | `/payroll/loans` |
| Salary hold & release | `/payroll/holds` |
| Leave encashment | `/payroll/encashment` |
| End-of-service settlement | `/payroll/settlement` |
| WPS / bank transfer file | `/payroll/wps` |

## Security notes

- Change the `admin` password immediately (`--admin-pass=...` on install).
- `config/config.php` is git-ignored — never commit real credentials.
- All forms are CSRF-protected; passwords are bcrypt-hashed.
