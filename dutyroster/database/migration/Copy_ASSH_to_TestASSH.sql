/* ================================================================
   Copy ALL table data:  ASSH  ->  TestASSH   (same SQL Server instance)

   Run AFTER Create_TestASSH.sql has built the empty TestASSH schema.

   What it does, per table:
     - builds an explicit column list, EXCLUDING computed and
       rowversion/timestamp columns (those can't be inserted);
     - wraps the insert in SET IDENTITY_INSERT ON/OFF when the table
       has an identity column, so original IDs are preserved;
     - copies with  INSERT INTO TestASSH.. SELECT FROM ASSH..;
     - continues past any table that fails (prints SKIP + reason),
       so one bad table never aborts the whole run.

   Foreign keys in TestASSH are disabled during the copy and
   re-enabled at the end, so table order doesn't matter.

   NOTE: this clones the FULL database (clinical, billing, HR, PII).
   Treat TestASSH with the same access controls as production.
   ================================================================ */
SET NOCOUNT ON;

-- Keep the transaction log from ballooning during a large bulk copy.
ALTER DATABASE [TestASSH] SET RECOVERY SIMPLE;
GO

/* ---- 1. Disable all foreign keys in TestASSH ---- */
DECLARE @disable NVARCHAR(MAX) = N'';
SELECT @disable = @disable
     + N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name)
     + N' NOCHECK CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
FROM TestASSH.sys.foreign_keys fk
JOIN TestASSH.sys.tables t ON t.object_id = fk.parent_object_id;
IF @disable <> N'' EXEC (N'USE [TestASSH]; ' + @disable);
PRINT 'Foreign keys disabled.';
GO

/* ---- 2. Copy data table-by-table ---- */
DECLARE @tbl SYSNAME, @cols NVARCHAR(MAX), @sql NVARCHAR(MAX), @hasIdent BIT;
DECLARE @ok INT = 0, @skip INT = 0;

DECLARE c CURSOR LOCAL FAST_FORWARD FOR
    SELECT t.name
    FROM ASSH.sys.tables t
    WHERE t.is_ms_shipped = 0
    ORDER BY t.name;

OPEN c;
FETCH NEXT FROM c INTO @tbl;
WHILE @@FETCH_STATUS = 0
BEGIN
    -- insertable columns only (skip computed + rowversion/timestamp)
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
        SET @skip += 1;
        FETCH NEXT FROM c INTO @tbl;
        CONTINUE;
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
        -- comment out the next line for a quieter run
        PRINT 'OK   ' + @tbl;
    END TRY
    BEGIN CATCH
        SET @skip += 1;
        PRINT 'SKIP ' + @tbl + '  -> ' + ERROR_MESSAGE();
    END CATCH

    FETCH NEXT FROM c INTO @tbl;
END
CLOSE c;
DEALLOCATE c;
PRINT '--------------------------------------------';
PRINT 'Copied OK: ' + CAST(@ok AS VARCHAR(10)) + '   Skipped: ' + CAST(@skip AS VARCHAR(10));
GO

/* ---- 3. Re-enable and re-validate foreign keys in TestASSH ---- */
DECLARE @enable NVARCHAR(MAX) = N'';
SELECT @enable = @enable
     + N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name)
     + N' WITH CHECK CHECK CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
FROM TestASSH.sys.foreign_keys fk
JOIN TestASSH.sys.tables t ON t.object_id = fk.parent_object_id;
IF @enable <> N'' EXEC (N'USE [TestASSH]; ' + @enable);
PRINT 'Foreign keys re-enabled.';
GO
