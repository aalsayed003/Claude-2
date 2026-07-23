# Duty Roster — Database Linkages

The legacy system spans several databases on the SQL Server instance. This map
(derived from `sys.sql_modules` text scans — which catch dynamic SQL — over the
duty-roster procedures) records exactly which databases the duty roster depends
on, so the rebuilt app and the test environment include the right pieces.

## In scope for the duty roster

| Database | DR modules | Role | App usage |
|---|---:|---|---|
| **ASSH** | 9 | Core duty-roster + HR | roster (`AllotShift`/`AllotShiftDetail`, draft `Allot_Shift`), shifts (`Shift`), employees (`Employee`), departments (`Department`), daily attendance (`Atten_MMYYYY`), corrections audit (`attendancehistory`), dashboard (`DRMainDashBoard`), submissions (`Schedule_Request`/`Schedule_RequestActions`), overtime (`DR_OverTime`/`DR_OvertimeReason`), change-schedule (`DR_ChangeSchedule`) |
| **DB_ASSH** | 7 | Companion | attendance corrections (`DR_CorrectionRequest` — `EmployeeID, DayFor, StateID, TypeID`; TypeID 0/2 = late-in, 1/3 = early-out), monthly working-hours targets (`EmployeeWorkingHours`) |
| **zkteco_biotime** | 1 | Punch source | raw biometric punches (`checkinout`) — read-only ingest by `load_punches.php` |

## Out of scope (payroll / other hospitals)

| Database | Notes |
|---|---|
| Attendance, Paylite_Attendance | One-proc payroll integrations (nightly export to Paylite payroll). Belongs to the future payroll phase, not the duty-roster app. |
| DB_SGH, Madinah | Other hospitals/branches. **Not referenced** by ASSH duty-roster modules — ignored. |
| DataInfo, ReportServer(*), Paylite*, db_PatientPortal, HRFileAudit | Referenced by non-duty-roster ASSH code only. |

## What this means for deployment & testing

- **Production**: the app connects to `ASSH` (read/write) + `DB_ASSH` (corrections,
  working hours) + `zkteco_biotime` (read-only punches).
- **Test environment**: `TestASSH` (done) + **`TestDB_ASSH`** (to create — the
  DR tables from `DB_ASSH`) + point the punch source at `zkteco_biotime`.
- Config keys: `db` -> ASSH/TestASSH; `legacy.companion_db` -> DB_ASSH/TestDB_ASSH;
  `punch_source` -> zkteco_biotime.

## How this was determined

```sql
-- Every database referenced by ASSH modules (catches dynamic SQL):
SELECT d.name, (SELECT COUNT(*) FROM sys.sql_modules m
    WHERE m.definition LIKE '%'+d.name+'..%' OR m.definition LIKE '%'+d.name+'.dbo.%')
FROM sys.databases d WHERE d.database_id > 4 ORDER BY 2 DESC;

-- Scoped to duty-roster modules (referencing AllotShift/Schedule_Request/DR_*/Atten_/…).
```
