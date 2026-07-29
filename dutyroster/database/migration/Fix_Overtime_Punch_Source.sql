/* ============================================================================
   FIX: "Eligible OT (from punches)" is empty (and View Attendance shows no
        punches) in the Duty Roster app.

   ROOT CAUSE
   ----------
   The app derives overtime and attendance from the raw biometric punches. It
   looks for that punch table INSIDE its own database under the name
   `checkinout` (config key legacy.punch_table, default 'checkinout'). At ASSH
   the punches actually live in a SEPARATE database:
        zkteco_biotime.dbo.checkinout   (columns: pin, checktime, ...)
   so the app's own database can't see them, and both the OT "Eligible OT (from
   punches)" list and the punch pairing in View Attendance come up empty.

   THE FIX
   -------
   Expose the biometric punches inside the APP database as a VIEW named
   dbo.checkinout that points at the biometric database. The app then finds them
   with NO config change. (The app was updated to recognise a view/synonym here,
   not only a real table.)

   Run this in the APP database:  TestASSH for development, ASSH for production.
   The app's SQL login must have SELECT permission on
   zkteco_biotime.dbo.checkinout.
   ============================================================================ */

USE TestASSH;          -- <<< change to ASSH in production
GO

/* 0) Sanity check: can this database/login even see the biometric table? */
IF OBJECT_ID('zkteco_biotime.dbo.checkinout') IS NULL
    PRINT '>> WARNING: zkteco_biotime.dbo.checkinout is NOT visible from here. '
        + 'Check the database name and that the app login has SELECT on it.';
ELSE
    PRINT '>> OK: zkteco_biotime.dbo.checkinout is visible.';
GO

/* 1) If a REAL table named checkinout already exists here, do nothing (don't
      overwrite live data). Otherwise (re)create the view. */
IF OBJECT_ID('dbo.checkinout', 'U') IS NOT NULL
BEGIN
    PRINT '>> A real table dbo.checkinout already exists here; leaving it as-is.';
END
ELSE
BEGIN
    IF OBJECT_ID('dbo.checkinout', 'V') IS NOT NULL
        DROP VIEW dbo.checkinout;

    /* SELECT * so every biometric column (incl. pin + checktime) is exposed.
       The app only needs pin (matched to the 9-digit PIN) and checktime. */
    EXEC('CREATE VIEW dbo.checkinout AS
             SELECT * FROM zkteco_biotime.dbo.checkinout');

    PRINT '>> Created view dbo.checkinout -> zkteco_biotime.dbo.checkinout';
END
GO

/* 2) VERIFY — should return recent punch rows for a test employee.
      Replace the PIN and the date window with a real case you expect OT for.
      Remember the OT/attendance cutoff is the 16th -> 15th, so for OT on, say,
      17 Apr 2026 use the window 16 Apr 2026 -> 16 May 2026 (period "May 2026"). */
SELECT TOP (20) pin, checktime
  FROM dbo.checkinout
 WHERE pin = '000001732'                              -- <<< employee 9-digit PIN
   AND checktime >= '2026-04-16' AND checktime < '2026-05-16'
 ORDER BY checktime;
GO

/* ----------------------------------------------------------------------------
   ALTERNATIVE (no view, no SQL): instead of this script you can simply set, in
   the app's config.php, the cross-database name directly:

       'punch_table' => 'zkteco_biotime.dbo.checkinout',

   Either approach makes the punches reachable. Use ONE of them, not both.
   ---------------------------------------------------------------------------- */
