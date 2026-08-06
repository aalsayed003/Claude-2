<?php
/**
 * Payroll — configuration.
 * Copy this file to config.php and edit for your site. config.php is git-ignored.
 *
 * This is a STANDALONE app. It connects to the SQL Server database that holds
 * the HR masters and payroll register (Employee, Department, CurrentDetails,
 * CurrentMonth) and creates its own Pay_* tables there. Attendance comes from
 * the Duty Roster system and is turned on later — see 'roster_link' below.
 */
return [
    'app' => [
        'name'      => 'Payroll',
        'org'       => 'Al Salam Hospital',
        'base_url'  => '/',            // e.g. '/payroll/' if in a sub-folder
        'timezone'  => 'Asia/Bahrain',
        'env'       => 'production',   // 'development' shows detailed errors
    ],

    // ---------------------------------------------------------------------
    // DUTY ROSTER LINK
    // ---------------------------------------------------------------------
    // Payroll cannot produce attendance on its own. While this is OFF, payroll
    // assumes FULL attendance: everyone is paid their full structure, prorated
    // only for a mid-month join/leave — no absence, lates, early-outs or
    // overtime. Salary structures, GOSI, loans, holds, encashment, increments,
    // settlements, register, payslips and the bank file all work in this state.
    //
    // Turn this ON once the Duty Roster / HRMS tables are reachable from this
    // database (roster: AllotShift/AllotShiftDetail, attendance: Atten_MMYYYY,
    // overtime: DR_OverTime, leave: LeaveApplication, corrections:
    // DR_CorrectionRequest). Then payroll becomes attendance-driven. This is a
    // config change only.
    'roster_link' => [
        'enabled'   => false,
        // If the roster tables live in another database on the same server,
        // set the prefix used to reach them, e.g. 'ASSH.dbo.'. Empty = same DB.
        'db_prefix' => '',
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
        'ot_state_expired'  => 10,                // DR_OverTime.StateID 10 = Expired

        // Single-database deployment: the DB_ASSH duty-roster tables
        // (DR_CorrectionRequest, EmployeeWorkingHours) are consolidated INTO the
        // app database via Consolidate_DB_ASSH_into_TestASSH.sql, so there is no
        // cross-database dependency. Leave companion_db empty to resolve these
        // tables inside the main `db` database.
        // The companion database holding the DR_* tables and working-hours
        // targets. CONFIRMED on the live server: DR_CorrectionRequest,
        // DR_OverTime and EmployeeWorkingHours live in DB_ASSH, NOT ASSH
        // (ASSH.DR_OverTime has ~5 rows; DB_ASSH.DR_OverTime has ~3,963).
        //   Production : 'DB_ASSH'
        //   TestASSH   : '' (the DR_* tables were consolidated into TestASSH)
        'companion_db'          => 'DB_ASSH',
        'correction_table'      => 'DR_CorrectionRequest', // EmployeeID, DayFor, StateID, TypeID
        'working_hours_table'   => 'EmployeeWorkingHours',
        // DR_CorrectionRequest.TypeID: 0,2 = late-in; 1,3 = early-out.
    ],

    // ---------------------------------------------------------------------
    // PAYROLL
    // ---------------------------------------------------------------------
    // The payroll module computes the monthly register from the roster and
    // attendance already in this database and writes the result to the LEGACY
    // payroll tables (CurrentDetails = salary structure, CurrentMonth = the
    // monthly register). Everything around the register — run lock, audit,
    // GOSI rates, loans, settlements — lives in the additive Pay_* tables
    // created by database/payroll.sqlserver.sql.
    'payroll' => [
        'enabled'   => true,
        'currency'  => 'BHD',
        'decimals'  => 3,

        // Table names. The first two are legacy; the Pay_* ones are created by
        // database/payroll.sqlserver.sql.
        'tables' => [
            'structure'     => 'CurrentDetails',
            'register'      => 'CurrentMonth',
            'monthly_allow' => 'MonthlyAllowances',
            'ot_month'      => 'overtime',
            'run'           => 'Pay_Run',
            'run_audit'     => 'Pay_RunAudit',
            'statutory'     => 'Pay_EmployeeStatutory',
            'bank'          => 'Pay_Bank',
            'gosi_rate'     => 'Pay_GosiRate',
            'loan'          => 'Pay_Loan',
            'loan_inst'     => 'Pay_LoanInstallment',
            'settlement'    => 'Pay_Settlement',
            'wps_export'    => 'Pay_WpsExport',
            'salary_hold'   => 'Pay_SalaryHold',
            'leave_encash'  => 'Pay_LeaveEncashment',
            'indemnity_prov'=> 'Pay_IndemnityProvision',
            'leave_prov'    => 'Pay_LeaveProvision',
            'leave_request' => 'Pay_LeaveRequest',
            'hr_request'    => 'Pay_HrRequest',
            'cme_req'       => 'Pay_CmeRequirement',
            'cme_activity'  => 'Pay_CmeActivity',
            'cme_cat_req'   => 'Pay_CmeCategoryRequirement',
        ],

        // Staff categories (Employee.CategoryID => label) used by the CME
        // requirement master. Fill in from your HR category master. If left
        // empty, the CME master lists whatever CategoryID values exist on
        // employees so you can label and set hours for each.
        'staff_categories' => [
            // 1 => 'Doctor',
            // 2 => 'Nursing',
            // 3 => 'Allied Health',
            // 4 => 'Administration',
        ],

        // Annual leave provision (accrued untaken leave valued at basic wage).
        'leave_provision' => [
            'annual_entitlement_days' => 30,     // full-year entitlement
            'accrual_from_join'       => true,   // pro-rate entitlement to service in the year
            'wage_basis'              => 'basic', // value the balance on basic (or 'gross')
            'day_divisor'             => 30,
            'carryover_cap_days'      => 60,     // balance above this is forfeited
            // Read the live balance/used from the HR leavebalance / LeaveApplication
            // tables when available; otherwise derive from entitlement only.
            'use_hr_tables'           => true,
            'annual_leave_ids'        => [],     // leave.ID values that are annual leave (empty = all)
        ],

        // Employee self-service leave request types.
        'leave_types' => ['Annual', 'Sick', 'Emergency', 'Unpaid', 'Maternity', 'Bereavement'],

        // Categories an employee can raise a request to HR under.
        'hr_request_categories' => ['Salary certificate', 'Experience letter', 'Leave query',
                                    'Payslip query', 'Bank / IBAN update', 'Other'],

        // Continuing Medical Education / training hours.
        'cme' => [
            'required_hours_per_year' => 50,   // default; per-employee override in Pay_CmeRequirement
        ],

        // A payroll month covers one attendance cutoff cycle (16th -> 15th).
        // true  => the cycle 16-Jun .. 15-Jul is the JULY payroll month.
        // false => the same cycle is the JUNE payroll month.
        'month_is_period_end' => true,

        // Basis for the daily rate used to prorate salary and to price
        // absence / unpaid leave / lates.
        //   'fixed'     — always divide by fixed_month_days (usual in Bahrain)
        //   'calendar'  — divide by the number of days in the cycle
        //   'scheduled' — divide by the employee's rostered days in the cycle
        'day_rate_basis'   => 'fixed',
        'fixed_month_days' => 30,

        // What the daily rate is computed from: 'basic' or 'gross'.
        'day_rate_on'      => 'gross',
        // What lates / early-outs are priced on: 'basic' or 'gross'.
        'penalty_rate_on'  => 'basic',
        // Contract hours per day, used for the hourly rate.
        'hours_per_day'    => 8,

        // Deduct late-in and early-out minutes from pay at all?
        // The hospital may prefer these as report-only, with real money only
        // for absence and unpaid leave.
        'deduct_lates'      => true,
        'deduct_undertime'  => true,
        // Minutes per month forgiven before any late deduction bites.
        'late_grace_minutes_month' => 0,

        // Overtime multipliers applied to the hourly rate. Bahrain Labour Law
        // sets the statutory floor; the hospital may pay above it.
        'ot_rates' => [
            'normal'  => 1.25,   // ordinary working day
            'night'   => 1.50,   // between the night hours
            'restday' => 1.50,   // weekly rest day
            'holiday' => 1.50,   // public holiday
        ],
        // Overtime is priced on 'basic' or 'gross'.
        'ot_rate_on' => 'basic',
        // Only overtime already APPROVED in DR_OverTime is paid. Setting this
        // false pays the raw derived OT from attendance — do not do that in
        // production.
        'ot_approved_only' => true,
        // DR_OverTime.StateID values that count as approved-and-payable.
        // These follow legacy.dr_states, which is itself provisional — confirm
        // against the approve procedure's CASE before the first live run.
        'ot_paid_states'   => [5, 6, 14],
        // Unit of DR_OverTime.TotalOverTime. Only used when StartOverTime /
        // EndOverTime are missing, which are preferred because they are
        // unambiguous. 'hours' or 'minutes'.
        'ot_source_unit'   => 'hours',
        // DR_CorrectionRequest.StateID values that count as an approved
        // correction. An approved correction waives that day's late-in or
        // early-out so the employee is not docked for a punch already fixed.
        'correction_approved_states' => [5, 6, 13, 14],

        // Leave types (leave.ID) that are UNPAID — these reduce pay at the day
        // rate. Everything else is treated as paid leave. Fill this in from
        // the leave master before the first live run: an empty list means every
        // leave type is paid.
        'unpaid_leave_ids' => [],

        // Shift ids that mean "not working". Shifts referenced by the leave
        // master are detected automatically; add anything else here. Shifts
        // whose name contains DAY OFF / WEEK OFF are also treated as off.
        'day_off_shift_ids' => [],

        // Public holidays in the period, as 'YYYY-MM-DD'. Overtime worked on
        // these is paid at the holiday multiplier.
        'public_holidays' => [],

        // Salary structure (CurrentDetails) -> register (CurrentMonth) map.
        // key        = logical component
        // structure  = column in CurrentDetails (null = computed, not stored)
        // register   = column in CurrentMonth that receives the amount
        // type       = earning | deduction
        // prorate    = reduce by payable days when the employee is not paid
        //              for the full month
        // gosi       = counts toward the GOSI contributory wage
        'components' => [
            'basic'          => ['label' => 'Basic Salary',          'structure' => 'BasicSalary',            'register' => 'Basicpay',                 'type' => 'earning',   'prorate' => true,  'gosi' => true],
            'hra'            => ['label' => 'Housing Allowance',     'structure' => 'HRA',                    'register' => 'HRA',                      'type' => 'earning',   'prorate' => true,  'gosi' => true],
            'transport'      => ['label' => 'Transport Allowance',   'structure' => 'Trsp',                   'register' => 'Trsp',                     'type' => 'earning',   'prorate' => true,  'gosi' => true],
            'risk'           => ['label' => 'Risk Allowance',        'structure' => 'RiskAllow',              'register' => 'RiskAllow',                'type' => 'earning',   'prorate' => true,  'gosi' => true],
            'position'       => ['label' => 'Position Allowance',    'structure' => 'PositionAllowance',      'register' => 'PositionAllowance',        'type' => 'earning',   'prorate' => true,  'gosi' => true],
            'communication'  => ['label' => 'Communication Allow.',  'structure' => 'CommunicationAllownace', 'register' => 'CommunicationAllownace',   'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'duty_manager'   => ['label' => 'Duty Manager Allow.',   'structure' => 'DutyManagerAllowance',   'register' => 'DutyManagerAllowance',     'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'special'        => ['label' => 'Special Allowance',     'structure' => 'SpecialAllowance',       'register' => 'SpecialAllowance',         'type' => 'earning',   'prorate' => true,  'gosi' => true],
            'nature_of_work' => ['label' => 'Nature of Work Allow.', 'structure' => 'NatureOfWorkAllownace',  'register' => 'NatureOfWorkAllownace',    'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'block_leader'   => ['label' => 'Block Leader Allow.',   'structure' => 'BlockLeaderAllownace',   'register' => 'BlockLeaderAllownace',     'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'meal'           => ['label' => 'Meal Allowance',        'structure' => 'MealAllownace',          'register' => 'MealAllownace',            'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'education'      => ['label' => 'Education Allowance',   'structure' => 'EducationalAllownace',   'register' => 'EducationalAllownace',     'type' => 'earning',   'prorate' => false, 'gosi' => false],
            'health_plan'    => ['label' => 'Health Plan',           'structure' => 'HealthPlan',             'register' => 'HealthPlan',               'type' => 'earning',   'prorate' => false, 'gosi' => false],
            'family_plan'    => ['label' => 'Family Plan',           'structure' => 'FamilyPlan',             'register' => 'FamilyPlan',               'type' => 'earning',   'prorate' => false, 'gosi' => false],
            'fixed_incentive'=> ['label' => 'Fixed Incentive',       'structure' => 'FixedIncentive',         'register' => 'FixedIncentive',           'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'other_allow1'   => ['label' => 'Other Allowance 1',     'structure' => 'OtherAllow1',            'register' => 'OtherAllow1',              'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'other_allow2'   => ['label' => 'Other Allowance 2',     'structure' => 'OtherAllow2',            'register' => 'OtherAllow2',              'type' => 'earning',   'prorate' => true,  'gosi' => false],
            'other_earnings' => ['label' => 'Other Earnings',        'structure' => 'OtherEarnings',          'register' => 'OtherEarnings',            'type' => 'earning',   'prorate' => false, 'gosi' => false],
            // Computed each month — no stored structure column.
            'overtime'       => ['label' => 'Overtime',              'structure' => null,                     'register' => 'OverTime',                 'type' => 'earning',   'prorate' => false, 'gosi' => false],
            'leave_encash'   => ['label' => 'Leave Encashment',      'structure' => null,                     'register' => 'LeaveEncash',              'type' => 'earning',   'prorate' => false, 'gosi' => false],
            'arrear'         => ['label' => 'Arrears',               'structure' => null,                     'register' => 'Arrear',                   'type' => 'earning',   'prorate' => false, 'gosi' => false],
            'pos_adjust'     => ['label' => 'Positive Adjustment',   'structure' => null,                     'register' => 'PositiveAdjust',           'type' => 'earning',   'prorate' => false, 'gosi' => false],

            'gosi'           => ['label' => 'GOSI / SIO',            'structure' => null,                     'register' => 'GOSI',                     'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'absence'        => ['label' => 'Absence Deduction',     'structure' => null,                     'register' => 'Absences',                 'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'unpaid_leave'   => ['label' => 'Unpaid Leave',          'structure' => null,                     'register' => 'unpaidleave',              'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'lates'          => ['label' => 'Late Deduction',        'structure' => null,                     'register' => 'Lates',                    'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'undertime'      => ['label' => 'Early-out Deduction',   'structure' => null,                     'register' => 'Undertimes',               'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'loan'           => ['label' => 'Staff Loan',            'structure' => null,                     'register' => 'LoanAmount',               'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'bank_loan'      => ['label' => 'Bank Loan',             'structure' => null,                     'register' => 'bankloan',                 'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'other_loan'     => ['label' => 'Other Loan',            'structure' => null,                     'register' => 'otherloan',                'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'advance'        => ['label' => 'Salary Advance',        'structure' => null,                     'register' => 'Advance',                  'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'penalty'        => ['label' => 'Penalty',               'structure' => null,                     'register' => 'Penalty',                  'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'phone_bills'    => ['label' => 'Phone Bills',           'structure' => null,                     'register' => 'PhoneBills',               'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'elec_bills'     => ['label' => 'Electricity',           'structure' => null,                     'register' => 'ElectricityBill',          'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'other_ded1'     => ['label' => 'Other Deduction 1',     'structure' => null,                     'register' => 'OtherDed1',                'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'other_ded2'     => ['label' => 'Other Deduction 2',     'structure' => null,                     'register' => 'OtherDed2',                'type' => 'deduction', 'prorate' => false, 'gosi' => false],
            'neg_adjust'     => ['label' => 'Negative Adjustment',   'structure' => null,                     'register' => 'NegativeAdjust',           'type' => 'deduction', 'prorate' => false, 'gosi' => false],
        ],

        // Social insurance. Rates live in Pay_GosiRate (effective-dated) so the
        // annual step-up is a data change, not a code change.
        'gosi' => [
            'enabled'          => true,
            // Employer share is reported for the SIO return but never deducted
            // from the employee.
            'post_employer_share' => false,
            // Fallback used only when Pay_GosiRate has no matching row.
            'fallback' => ['bahraini' => ['employee' => 8.0, 'employer' => 13.0],
                           'expat'    => ['employee' => 1.0, 'employer' => 4.0],
                           'cap'      => 4000],
        ],

        // End-of-service indemnity. Bahrain Labour Law (2012) art. 111 is the
        // classic basis: 15 days' wage per year for the first 3 years, 30 days
        // per year thereafter, pro-rated for part years. Confirm the treatment
        // that applies to your expat staff under the SIO end-of-service scheme
        // before running a live settlement.
        'indemnity' => [
            'enabled'              => true,
            'days_first_tier'      => 15,
            'first_tier_years'     => 3,
            'days_after_tier'      => 30,
            'wage_basis'           => 'gross',   // 'basic' or 'gross'
            'min_service_months'   => 3,         // settlement: below this, no indemnity
            'prorate_part_years'   => true,
            // The Indemnity Provision (balance-sheet accrual for active staff)
            // normally accrues from day one even though a <3-month leaver would
            // be paid nothing. Set this to 3 to match the settlement gate.
            'provision_min_service_months' => 0,
        ],

        // Leave encashment: value of unused annual leave at settlement.
        'leave_encash' => [
            'wage_basis' => 'gross',   // 'basic' or 'gross'
            'day_divisor' => 30,
        ],

        // Wage Protection System / bank transfer file.
        'wps' => [
            'employer_id'    => '',          // LMRA / CBB employer identifier
            'employer_name'  => 'Al Salam Specialist Hospital',
            'employer_bank'  => '',          // paying bank code
            'employer_iban'  => '',
            'format'         => 'csv',       // csv | sif
            'file_prefix'    => 'WPS',
        ],

        // Only these roles may see or touch salary figures.
        'roles' => [
            'view'    => 'fa',      // register, payslips of others
            'process' => 'fa',      // create run, calculate
            'approve' => 'coo',     // approve + lock
        ],
    ],

    'security' => [
        'session_name' => 'DUTYROSTER_SID',
        'session_ttl'  => 3600 * 8,   // seconds
    ],
];
