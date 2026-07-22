<?php
/**
 * Duty Roster — configuration.
 * Copy this file to config.php and edit for your site. config.php is git-ignored.
 */
return [
    'app' => [
        'name'      => 'Duty Roster',
        'org'       => 'Al Salam Hospital',
        'base_url'  => '/',            // e.g. '/dutyroster/' if in a sub-folder
        'timezone'  => 'Asia/Bahrain',
        'env'       => 'production',   // 'development' shows detailed errors
    ],

    // Attendance cutoff cycle. Default: 16th of month -> 15th of next month.
    // A day belongs to the period whose cutoff start is on/before it.
    'attendance' => [
        'cutoff_day'        => 16,
        'grace_late_min'    => 0,   // minutes of grace before "late in" is counted
        'grace_early_min'   => 0,   // minutes of grace before "early out" is counted
        'ot_min_threshold'  => 30,  // ignore OT shorter than this many minutes
        'week_off_days'     => [5, 6], // 0=Sun..6=Sat  -> Fri & Sat (informational)
    ],

    // The application database. In "legacy" mode this points at the existing
    // ASSH schema (use TestASSH for development). See 'legacy' below.
    'db' => [
        'driver'   => 'sqlsrv',       // sqlsrv | mysql | pgsql
        'host'     => '127.0.0.1',    // SQL Server host, or  HOST\INSTANCE
        'port'     => 1433,           // omit/ignore when using a named instance
        'database' => 'TestASSH',     // TestASSH for dev; ASSH for production
        'username' => 'duty_app',
        'password' => 'change-me',
        'charset'  => 'utf8mb4',      // used by mysql only
    ],

    // Legacy-table mapping. When 'enabled' => true, the app reads/writes the
    // existing ASSH duty-roster tables instead of its own clean schema, so it
    // is a drop-in for the old desktop app.
    // NOTE: the live employee/department masters are `Employee` and `Department`.
    // `EmployeeNew` / `DepartmentNew` / `ScheduleStatus` belong to other systems
    // and are NOT used here.
    'legacy' => [
        'enabled'      => true,
        'employee'     => 'Employee',
        'department'   => 'Department',
        'designation'  => 'Designation',
        'shift'        => 'Shift',
        'roster_hdr'   => 'AllotShift',       // header: one per employee per month
        'roster_dtl'   => 'AllotShiftDetail', // detail: one per day
        'att_history'  => 'attendancehistory',
        'punch_daily'  => 'empPunchingDetails',
        'dashboard'    => 'DRMainDashBoard',
        'sched_req'    => 'Schedule_Request',
        'sched_act'    => 'Schedule_RequestActions',
        'sched_status' => 'ScheduleStatus',
        'ot'           => 'DR_OverTime',
        'ot_reason'    => 'DR_OvertimeReason',
        'change_sched' => 'DR_ChangeSchedule',
        'leave'        => 'leave',
        'leave_app'    => 'LeaveApplication',
        'leave_bal'    => 'leavebalance',
        'sys_users'    => 'RA_SystemUsers',

        // attendancehistory.Status is a char(1). Map each code to an app status
        // (present|absent|day_off|holiday|leave). Confirm the real codes from the
        // data: SELECT DISTINCT Status, COUNT(*) FROM attendancehistory GROUP BY Status.
        'status_map' => [
            'P' => 'present', 'A' => 'absent', 'H' => 'holiday',
            'O' => 'day_off', 'W' => 'day_off', 'L' => 'leave',
        ],
    ],

    // The biometric source: the SQL Server DB that the ZKTeco auto-sync script
    // writes into (database `zkteco_biotime`, table `checkinout`). The ingest
    // job reads NEW rows from here into `punches` (read-only login is enough).
    //
    // Column mapping (confirmed against the live insert):
    //   sn        -> floor / location   (e.g. "10th Floor")   => device_name
    //   sn_name   -> device code        (e.g. "BRCP174860007")=> device_sn
    //   checktype -> always 1, so IN/OUT is inferred by punch order, not this.
    'punch_source' => [
        'enabled'  => false,          // set true once credentials are filled in
        'driver'   => 'sqlsrv',
        'host'     => '127.0.0.1',    // SQL Server host / instance
        'port'     => 1433,
        'database' => 'zkteco_biotime',
        'username' => 'reader',
        'password' => 'change-me',
        // Must return these aliased columns. `:last_id` is bound to the highest
        // source_id already imported (idempotent incremental load).
        'query'    => "SELECT id AS source_id, pin, checktime AS punch_time,
                              checktype AS check_type,
                              sn   AS device_name,
                              sn_name AS device_sn,
                              area_name AS area_name
                       FROM checkinout
                       WHERE id > :last_id
                       ORDER BY id ASC",
    ],

    'security' => [
        'session_name' => 'DUTYROSTER_SID',
        'session_ttl'  => 3600 * 8,   // seconds
    ],
];
