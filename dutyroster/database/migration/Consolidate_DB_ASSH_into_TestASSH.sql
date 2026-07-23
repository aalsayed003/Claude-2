/* ================================================================
   Consolidate: copy the duty-roster tables from DB_ASSH INTO TestASSH,
   so the app runs against a SINGLE database.

   Uses SELECT ... INTO, which creates each table in TestASSH with the
   source structure (columns, types, nullability, identity) AND copies
   the data in one step. Tables already present in TestASSH are skipped
   (so the ASSH tables you already copied are never touched).

   Run AFTER Create_TestASSH.sql + the ASSH data copy.
   ================================================================ */
USE TestASSH;
SET NOCOUNT ON;

/* ---- 1. Which DB_ASSH tables does the duty roster need? ---- */
IF OBJECT_ID('tempdb..#need') IS NOT NULL DROP TABLE #need;
CREATE TABLE #need (name SYSNAME PRIMARY KEY);

-- known-needed (from the decoded procs)
INSERT INTO #need (name) VALUES ('DR_CorrectionRequest'), ('EmployeeWorkingHours');

-- auto-discovered: any DB_ASSH table referenced by an ASSH duty-roster module
INSERT INTO #need (name)
SELECT DISTINCT t.name
FROM DB_ASSH.sys.tables t
WHERE EXISTS (
        SELECT 1 FROM ASSH.sys.sql_modules m
        WHERE ( m.definition LIKE '%DB_ASSH..'    + t.name + '%'
             OR m.definition LIKE '%DB_ASSH.dbo.' + t.name + '%')
          AND ( m.definition LIKE '%AllotShift%'      OR m.definition LIKE '%Schedule[_]Request%'
             OR m.definition LIKE '%DR[_]%'           OR m.definition LIKE '%Atten[_]%'
             OR m.definition LIKE '%attendance%'      OR m.definition LIKE '%Punch%'
             OR m.definition LIKE '%WorkingHours%'))
  AND NOT EXISTS (SELECT 1 FROM #need n WHERE n.name = t.name);

PRINT 'Tables to consider: ' + CAST((SELECT COUNT(*) FROM #need) AS VARCHAR(10));

/* ---- 2. Copy each into TestASSH (structure + data), skip collisions ---- */
DECLARE @t SYSNAME, @sql NVARCHAR(MAX), @ok INT = 0, @skip INT = 0;
DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT name FROM #need ORDER BY name;
OPEN c;
FETCH NEXT FROM c INTO @t;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID('TestASSH.dbo.' + QUOTENAME(@t)) IS NOT NULL
    BEGIN
        PRINT 'SKIP ' + @t + '  (already exists in TestASSH)';
        SET @skip += 1;
    END
    ELSE IF OBJECT_ID('DB_ASSH.dbo.' + QUOTENAME(@t)) IS NULL
    BEGIN
        PRINT 'SKIP ' + @t + '  (not found in DB_ASSH)';
        SET @skip += 1;
    END
    ELSE
    BEGIN
        SET @sql = 'SELECT * INTO TestASSH.dbo.' + QUOTENAME(@t)
                 + ' FROM DB_ASSH.dbo.' + QUOTENAME(@t) + ';';
        BEGIN TRY
            EXEC sp_executesql @sql;
            PRINT 'OK   ' + @t;
            SET @ok += 1;
        END TRY
        BEGIN CATCH
            PRINT 'SKIP ' + @t + '  -> ' + ERROR_MESSAGE();
            SET @skip += 1;
        END CATCH
    END
    FETCH NEXT FROM c INTO @t;
END
CLOSE c; DEALLOCATE c;

PRINT '--------------------------------------------';
PRINT 'Copied OK: ' + CAST(@ok AS VARCHAR(10)) + '   Skipped: ' + CAST(@skip AS VARCHAR(10));
DROP TABLE #need;

/* ---- 3. Verify ---- */
SELECT name AS consolidated_table, (SELECT SUM(p.rows) FROM sys.partitions p
        WHERE p.object_id = t.object_id AND p.index_id IN (0,1)) AS row_count
FROM sys.tables t
WHERE name IN ('DR_CorrectionRequest', 'EmployeeWorkingHours')
ORDER BY name;
