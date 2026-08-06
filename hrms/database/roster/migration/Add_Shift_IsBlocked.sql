/*
 * Add_Shift_IsBlocked.sql
 * -----------------------
 * The app can "hide" a shift from the roster dropdowns by setting IsBlocked = 1
 * (Duty Roster Master → Hide). ShiftRepository filters IsBlocked = 0 by default,
 * so every shift query needs this column to exist — without it the Shift Master,
 * roster template, import, corrections, etc. all fail with "Invalid column name
 * 'IsBlocked'".
 *
 * Adds the column (default 0 = visible). Safe to re-run.
 *
 * Run in SSMS (or: sqlcmd -S <server> -E -d TestASSH -i Add_Shift_IsBlocked.sql)
 */

USE TestASSH;
GO

IF COL_LENGTH('dbo.Shift', 'IsBlocked') IS NULL
BEGIN
    ALTER TABLE dbo.Shift
        ADD IsBlocked BIT NOT NULL CONSTRAINT DF_Shift_IsBlocked DEFAULT (0);
    PRINT 'Shift.IsBlocked added (0 = visible in dropdowns, 1 = hidden).';
END
ELSE
    PRINT 'Shift.IsBlocked already exists — nothing to do.';
GO

-- Optional: hide a batch of shifts up front, e.g.
--   UPDATE dbo.Shift SET IsBlocked = 1 WHERE Name IN ('RM9','BF21','BF23');
-- Un-hide with IsBlocked = 0, or use the Hide/Unhide button in Duty Roster Master.
