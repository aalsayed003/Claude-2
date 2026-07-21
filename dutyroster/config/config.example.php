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

    // The application's own database.
    'db' => [
        'driver'   => 'mysql',        // mysql | sqlsrv | pgsql
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'duty_roster',
        'username' => 'duty_roster',
        'password' => 'change-me',
        'charset'  => 'utf8mb4',
    ],

    // The biometric source that BioTime / ZKTeco writes into.
    // The ingest job reads NEW rows from here into `punches`.
    // Set 'enabled' => false until you have the real connection details.
    'punch_source' => [
        'enabled'  => false,
        'driver'   => 'sqlsrv',       // sqlsrv | mysql | pgsql
        'host'     => '127.0.0.1',
        'port'     => 1433,
        'database' => 'biotime',
        'username' => 'reader',
        'password' => 'change-me',
        // Query used to pull new punches. Must return the aliased columns below.
        // `:last_id` is bound to the highest source_id already imported.
        'query'    => "SELECT id AS source_id, pin, checktime AS punch_time,
                              checktype AS check_type, sn_name AS device_name,
                              SN AS device_sn, area_name AS area_name
                       FROM checkinout
                       WHERE id > :last_id
                       ORDER BY id ASC",
    ],

    'security' => [
        'session_name' => 'DUTYROSTER_SID',
        'session_ttl'  => 3600 * 8,   // seconds
    ],
];
