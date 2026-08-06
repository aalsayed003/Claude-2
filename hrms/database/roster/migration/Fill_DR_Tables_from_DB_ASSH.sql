/* ================================================================
   Fill duty-roster tables that are EMPTY in TestASSH from DB_ASSH.

   Several DR tables exist in BOTH ASSH and DB_ASSH. TestASSH was built
   from the ASSH schema, so some (e.g. DR_CorrectionRequest) are the EMPTY
   ASSH copies while the real data lives in DB_ASSH.

   For each listed table: if TestASSH's copy is EMPTY and DB_ASSH's has rows,
   drop the empty copy and SELECT ... INTO from DB_ASSH (exact structure +
   data). Tables that already have data in TestASSH are left untouched.
   ================================================================ */
USE TestASSH;
SET NOCOUNT ON;

DECLARE @tables TABLE (name SYSNAME PRIMARY KEY);
INSERT INTO @tables (name) VALUES
    ('DR_CorrectionRequest'), ('EmployeeWorkingHours'), ('DR_ChangeSchedule'),
    ('DR_OverTime'), ('DR_Audit'), ('DR_EmployeeDutyRoster');

DECLARE @t SYSNAME, @sql NVARCHAR(MAX), @src INT, @dst INT, @ok INT = 0, @skip INT = 0;
DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT name FROM @tables ORDER BY name;
OPEN c;
FETCH NEXT FROM c INTO @t;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID('TestASSH.dbo.' + QUOTENAME(@t)) IS NULL
       OR OBJECT_ID('DB_ASSH.dbo.' + QUOTENAME(@t)) IS NULL
    BEGIN
        PRINT 'SKIP ' + @t + '  (absent in one database)';
        SET @skip += 1; FETCH NEXT FROM c INTO @t; CONTINUE;
    END

    SET @sql = N'SELECT @s = (SELECT COUNT(*) FROM DB_ASSH.dbo.' + QUOTENAME(@t) + N'),'
             + N'       @d = (SELECT COUNT(*) FROM TestASSH.dbo.' + QUOTENAME(@t) + N')';
    EXEC sp_executesql @sql, N'@s INT OUTPUT, @d INT OUTPUT', @s = @src OUTPUT, @d = @dst OUTPUT;

    IF @dst > 0
    BEGIN
        PRINT 'KEEP ' + @t + '  (TestASSH already has ' + CAST(@dst AS VARCHAR(12)) + ' rows)';
        SET @skip += 1; FETCH NEXT FROM c INTO @t; CONTINUE;
    END
    IF @src = 0
    BEGIN
        PRINT 'SKIP ' + @t + '  (empty in both)';
        SET @skip += 1; FETCH NEXT FROM c INTO @t; CONTINUE;
    END

    BEGIN TRY
        EXEC ('DROP TABLE TestASSH.dbo.' + @t);
        EXEC ('SELECT * INTO TestASSH.dbo.' + @t + ' FROM DB_ASSH.dbo.' + @t);
        PRINT 'FILL ' + @t + '  (' + CAST(@src AS VARCHAR(12)) + ' rows from DB_ASSH)';
        SET @ok += 1;
    END TRY
    BEGIN CATCH
        PRINT 'SKIP ' + @t + '  -> ' + ERROR_MESSAGE();
        SET @skip += 1;
    END CATCH

    FETCH NEXT FROM c INTO @t;
END
CLOSE c; DEALLOCATE c;

PRINT '--------------------------------------------';
PRINT 'Filled from DB_ASSH: ' + CAST(@ok AS VARCHAR(10)) + '   Left as-is: ' + CAST(@skip AS VARCHAR(10));

/* verify */
SELECT 'DR_CorrectionRequest' tbl, COUNT(*) rows_now FROM TestASSH.dbo.DR_CorrectionRequest
UNION ALL SELECT 'EmployeeWorkingHours', COUNT(*) FROM TestASSH.dbo.EmployeeWorkingHours;
