/* ================================================================
   Copy the monthly attendance tables (Atten_MMYYYY) from ASSH into
   TestASSH. These hold the daily punches and were NOT included in the
   trimmed duty-roster copy, so they are empty in TestASSH.

   For each Atten_ table that has rows in ASSH: drop the empty TestASSH
   copy and SELECT ... INTO from ASSH (structure + data).
   ================================================================ */
USE TestASSH;
SET NOCOUNT ON;

DECLARE @t SYSNAME, @src INT, @ok INT = 0, @skip INT = 0;

DECLARE c CURSOR LOCAL FAST_FORWARD FOR
    SELECT name FROM sys.tables WHERE name LIKE 'Atten[_]%' ORDER BY name;
OPEN c;
FETCH NEXT FROM c INTO @t;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID('ASSH.dbo.' + QUOTENAME(@t)) IS NULL
    BEGIN
        SET @skip += 1; FETCH NEXT FROM c INTO @t; CONTINUE;
    END

    -- how many rows in the ASSH source?
    DECLARE @sql NVARCHAR(MAX) =
        N'SELECT @n = (SELECT COUNT(*) FROM ASSH.dbo.' + QUOTENAME(@t) + N')';
    EXEC sp_executesql @sql, N'@n INT OUTPUT', @n = @src OUTPUT;

    IF @src = 0
    BEGIN
        SET @skip += 1; FETCH NEXT FROM c INTO @t; CONTINUE;   -- empty month, skip quietly
    END

    BEGIN TRY
        IF OBJECT_ID('TestASSH.dbo.' + QUOTENAME(@t)) IS NOT NULL
            EXEC ('DROP TABLE TestASSH.dbo.' + @t);
        EXEC ('SELECT * INTO TestASSH.dbo.' + @t + ' FROM ASSH.dbo.' + @t);
        PRINT 'COPIED ' + @t + '  (' + CAST(@src AS VARCHAR(12)) + ' rows)';
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
PRINT 'Copied ' + CAST(@ok AS VARCHAR(10)) + ' Atten_ tables with data; skipped '
      + CAST(@skip AS VARCHAR(10)) + ' (empty/absent).';

/* verify: which months now have data in TestASSH */
SELECT t.name, SUM(p.rows) AS rows
FROM sys.tables t JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0,1)
WHERE t.name LIKE 'Atten[_]%'
GROUP BY t.name HAVING SUM(p.rows) > 0
ORDER BY t.name DESC;
