# ASSH HRMS — merged Duty Roster + Payroll

A single plain-PHP app that combines the **Duty Roster** and **Payroll**
systems under **one login and one navigation**, with payroll reading the real
punch-in/out attendance from the roster. It is a *new* app: the standalone
`dutyroster/` and `payroll/` apps in this repo are left untouched as backups so
this merged app can be tested on its own first.

## Layout

```
hrms/
  public/index.php        one front controller + shared login
  config/config.example.php  merged config (copy to config.php)
  app/
    Core/                 shared Config, Router, Database, Auth, View,
                          Controller (module-aware views), helpers (merged)
    Controllers/          shared AuthController (one login)
    Roster/               duty-roster module  (namespace App\Roster\*)
    Payroll/              payroll module       (namespace App\Payroll\*)
    Views/roster/         roster views     (rendered as roster/<name>)
    Views/payroll/        payroll views    (rendered as payroll/<name>)
    Views/layouts/app.php unified nav (Duty Roster · Payroll · Me · HR Desk)
    routes.php            merged routes (roster at root, payroll under payroll/)
  database/roster, database/payroll   both apps' SQL
  ingest/install.php      payroll installer (Pay_* tables + seed)
  tests/                  combined sqlite harness (see below)
```

The two modules keep their own namespaces so their identically-named classes
(`DashboardController`, `EmployeeController`, …) coexist. The shared
`Controller::view()` prefixes the view path with the calling module, so each
module's controllers render their own views unchanged.

## One login, unified roles

Login uses a single users table (`security.users_table`, default
`dr_app_users`). Role ranks (superset of both apps):
`employee < dept_head < fa < mrd < coo < cno < coo_md < hr < admin`.
Payroll salary screens are gated at `fa`+.

## Payroll reads real attendance

`roster_link.enabled` is **true** in the merged config. `PayrollAttendance`
loads the raw biometric punches and pairs them against the roster with the
roster module's `PunchPairer` (the same schedule-aware pairing as View
Attendance — correct for split-duty and overnight), falling back to the
pre-paired `Atten_MMYYYY` tables only when the raw feed isn't reachable. So a
payroll run's present/absent/late/overtime come from the same source the
attendance screen shows.

## Quick start

```bash
cp config/config.example.php config/config.php    # edit db + payroll blocks
php ingest/install.php --admin-pass=YOUR_PASSWORD # create Pay_* tables + seed
php -S 127.0.0.1:8080 -t public                   # dev server
```

Before the first real payroll dry-run against TestASSH, run
`database/payroll/rebuild_testassh.sql` once (TestASSH has duplicated rows that
would otherwise double-count payroll).

## Tests (sqlite harness, no SQL Server needed)

```bash
php tests/build_hrms_db.php            # combined sqlite DB (roster + Pay_* + masters)
php tests/payroll_attendance_check.php # payroll reads real punches
HRMS_TEST_DB=…/test.sqlite php -S 127.0.0.1:8092 -t public &
BASE_URL=http://127.0.0.1:8092 php tests/drive_hrms.php   # drive every route
```

Verified: one login; all roster routes; all payroll routes; a full payroll run
(create → calculate → register) whose days-attended is derived from the real
punches.
