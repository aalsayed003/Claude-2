<?php
/**
 * ASSH HRMS — MERGED config (duty roster + payroll).
 * Generated from both apps config.example.php. Copy to config.php and edit.
 * roster_link.enabled=true so payroll reads real attendance from the roster.
 */
return array (
  'app' => 
  array (
    'name' => 'ASSH HRMS',
    'org' => 'Al Salam Hospital',
    'base_url' => '/',
    'timezone' => 'Asia/Bahrain',
    'env' => 'production',
  ),
  'roster_link' => 
  array (
    'enabled' => true,
    'db_prefix' => '',
  ),
  'attendance' => 
  array (
    'cutoff_day' => 16,
    'grace_late_min' => 15,
    'grace_early_min' => 15,
    'ot_min_threshold' => 60,
    'week_off_days' => 
    array (
      0 => 5,
      1 => 6,
    ),
  ),
  'db' => 
  array (
    'driver' => 'sqlsrv',
    'host' => '127.0.0.1',
    'port' => 1433,
    'database' => 'TestASSH',
    'username' => 'duty_app',
    'password' => 'change-me',
    'charset' => 'utf8mb4',
  ),
  'legacy' => 
  array (
    'enabled' => true,
    'employee' => 'Employee',
    'department' => 'Department',
    'designation' => 'Designation',
    'shift' => 'Shift',
    'roster_hdr' => 'AllotShift',
    'roster_dtl' => 'AllotShiftDetail',
    'roster_draft_hdr' => 'Allot_Shift',
    'roster_draft_dtl' => 'Allot_ShiftDetails',
    'att_history' => 'attendancehistory',
    'punch_daily' => 'empPunchingDetails',
    'dashboard' => 'DRMainDashBoard',
    'sched_req' => 'Schedule_Request',
    'sched_act' => 'Schedule_RequestActions',
    'sched_status' => 'ScheduleStatus',
    'ot' => 'DR_OverTime',
    'ot_reason' => 'DR_OvertimeReason',
    'change_sched' => 'DR_ChangeSchedule',
    'leave' => 'leave',
    'leave_app' => 'LeaveApplication',
    'leave_bal' => 'leavebalance',
    'sys_users' => 'RA_SystemUsers',
    'att_month_prefix' => 'Atten_',
    'schedule_states' => 
    array (
      1 => 'Submitted',
      2 => 'Department Head Approved',
      3 => 'MD/COO/CNO Approved',
    ),
    'schedule_pending_codes' => 
    array (
      0 => 1,
      1 => 2,
    ),
    'roster_calendar_month' => true,
    'cutoff_source_table' => 'PCoff',
    'ot_exclude_shift_ids' => 
    array (
      0 => 103,
    ),
    'dr_states' => 
    array (
      1 => 'Pending',
      3 => 'Approved (L1)',
      4 => 'Approved (L2)',
      5 => 'Approved (final)',
      6 => 'Approved (6)',
      10 => 'Expired',
      11 => 'Rejected?',
      13 => 'Applied?',
      14 => 'Applied',
      15 => 'Rejected/Cancelled?',
    ),
    'dr_pending_states' => 
    array (
      0 => 1,
      1 => 3,
      2 => 4,
      3 => 5,
      4 => 6,
    ),
    'ot_state_expired' => 10,
    'companion_db' => '',
    'correction_table' => 'DR_CorrectionRequest',
    'working_hours_table' => 'EmployeeWorkingHours',
    'dr_initial_state' => 1,
    'correction_states' => 
    array (
      'pending' => 1,
      'head_ok' => 3,
      'applied' => 14,
      'rejected' => 11,
    ),
    'approval_chains' => 
    array (
      'nurse' => 
      array (
        0 => 'dept_head',
        1 => 'cno',
        2 => 'hr',
      ),
      'doctor' => 
      array (
        0 => 'dept_head',
        1 => 'coo_md',
        2 => 'hr',
      ),
      'default' => 
      array (
        0 => 'dept_head',
        1 => 'hr',
      ),
    ),
    'request_chains' => 
    array (
      'nurse' => 
      array (
        0 => 'cno',
        1 => 'hr',
      ),
      'doctor' => 
      array (
        0 => 'coo_md',
        1 => 'hr',
      ),
      'default' => 
      array (
        0 => 'dept_head',
        1 => 'hr',
      ),
    ),
    'schedule_reject_code' => 9,
    'dept_category' => 
    array (
    ),
    'punch_table' => 'checkinout',
    'punch_pin_col' => 'pin',
    'punch_time_col' => 'checktime',
  ),
  'payroll' => 
  array (
    'enabled' => true,
    'currency' => 'BHD',
    'decimals' => 3,
    'tables' => 
    array (
      'structure' => 'CurrentDetails',
      'register' => 'CurrentMonth',
      'monthly_allow' => 'MonthlyAllowances',
      'ot_month' => 'overtime',
      'run' => 'Pay_Run',
      'run_audit' => 'Pay_RunAudit',
      'statutory' => 'Pay_EmployeeStatutory',
      'bank' => 'Pay_Bank',
      'gosi_rate' => 'Pay_GosiRate',
      'loan' => 'Pay_Loan',
      'loan_inst' => 'Pay_LoanInstallment',
      'settlement' => 'Pay_Settlement',
      'wps_export' => 'Pay_WpsExport',
      'salary_hold' => 'Pay_SalaryHold',
      'leave_encash' => 'Pay_LeaveEncashment',
      'indemnity_prov' => 'Pay_IndemnityProvision',
      'leave_prov' => 'Pay_LeaveProvision',
      'leave_request' => 'Pay_LeaveRequest',
      'leave_balance' => 'Pay_LeaveBalance',
      'hr_request' => 'Pay_HrRequest',
      'cme_req' => 'Pay_CmeRequirement',
      'cme_activity' => 'Pay_CmeActivity',
      'cme_cat_req' => 'Pay_CmeCategoryRequirement',
    ),
    'staff_categories' => 
    array (
    ),
    'leave_provision' => 
    array (
      'annual_entitlement_days' => 30,
      'accrual_from_join' => true,
      'wage_basis' => 'basic',
      'day_divisor' => 30,
      'carryover_cap_days' => 60,
      'use_hr_tables' => true,
      'annual_leave_ids' => 
      array (
      ),
    ),
    'leave_types' => 
    array (
      0 => 'Annual',
      1 => 'Sick',
      2 => 'Emergency',
      3 => 'Unpaid',
      4 => 'Maternity',
      5 => 'Bereavement',
    ),
    'hr_request_categories' =>
    array (
      0 => 'Salary certificate',
      1 => 'Experience letter',
      2 => 'Leave query',
      3 => 'Payslip query',
      4 => 'Bank / IBAN update',
      5 => 'Other',
    ),
    // Category treated as the "salary certificate" queue on the dashboard tile.
    'salary_certificate_category' => 'Salary certificate',
    // Yearly entitlement (days) per leave type. Types not listed here (e.g.
    // Unpaid) do not draw from a balance.
    'leave_entitlement' =>
    array (
      'Annual' => 30,
      'Sick' => 15,
      'Emergency' => 5,
      'Maternity' => 70,
      'Bereavement' => 5,
    ),
    // Leave types that must have enough balance to be requested and that
    // consume the balance once approved.
    'leave_balance_types' =>
    array (
      0 => 'Annual',
      1 => 'Sick',
      2 => 'Emergency',
      3 => 'Maternity',
      4 => 'Bereavement',
    ),
    // Leave types for which a supporting document (e.g. a medical note) can be
    // attached. Sick leave prompts for it; the field is optional for others.
    'leave_attachment_types' =>
    array (
      0 => 'Sick',
    ),
    // Uploaded supporting documents (leave notes etc.).
    'uploads' =>
    array (
      'dir' => '',                       // '' -> <app>/storage/uploads
      'max_bytes' => 5242880,            // 5 MB
      'allowed_ext' =>
      array (
        0 => 'pdf', 1 => 'jpg', 2 => 'jpeg', 3 => 'png', 4 => 'gif', 5 => 'webp', 6 => 'heic',
      ),
    ),
    // Best-effort OCR of image attachments (free Tesseract CLI). If the binary
    // is missing the attachment is still stored; only the extracted text is skipped.
    'ocr' =>
    array (
      'enabled' => true,
      'bin' => 'tesseract',
      'lang' => 'eng',
    ),
    'cme' => 
    array (
      'required_hours_per_year' => 50,
    ),
    'month_is_period_end' => false,
    // ASSH manual paysheet prorates every component by No.of.Days / days-in-month
    // (July = 31), so day/hour rates divide by the actual calendar days of the
    // payroll month. Use 'fixed' + fixed_month_days for a flat 30-day divisor.
    'day_rate_basis' => 'month_days',
    'fixed_month_days' => 30,
    'day_rate_on' => 'gross',
    'penalty_rate_on' => 'basic',
    'hours_per_day' => 8,
    'deduct_lates' => true,
    'deduct_undertime' => true,
    'late_grace_minutes_month' => 0,
    'ot_rates' => 
    array (
      'normal' => 1.25,
      'night' => 1.5,
      'restday' => 1.5,
      'holiday' => 1.5,
    ),
    'ot_rate_on' => 'basic',
    'ot_approved_only' => true,
    'ot_paid_states' => 
    array (
      0 => 5,
      1 => 6,
      2 => 14,
    ),
    'ot_source_unit' => 'hours',
    'correction_approved_states' => 
    array (
      0 => 5,
      1 => 6,
      2 => 13,
      3 => 14,
    ),
    'unpaid_leave_ids' => 
    array (
    ),
    'day_off_shift_ids' => 
    array (
    ),
    'public_holidays' => 
    array (
    ),
    'components' => 
    array (
      'basic' => 
      array (
        'label' => 'Basic Salary',
        'structure' => 'BasicSalary',
        'register' => 'Basicpay',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => true,
      ),
      'hra' => 
      array (
        'label' => 'Housing Allowance',
        'structure' => 'HRA',
        'register' => 'HRA',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => true,
      ),
      'transport' => 
      array (
        'label' => 'Transport Allowance',
        'structure' => 'Trsp',
        'register' => 'Trsp',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => true,
      ),
      'risk' => 
      array (
        'label' => 'Risk Allowance',
        'structure' => 'RiskAllow',
        'register' => 'RiskAllow',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => true,
      ),
      'position' => 
      array (
        'label' => 'Position Allowance',
        'structure' => 'PositionAllowance',
        'register' => 'PositionAllowance',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => true,
      ),
      'communication' => 
      array (
        'label' => 'Communication Allow.',
        'structure' => 'CommunicationAllownace',
        'register' => 'CommunicationAllownace',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'duty_manager' => 
      array (
        'label' => 'Duty Manager Allow.',
        'structure' => 'DutyManagerAllowance',
        'register' => 'DutyManagerAllowance',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'special' => 
      array (
        'label' => 'Special Allowance',
        'structure' => 'SpecialAllowance',
        'register' => 'SpecialAllowance',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => true,
      ),
      'nature_of_work' => 
      array (
        'label' => 'Nature of Work Allow.',
        'structure' => 'NatureOfWorkAllownace',
        'register' => 'NatureOfWorkAllownace',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'block_leader' => 
      array (
        'label' => 'Block Leader Allow.',
        'structure' => 'BlockLeaderAllownace',
        'register' => 'BlockLeaderAllownace',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'meal' => 
      array (
        'label' => 'Meal Allowance',
        'structure' => 'MealAllownace',
        'register' => 'MealAllownace',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'education' => 
      array (
        'label' => 'Education Allowance',
        'structure' => 'EducationalAllownace',
        'register' => 'EducationalAllownace',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'health_plan' => 
      array (
        'label' => 'Health Plan',
        'structure' => 'HealthPlan',
        'register' => 'HealthPlan',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'family_plan' => 
      array (
        'label' => 'Family Plan',
        'structure' => 'FamilyPlan',
        'register' => 'FamilyPlan',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'fixed_incentive' => 
      array (
        'label' => 'Fixed Incentive',
        'structure' => 'FixedIncentive',
        'register' => 'FixedIncentive',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'other_allow1' => 
      array (
        'label' => 'Other Allowance 1',
        'structure' => 'OtherAllow1',
        'register' => 'OtherAllow1',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'other_allow2' => 
      array (
        'label' => 'Other Allowance 2',
        'structure' => 'OtherAllow2',
        'register' => 'OtherAllow2',
        'type' => 'earning',
        'prorate' => true,
        'gosi' => false,
      ),
      'other_earnings' => 
      array (
        'label' => 'Other Earnings',
        'structure' => 'OtherEarnings',
        'register' => 'OtherEarnings',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'overtime' => 
      array (
        'label' => 'Overtime',
        'structure' => NULL,
        'register' => 'OverTime',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'leave_encash' => 
      array (
        'label' => 'Leave Encashment',
        'structure' => NULL,
        'register' => 'LeaveEncash',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'arrear' => 
      array (
        'label' => 'Arrears',
        'structure' => NULL,
        'register' => 'Arrear',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'pos_adjust' => 
      array (
        'label' => 'Positive Adjustment',
        'structure' => NULL,
        'register' => 'PositiveAdjust',
        'type' => 'earning',
        'prorate' => false,
        'gosi' => false,
      ),
      'gosi' => 
      array (
        'label' => 'GOSI / SIO',
        'structure' => NULL,
        'register' => 'GOSI',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'absence' => 
      array (
        'label' => 'Absence Deduction',
        'structure' => NULL,
        'register' => 'Absences',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'unpaid_leave' => 
      array (
        'label' => 'Unpaid Leave',
        'structure' => NULL,
        'register' => 'unpaidleave',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'lates' => 
      array (
        'label' => 'Late Deduction',
        'structure' => NULL,
        'register' => 'Lates',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'undertime' => 
      array (
        'label' => 'Early-out Deduction',
        'structure' => NULL,
        'register' => 'Undertimes',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'loan' => 
      array (
        'label' => 'Staff Loan',
        'structure' => NULL,
        'register' => 'LoanAmount',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'bank_loan' => 
      array (
        'label' => 'Bank Loan',
        'structure' => NULL,
        'register' => 'bankloan',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'other_loan' => 
      array (
        'label' => 'Other Loan',
        'structure' => NULL,
        'register' => 'otherloan',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'advance' => 
      array (
        'label' => 'Salary Advance',
        'structure' => NULL,
        'register' => 'Advance',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'penalty' => 
      array (
        'label' => 'Penalty',
        'structure' => NULL,
        'register' => 'Penalty',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'phone_bills' => 
      array (
        'label' => 'Phone Bills',
        'structure' => NULL,
        'register' => 'PhoneBills',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'elec_bills' => 
      array (
        'label' => 'Electricity',
        'structure' => NULL,
        'register' => 'ElectricityBill',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'other_ded1' => 
      array (
        'label' => 'Other Deduction 1',
        'structure' => NULL,
        'register' => 'OtherDed1',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'other_ded2' => 
      array (
        'label' => 'Other Deduction 2',
        'structure' => NULL,
        'register' => 'OtherDed2',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
      'neg_adjust' => 
      array (
        'label' => 'Negative Adjustment',
        'structure' => NULL,
        'register' => 'NegativeAdjust',
        'type' => 'deduction',
        'prorate' => false,
        'gosi' => false,
      ),
    ),
    'gosi' => 
    array (
      'enabled' => true,
      'post_employer_share' => false,
      'fallback' => 
      array (
        'bahraini' => 
        array (
          'employee' => 8.0,
          'employer' => 13.0,
        ),
        'expat' => 
        array (
          'employee' => 1.0,
          'employer' => 4.0,
        ),
        'cap' => 4000,
      ),
    ),
    'indemnity' => 
    array (
      'enabled' => true,
      'days_first_tier' => 15,
      'first_tier_years' => 3,
      'days_after_tier' => 30,
      'wage_basis' => 'gross',
      'min_service_months' => 3,
      'prorate_part_years' => true,
      'provision_min_service_months' => 0,
    ),
    'leave_encash' => 
    array (
      'wage_basis' => 'gross',
      'day_divisor' => 30,
    ),
    'wps' => 
    array (
      'employer_id' => '',
      'employer_name' => 'Al Salam Specialist Hospital',
      'employer_bank' => '',
      'employer_iban' => '',
      'format' => 'csv',
      'file_prefix' => 'WPS',
    ),
    'roles' => 
    array (
      'view' => 'fa',
      'process' => 'fa',
      'approve' => 'coo',
    ),
  ),
  'security' => 
  array (
    'session_name' => 'HRMS_SID',
    'session_ttl' => 28800,
    'users_table' => 'dr_app_users',
  ),
  'punch_source' => 
  array (
    'enabled' => false,
    'driver' => 'sqlsrv',
    'host' => '127.0.0.1',
    'port' => 1433,
    'database' => 'zkteco_biotime',
    'username' => 'reader',
    'password' => 'change-me',
    'query' => 'SELECT id AS source_id, pin, checktime AS punch_time,
                              checktype AS check_type,
                              sn   AS device_name,
                              sn_name AS device_sn,
                              area_name AS area_name
                       FROM checkinout
                       WHERE id > :last_id
                       ORDER BY id ASC',
  ),
);
