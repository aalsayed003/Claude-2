/*
 * Restore_Identity_AutoNumbering.sql
 * ----------------------------------
 * SELECT ... INTO (used to build TestASSH from ASSH / DB_ASSH) copies data but
 * DROPS the IDENTITY property. Any NOT NULL key column that the application does
 * not populate itself then fails on INSERT with:
 *
 *     Cannot insert the value NULL into column '<col>', table
 *     'TestASSH.dbo.<table>'; column does not allow nulls. INSERT fails.
 *
 * This restores auto-numbering IN PLACE, without rebuilding the tables:
 *   - a SEQUENCE seeded at MAX(<col>) + 1 (so existing ids are never reused), and
 *   - a DEFAULT constraint that draws the next value when an INSERT omits the id.
 *
 * Existing rows and their ids are untouched. Safe to re-run: any column that is
 * already IDENTITY or already has a DEFAULT is skipped.
 *
 * Run in SSMS (or: sqlcmd -S <server> -E -d TestASSH -i Restore_Identity_AutoNumbering.sql)
 */

USE TestASSH;
GO
SET NOCOUNT ON;

/* ---- 1) Columns to give auto-numbering ----------------------------------
   These are the NOT NULL key columns the app inserts without supplying.
   Add more (tbl, col) rows here if part 2 below reveals another one.        */
DECLARE @targets TABLE (tbl SYSNAME, col SYSNAME);
INSERT INTO @targets (tbl, col) VALUES
    ('dbo.DR_CorrectionRequest', 'SNo'),        -- legacy serial (correction)
    ('dbo.DR_CorrectionRequest', 'RequestID'),  -- correction request id
    ('dbo.Schedule_Request',     'ID'),         -- roster submission id
    ('dbo.AllotShift',           'ID'),         -- roster header id
    ('dbo.AllotShiftDetail',     'ID'),         -- roster day-row id
    ('dbo.DR_ChangeSchedule',    'SNo'),        -- change-schedule serial (if present)
    ('dbo.DR_OverTime',          'SNo');        -- overtime serial (if present)

DECLARE @tbl SYSNAME, @col SYSNAME, @seq SYSNAME, @df SYSNAME, @start BIGINT, @sql NVARCHAR(MAX);
DECLARE cur CURSOR LOCAL FAST_FORWARD FOR SELECT tbl, col FROM @targets;
OPEN cur; FETCH NEXT FROM cur INTO @tbl, @col;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF OBJECT_ID(@tbl,'U') IS NULL
        PRINT @tbl + ': table not found - skipped';
    ELSE IF COLUMNPROPERTY(OBJECT_ID(@tbl), @col, 'ColumnId') IS NULL
        PRINT @tbl + '.' + @col + ': column not found - skipped';
    ELSE IF COLUMNPROPERTY(OBJECT_ID(@tbl), @col, 'IsIdentity') = 1
        PRINT @tbl + '.' + @col + ': already IDENTITY - nothing to do';
    ELSE IF EXISTS (SELECT 1 FROM sys.default_constraints dc
                    JOIN sys.columns c ON c.object_id = dc.parent_object_id
                                      AND c.column_id = dc.parent_column_id
                    WHERE dc.parent_object_id = OBJECT_ID(@tbl) AND c.name = @col)
        PRINT @tbl + '.' + @col + ': already has a DEFAULT - nothing to do';
    ELSE
    BEGIN
        SET @seq = 'seq_' + REPLACE(REPLACE(@tbl,'dbo.',''),'.','_') + '_' + @col;
        SET @df  = 'DF_'  + REPLACE(REPLACE(@tbl,'dbo.',''),'.','_') + '_' + @col;

        SET @sql = N'SELECT @s = ISNULL(MAX(' + QUOTENAME(@col) + N'),0) + 1 FROM ' + @tbl + N';';
        EXEC sp_executesql @sql, N'@s BIGINT OUTPUT', @s = @start OUTPUT;

        IF OBJECT_ID('dbo.' + @seq, 'SO') IS NOT NULL EXEC('DROP SEQUENCE dbo.' + @seq + ';');
        EXEC('CREATE SEQUENCE dbo.' + @seq + ' AS BIGINT START WITH '
             + CAST(@start AS VARCHAR(20)) + ' INCREMENT BY 1;');
        EXEC('ALTER TABLE ' + @tbl + ' ADD CONSTRAINT ' + @df
             + ' DEFAULT (NEXT VALUE FOR dbo.' + @seq + ') FOR ' + QUOTENAME(@col) + ';');

        PRINT @tbl + '.' + @col + ': auto-numbering enabled (starts at ' + CAST(@start AS VARCHAR(20)) + ')';
    END
    FETCH NEXT FROM cur INTO @tbl, @col;
END
CLOSE cur; DEALLOCATE cur;
GO

/* ---- 2) Diagnostic: any column that could STILL block an INSERT ----------
   Lists NOT NULL, non-identity, no-default columns for the write-path tables.
   Anything the app doesn't set (a legacy flag/id) is a potential next failure
   — add it to @targets above (or give it a literal DEFAULT) and re-run.       */
SELECT  OBJECT_NAME(c.object_id) AS table_name,
        c.column_id,
        c.name                   AS column_name,
        t.name                   AS data_type
FROM sys.columns c
JOIN sys.types t ON t.user_type_id = c.user_type_id
LEFT JOIN sys.default_constraints dc
       ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
WHERE c.object_id IN (
        OBJECT_ID('dbo.DR_CorrectionRequest'), OBJECT_ID('dbo.Schedule_Request'),
        OBJECT_ID('dbo.AllotShift'), OBJECT_ID('dbo.AllotShiftDetail'),
        OBJECT_ID('dbo.DR_ChangeSchedule'), OBJECT_ID('dbo.DR_OverTime'))
  AND c.is_nullable = 0 AND c.is_identity = 0 AND dc.object_id IS NULL
ORDER BY table_name, c.column_id;
GO
