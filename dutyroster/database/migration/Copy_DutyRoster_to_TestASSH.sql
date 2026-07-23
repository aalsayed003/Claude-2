/* ================================================================
   Copy DUTY-ROSTER table data:  ASSH -> TestASSH  (same instance)
   Run AFTER Create_TestASSH.sql.

   Only the ~40 duty-roster tables are copied (edit the list below).
   Same safety as the full copy:
     - excludes computed / rowversion columns
     - SET IDENTITY_INSERT on/off for identity tables
     - foreign keys disabled during load, re-enabled after
     - skips + logs any table that fails; names not found are noted
   ================================================================ */
SET NOCOUNT ON;
ALTER DATABASE [TestASSH] SET RECOVERY SIMPLE;
GO

/* ---------- 1. The duty-roster table list (EDIT HERE) ---------- */
DECLARE @Tables TABLE (name SYSNAME PRIMARY KEY);
INSERT INTO @Tables (name) VALUES
  -- Shift / roster
  ('Shift'), ('AllotShift'), ('AllotShiftDetail'), ('AllotShiftDetailLeave'),
  ('Allot_Shift'), ('Allot_ShiftDetails'), ('CurrentMonth'), ('CurrentDetails'),
  ('shiftschedulehistory'),
  -- Attendance
  ('attendance'), ('attendancehistory'), ('empPunchingDetails'),
  ('empPunchingDetailsIF'), ('empPunchingDetailsIFDT'), ('employeepresent'),
  ('DRMainDashBoard'),
  -- Requests / approval workflow
  ('Schedule_Request'), ('Schedule_RequestActions'), ('ScheduleStatus'),
  ('DR_ChangeSchedule'), ('DR_OverTime'), ('DR_OvertimeReason'), ('overtime'),
  ('DR_VacationPlan'), ('DR_Audit'),
  -- Leave management
  ('leave'), ('LeaveApplication'), ('LeaveApplicationCOff'), ('leavebalance'),
  ('SickLeaves'),
  -- Master data
  ('Employee'), ('EmployeeNew'), ('Employee_DutyRoster'), ('employeechangedetails'),
  ('employeestation'), ('Department'), ('DepartmentNew'), ('Designation'),
  ('DesignationContract'), ('desgmaster'), ('desgmasterdetail'),
  ('RA_SystemUsers'), ('ModulePermission');
  -- Optional extras (uncomment if you want them in the test copy):
  -- ('CHECKINOUT_24'), ('EmployeePhoto'), ('employeeimmunisation'),
  -- ('MonthlyAllowances'), ('DepartmentwiseAdjustment'),
  -- ('ScheduleDay'), ('ScheduleDaySlots'), ('ScheduleDoctor');

/* ---------- 2. Note any listed names that don't exist in ASSH ---------- */
DECLARE @missing NVARCHAR(MAX) = N'';
SELECT @missing = @missing + x.name + N', '
FROM @Tables x
WHERE NOT EXISTS (SELECT 1 FROM ASSH.sys.tables t WHERE t.name = x.name AND t.is_ms_shipped = 0);
IF @missing <> N'' PRINT 'NOTE - not found in ASSH (skipped): ' + @missing;

/* ---------- 3. Disable FKs in TestASSH ---------- */
DECLARE @disable NVARCHAR(MAX) = N'';
SELECT @disable = @disable
     + N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name)
     + N' NOCHECK CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
FROM TestASSH.sys.foreign_keys fk
JOIN TestASSH.sys.tables t ON t.object_id = fk.parent_object_id;
IF @disable <> N'' EXEC (N'USE [TestASSH]; ' + @disable);

/* ---------- 4. Copy each listed table ---------- */
DECLARE @tbl SYSNAME, @cols NVARCHAR(MAX), @sql NVARCHAR(MAX), @hasIdent BIT;
DECLARE @ok INT = 0, @skip INT = 0;

DECLARE c CURSOR LOCAL FAST_FORWARD FOR
    SELECT t.name
    FROM @Tables x
    JOIN ASSH.sys.tables t ON t.name = x.name AND t.is_ms_shipped = 0
    ORDER BY t.name;

OPEN c;
FETCH NEXT FROM c INTO @tbl;
WHILE @@FETCH_STATUS = 0
BEGIN
    SELECT @cols = STUFF((
        SELECT N',' + QUOTENAME(col.name)
        FROM ASSH.sys.columns col
        JOIN ASSH.sys.types  ty ON ty.user_type_id = col.user_type_id
        WHERE col.object_id = OBJECT_ID(N'ASSH.dbo.' + QUOTENAME(@tbl))
          AND col.is_computed = 0
          AND ty.name NOT IN (N'timestamp', N'rowversion')
        ORDER BY col.column_id
        FOR XML PATH(N''), TYPE).value(N'.', N'NVARCHAR(MAX)'), 1, 1, N'');

    IF @cols IS NULL
    BEGIN
        PRINT 'SKIP ' + @tbl + '  -> no insertable columns';
        SET @skip += 1; FETCH NEXT FROM c INTO @tbl; CONTINUE;
    END

    SET @hasIdent = CASE WHEN EXISTS (
        SELECT 1 FROM ASSH.sys.columns
        WHERE object_id = OBJECT_ID(N'ASSH.dbo.' + QUOTENAME(@tbl)) AND is_identity = 1
    ) THEN 1 ELSE 0 END;

    SET @sql =
        CASE WHEN @hasIdent = 1
             THEN N'SET IDENTITY_INSERT [TestASSH].[dbo].' + QUOTENAME(@tbl) + N' ON; ' ELSE N'' END
      + N'INSERT INTO [TestASSH].[dbo].' + QUOTENAME(@tbl) + N' (' + @cols + N') '
      + N'SELECT ' + @cols + N' FROM [ASSH].[dbo].' + QUOTENAME(@tbl) + N'; '
      + CASE WHEN @hasIdent = 1
             THEN N'SET IDENTITY_INSERT [TestASSH].[dbo].' + QUOTENAME(@tbl) + N' OFF;' ELSE N'' END;

    BEGIN TRY
        EXEC sp_executesql @sql;
        SET @ok += 1;
        PRINT 'OK   ' + @tbl;
    END TRY
    BEGIN CATCH
        SET @skip += 1;
        PRINT 'SKIP ' + @tbl + '  -> ' + ERROR_MESSAGE();
    END CATCH

    FETCH NEXT FROM c INTO @tbl;
END
CLOSE c; DEALLOCATE c;
PRINT '--------------------------------------------';
PRINT 'Copied OK: ' + CAST(@ok AS VARCHAR(10)) + '   Skipped: ' + CAST(@skip AS VARCHAR(10));

/* ---------- 5. Re-enable FKs ---------- */
DECLARE @enable NVARCHAR(MAX) = N'';
SELECT @enable = @enable
     + N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name)
     + N' WITH CHECK CHECK CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
FROM TestASSH.sys.foreign_keys fk
JOIN TestASSH.sys.tables t ON t.object_id = fk.parent_object_id;
IF @enable <> N'' EXEC (N'USE [TestASSH]; ' + @enable);
PRINT 'Done.';
GO
