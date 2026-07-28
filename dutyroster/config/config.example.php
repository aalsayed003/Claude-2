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
        'grace_late_min'    => 15,  // late-in counts only when > this (so 16 min and above)
        'grace_early_min'   => 15,  // early-out counts only when > this
        'ot_min_threshold'  => 60,  // overtime counts only from this many minutes
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
        'roster_hdr'   => 'AllotShift',       // FINAL uploaded roster header (per employee/month)
        'roster_dtl'   => 'AllotShiftDetail', // FINAL detail, keyed by ShiftDate
        'roster_draft_hdr' => 'Allot_Shift',       // DRAFT roster pending approval
        'roster_draft_dtl' => 'Allot_ShiftDetails',// DRAFT detail, keyed by ShiftDay + RequestId
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

        // Daily attendance lives in monthly tables named <prefix>MMYYYY,
        // e.g. Atten_092023. Status is derived from roster + punches (these
        // tables have no status letter). `attendancehistory` is the correction
        // audit log (Status M/I/D + Prev* values), used only for corrections.
        'att_month_prefix' => 'Atten_',

        // Schedule_Request approval states, decoded from
        // sp_wDREmployeeScheduleRequest. Chain: Submitted -> Dept Head -> MD/COO/CNO,
        // then Uploaded=1 applies it to the final AllotShift roster.
        'schedule_states' => [
            1 => 'Submitted',
            2 => 'Department Head Approved',
            3 => 'MD/COO/CNO Approved',
        ],
        // Dashboard "pending" schedules = Uploaded=0 AND Approved IN (these).
        'schedule_pending_codes' => [1, 2],

        // The roster is prepared per CALENDAR month; attendance/correction use
        // a cutoff cycle. The real cutoff day is PCoff.PCoffDay + 1 (read it from
        // the DB rather than hardcoding attendance.cutoff_day).
        'roster_calendar_month' => true,
        'cutoff_source_table'   => 'PCoff',   // PCoffDay + 1

        // Overtime is DERIVED (not stored): early-in (LateIn) + late-out
        // (UnderTime) between scheduled (AllotShiftDetail) and actual (Atten_).
        // Per-category floor MinOverTimeLimit comes from HRCategory.
        'ot_exclude_shift_ids' => [103],   // + shifts listed in the Leave table

        // Shared DR request state enum (DR_ChangeSchedule, DR_OverTime,
        // DR_CorrectionRequest). CONFIRMED: 10 = Expired (nightly batch).
        // The rest are PROVISIONAL (from data distribution + storyboard legend) —
        // confirm from the approve proc's CASE before wiring approve/reject writes.
        'dr_states' => [
            1  => 'Pending',
            3  => 'Approved (L1)',
            4  => 'Approved (L2)',
            5  => 'Approved (final)',
            6  => 'Approved (6)',
            10 => 'Expired',            // confirmed
            11 => 'Rejected?',
            13 => 'Applied?',
            14 => 'Applied',
            15 => 'Rejected/Cancelled?',
        ],
        'dr_pending_states' => [1, 3, 4, 5, 6],  // provisional: counted as pending
        'dr_initial_state'  => 1,                 // StateID for a newly-submitted request

        // Approval routing by employee category (request/roster approvals):
        //   nurse : Dept Head -> CNO -> HR apply
        //   doctor: Dept Head -> COO/MD -> HR apply
        //   other : Dept Head -> HR apply
        // Category is derived from the employee (CategoryID / Designation) -
        // confirm the nurse/doctor category ids.
        'approval_chains' => [
            'nurse'   => ['dept_head', 'cno', 'hr'],
            'doctor'  => ['dept_head', 'coo_md', 'hr'],
            'default' => ['dept_head', 'hr'],
        ],
        'ot_state_expired'  => 10,                // DR_OverTime.StateID 10 = Expired

        // Single-database deployment: the DB_ASSH duty-roster tables
        // (DR_CorrectionRequest, EmployeeWorkingHours) are consolidated INTO the
        // app database via Consolidate_DB_ASSH_into_TestASSH.sql, so there is no
        // cross-database dependency. Leave companion_db empty to resolve these
        // tables inside the main `db` database.
        'companion_db'          => '',   // '' = same database as `db`
        'correction_table'      => 'DR_CorrectionRequest', // EmployeeID, DayFor, StateID, TypeID
        'working_hours_table'   => 'EmployeeWorkingHours',
        // DR_CorrectionRequest.TypeID: 0,2 = late-in; 1,3 = early-out.

        // Raw biometric punch table (one row per punch). When present, View
        // Attendance pairs punches against the roster schedule itself — which
        // fixes split-duty (reads the 2nd in) and overnight shifts (the
        // after-midnight out is attributed to the shift's day, not the next).
        // If this table isn't in the app DB, the app falls back to the
        // pre-paired Atten_MMYYYY tables. Use a cross-DB name (e.g.
        // 'zkteco_biotime.dbo.checkinout') if it lives in another database.
        'punch_table'     => 'checkinout',
        'punch_pin_col'   => 'pin',        // matched to the employee's 9-digit PIN
        'punch_time_col'  => 'checktime',
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
        // App login table. In legacy mode the app lives in the ASSH schema, so
        // use a prefixed name that can't collide with any legacy table.
        'users_table'  => 'dr_app_users',
    ],
];
