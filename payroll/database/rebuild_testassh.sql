/* ===========================================================================
   Clean rebuild of the duplicated / missing tables in TestASSH
   ---------------------------------------------------------------------------
   The 22-Jul copy that created TestASSH double-loaded several transactional
   tables (CurrentMonth, CurrentDetails, AllotShiftDetail, LeaveApplication,
   leavebalance are ~2x their ASSH counts) and skipped MonthlyAllowances. That
   makes a payroll dry run double-count. This script reloads each affected table
   cleanly from the live source, so TestASSH faithfully mirrors production.

   Sources (same SQL Server instance):
       ASSH     — core HRMS / roster  (Employee, CurrentMonth, ...)
       DB_ASSH  — companion           (DR_CorrectionRequest, DR_OverTime, ...)

   *** THIS SCRIPT MODIFIES TestASSH ONLY. It never writes to ASSH or DB_ASSH. ***

   It drops and recreates each target with SELECT INTO, which copies the data
   and column definitions (including IDENTITY) but NOT indexes/PK/constraints —
   fine for a payroll test database. The payroll performance indexes are
   recreated at the end. Idempotent: safe to re-run.

   Run in SSMS against the instance, as a login that can read ASSH + DB_ASSH and
   write TestASSH. Review the verification grid at the bottom before using it.
   =========================================================================== */

SET NOCOUNT ON;

IF DB_ID('TestASSH') IS NULL BEGIN RAISERROR('TestASSH not found on this instance.', 16, 1); RETURN; END
IF DB_ID('ASSH')     IS NULL BEGIN RAISERROR('ASSH not found on this instance.',     16, 1); RETURN; END
IF DB_ID('DB_ASSH')  IS NULL BEGIN RAISERROR('DB_ASSH not found on this instance.',  16, 1); RETURN; END
GO

USE TestASSH;
GO

PRINT '--- 1. Reloading the double-loaded tables from ASSH ---';

