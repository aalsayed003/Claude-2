/* ================================================================
   Replace the duty-roster tables in TestASSH with the AUTHORITATIVE
   DB_ASSH versions (the ASSH copies are stale/empty decoys).

   For each: drop the TestASSH copy and SELECT ... INTO from DB_ASSH
   (exact structure + data). Then re-reveal the real workflow state
   codes from the now-complete data.
   ================================================================ */
USE TestASSH;
SET NOCOUNT ON;

DECLARE @tables TABLE (name SYSNAME PRIMARY KEY);
INSERT INTO @tables (name) VALUES
    ('DR_CorrectionRequest'), ('EmployeeWorkingHours'), ('DR_ChangeSchedule'),
    ('DR_OverTime'), ('DR_Audit'), ('DR_EmployeeDutyRoster');

DECLARE @t SYSNAME, @ok INT = 0, @skip INT = 0;
DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT name FROM @tables ORDER BY name;
OPEN c;
FETCH NEXT FROM c INTO @t;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID('DB_ASSH.dbo.' + QUOTENAME(@t)) IS NULL
    BEGIN
        PRINT 'SKIP ' + @t + '  (not found in DB_ASSH)';
        SET @skip += 1; FETCH NEXT FROM c INTO @t; CONTINUE;
    END
    BEGIN TRY
        IF OBJECT_ID('TestASSH.dbo.' + QUOTENAME(@t)) IS NOT NULL
            EXEC ('DROP TABLE TestASSH.dbo.' + @t);
        EXEC ('SELECT * INTO TestASSH.dbo.' + @t + ' FROM DB_ASSH.dbo.' + @t);
        PRINT 'REPLACED ' + @t;
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
PRINT 'Replaced from DB_ASSH: ' + CAST(@ok AS VARCHAR(10)) + '   Skipped: ' + CAST(@skip AS VARCHAR(10));

/* ---- Real workflow state codes (now from full DB_ASSH data) ---- */
PRINT 'DR_ChangeSchedule.StateID:';
SELECT StateID, COUNT(*) AS n FROM TestASSH.dbo.DR_ChangeSchedule GROUP BY StateID ORDER BY StateID;

PRINT 'DR_OverTime.StateID:';
SELECT StateID, COUNT(*) AS n FROM TestASSH.dbo.DR_OverTime GROUP BY StateID ORDER BY StateID;

PRINT 'DR_CorrectionRequest.TypeID x StateID:';
SELECT TypeID, StateID, COUNT(*) AS n
FROM TestASSH.dbo.DR_CorrectionRequest
GROUP BY TypeID, StateID ORDER BY TypeID, StateID;