IF OBJECT_ID('TestASSH.dbo.CurrentMonth', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.CurrentMonth;
SELECT * INTO TestASSH.dbo.CurrentMonth FROM ASSH.dbo.CurrentMonth;
PRINT '   CurrentMonth reloaded.';

IF OBJECT_ID('TestASSH.dbo.CurrentDetails', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.CurrentDetails;
SELECT * INTO TestASSH.dbo.CurrentDetails FROM ASSH.dbo.CurrentDetails;
PRINT '   CurrentDetails reloaded.';

IF OBJECT_ID('TestASSH.dbo.AllotShiftDetail', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.AllotShiftDetail;
SELECT * INTO TestASSH.dbo.AllotShiftDetail FROM ASSH.dbo.AllotShiftDetail;
PRINT '   AllotShiftDetail reloaded.';

IF OBJECT_ID('TestASSH.dbo.LeaveApplication', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.LeaveApplication;
SELECT * INTO TestASSH.dbo.LeaveApplication FROM ASSH.dbo.LeaveApplication;
PRINT '   LeaveApplication reloaded.';

IF OBJECT_ID('TestASSH.dbo.leavebalance', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.leavebalance;
SELECT * INTO TestASSH.dbo.leavebalance FROM ASSH.dbo.leavebalance;
PRINT '   leavebalance reloaded.';
GO

PRINT '--- 2. Bringing over the table the copy skipped (MonthlyAllowances) ---';

IF OBJECT_ID('TestASSH.dbo.MonthlyAllowances', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.MonthlyAllowances;
SELECT * INTO TestASSH.dbo.MonthlyAllowances FROM ASSH.dbo.MonthlyAllowances;
PRINT '   MonthlyAllowances loaded.';
GO

PRINT '--- 3. Refreshing the companion DR_* tables from DB_ASSH ---';
PRINT '        (so the app can run against TestASSH with companion_db = '''')';

IF OBJECT_ID('TestASSH.dbo.DR_CorrectionRequest', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.DR_CorrectionRequest;
SELECT * INTO TestASSH.dbo.DR_CorrectionRequest FROM DB_ASSH.dbo.DR_CorrectionRequest;
PRINT '   DR_CorrectionRequest reloaded.';

IF OBJECT_ID('TestASSH.dbo.DR_OverTime', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.DR_OverTime;
SELECT * INTO TestASSH.dbo.DR_OverTime FROM DB_ASSH.dbo.DR_OverTime;
PRINT '   DR_OverTime reloaded.';

IF OBJECT_ID('TestASSH.dbo.EmployeeWorkingHours', 'U') IS NOT NULL DROP TABLE TestASSH.dbo.EmployeeWorkingHours;
SELECT * INTO TestASSH.dbo.EmployeeWorkingHours FROM DB_ASSH.dbo.EmployeeWorkingHours;
PRINT '   EmployeeWorkingHours reloaded.';
GO

PRINT '--- 4. Re-creating the payroll performance indexes ---';

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_CurrentMonth_EmpMonth' AND object_id = OBJECT_ID('TestASSH.dbo.CurrentMonth'))
    CREATE INDEX IX_CurrentMonth_EmpMonth ON TestASSH.dbo.CurrentMonth (Empid, CurrentMonth);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_CurrentDetails_EmpMonth' AND object_id = OBJECT_ID('TestASSH.dbo.CurrentDetails'))
    CREATE INDEX IX_CurrentDetails_EmpMonth ON TestASSH.dbo.CurrentDetails (Empid, CurrentMonth);
GO

PRINT '--- 5. Verification: source vs TestASSH row counts (should now match) ---';

SELECT 'CurrentMonth'         AS table_name, (SELECT COUNT(*) FROM ASSH.dbo.CurrentMonth)         AS source_rows, (SELECT COUNT(*) FROM TestASSH.dbo.CurrentMonth)         AS testassh_rows
UNION ALL SELECT 'CurrentDetails',      (SELECT COUNT(*) FROM ASSH.dbo.CurrentDetails),      (SELECT COUNT(*) FROM TestASSH.dbo.CurrentDetails)
UNION ALL SELECT 'AllotShiftDetail',    (SELECT COUNT(*) FROM ASSH.dbo.AllotShiftDetail),    (SELECT COUNT(*) FROM TestASSH.dbo.AllotShiftDetail)
UNION ALL SELECT 'LeaveApplication',    (SELECT COUNT(*) FROM ASSH.dbo.LeaveApplication),    (SELECT COUNT(*) FROM TestASSH.dbo.LeaveApplication)
UNION ALL SELECT 'leavebalance',        (SELECT COUNT(*) FROM ASSH.dbo.leavebalance),        (SELECT COUNT(*) FROM TestASSH.dbo.leavebalance)
UNION ALL SELECT 'MonthlyAllowances',   (SELECT COUNT(*) FROM ASSH.dbo.MonthlyAllowances),   (SELECT COUNT(*) FROM TestASSH.dbo.MonthlyAllowances)
UNION ALL SELECT 'DR_CorrectionRequest',(SELECT COUNT(*) FROM DB_ASSH.dbo.DR_CorrectionRequest),(SELECT COUNT(*) FROM TestASSH.dbo.DR_CorrectionRequest)
UNION ALL SELECT 'DR_OverTime',         (SELECT COUNT(*) FROM DB_ASSH.dbo.DR_OverTime),      (SELECT COUNT(*) FROM TestASSH.dbo.DR_OverTime)
UNION ALL SELECT 'EmployeeWorkingHours',(SELECT COUNT(*) FROM DB_ASSH.dbo.EmployeeWorkingHours),(SELECT COUNT(*) FROM TestASSH.dbo.EmployeeWorkingHours)
ORDER BY table_name;
GO

PRINT '--- Done. TestASSH now mirrors ASSH + DB_ASSH for these tables. ---';
GO
