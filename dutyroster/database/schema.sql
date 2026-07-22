-- =====================================================================
--  Duty Roster — On-Premise Rebuild
--  Schema (MySQL 8 / MariaDB 10.4+)
--
--  This is the application's OWN database. The raw biometric feed
--  (ZKTeco / BioTime `checkinout` table) stays where it is; the
--  ingest job (ingest/load_punches.php) copies punches into `punches`
--  and the attendance engine derives `attendance` from them.
--
--  Attendance month runs on a cutoff cycle (default 16th -> 15th),
--  configurable in config/config.php.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
--  Organisation structure
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    code          VARCHAR(40)  NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dept_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    name          VARCHAR(150) NOT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_section_dept FOREIGN KEY (department_id) REFERENCES departments(id),
    UNIQUE KEY uq_section (department_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Employees  (emp_id is the human code shown in the UI e.g. "01732";
--  pin is the biometric enrollment number e.g. "000001732")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    emp_id        VARCHAR(20)  NOT NULL,
    pin           VARCHAR(30)  NOT NULL,
    full_name     VARCHAR(200) NOT NULL,
    department_id INT NULL,
    section_id    INT NULL,
    designation   VARCHAR(150) NULL,
    is_dept_head  TINYINT(1)   NOT NULL DEFAULT 0,
    date_joined   DATE NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_emp_dept    FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_emp_section FOREIGN KEY (section_id)    REFERENCES sections(id),
    UNIQUE KEY uq_emp_id (emp_id),
    UNIQUE KEY uq_emp_pin (pin),
    KEY idx_emp_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Application users & roles
--  role: admin | employee | dept_head | fa | mrd | coo
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(80)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(200) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'employee',
    employee_id   INT NULL,
    department_id INT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_emp  FOREIGN KEY (employee_id)   REFERENCES employees(id),
    CONSTRAINT fk_user_dept FOREIGN KEY (department_id) REFERENCES departments(id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Shift master  (Duty Roster Master screen)
--  Supports split shifts via a second in/out pair.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shifts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(30)  NOT NULL,       -- e.g. RM16, SP17, ST54
    name          VARCHAR(100) NULL,
    first_in      TIME NULL,
    first_out     TIME NULL,
    second_in     TIME NULL,
    second_out    TIME NULL,
    total_hours   DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_day_off    TINYINT(1)   NOT NULL DEFAULT 0,
    is_holiday    TINYINT(1)   NOT NULL DEFAULT 0,
    crosses_midnight TINYINT(1) NOT NULL DEFAULT 0,   -- night shift out < in
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shift_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Public holidays
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS holidays (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date  DATE NOT NULL,
    name          VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_holiday (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Duty roster — the assigned schedule, one row per employee per day
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roster (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    work_date     DATE NOT NULL,
    shift_id      INT NOT NULL,
    period_key    CHAR(7) NOT NULL,            -- cutoff period e.g. 2023-09
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_roster_emp   FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_roster_shift FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    UNIQUE KEY uq_roster (employee_id, work_date),
    KEY idx_roster_period (period_key),
    KEY idx_roster_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Roster submission + approval chain (Submit / Approve screens)
--  status: draft | submitted | head_ok | fa_ok | mrd_ok | approved | rejected
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roster_submissions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    period_key    CHAR(7) NOT NULL,
    department_id INT NOT NULL,
    section_id    INT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'submitted',
    submitted_by  INT NULL,
    submitted_at  DATETIME NULL,
    head_by       INT NULL, head_at DATETIME NULL,
    fa_by         INT NULL, fa_at   DATETIME NULL,
    mrd_by        INT NULL, mrd_at  DATETIME NULL,
    coo_by        INT NULL, coo_at  DATETIME NULL,
    rejected_by   INT NULL, rejected_at DATETIME NULL,
    comments      VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sub_dept FOREIGN KEY (department_id) REFERENCES departments(id),
    KEY idx_sub_period (period_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Punches — copied from BioTime `checkinout` by the ingest job.
--  Kept raw; the attendance engine consolidates them into `attendance`.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS punches (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_id     BIGINT NULL,                 -- checkinout.id, for idempotent load
    pin           VARCHAR(30) NOT NULL,
    employee_id   INT NULL,
    punch_time    DATETIME NOT NULL,
    check_type    VARCHAR(10) NULL,            -- always '1' on these devices; IN/OUT is inferred by punch order, not this
    device_name   VARCHAR(100) NULL,           -- sn_name (e.g. "10th Floor")
    device_sn     VARCHAR(60) NULL,
    area_name     VARCHAR(100) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_punch_source (source_id),
    KEY idx_punch_pin_time (pin, punch_time),
    KEY idx_punch_emp_time (employee_id, punch_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Attendance — one derived row per employee per day.
--  status: present | absent | day_off | holiday | leave | no_punch
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    work_date     DATE NOT NULL,
    period_key    CHAR(7) NOT NULL,
    shift_id      INT NULL,
    -- actual punches
    act_first_in  DATETIME NULL,
    act_first_out DATETIME NULL,
    act_second_in DATETIME NULL,
    act_second_out DATETIME NULL,
    punch_count   INT NOT NULL DEFAULT 0,
    -- derived
    late_in_min   INT NOT NULL DEFAULT 0,
    early_out_min INT NOT NULL DEFAULT 0,
    worked_min    INT NOT NULL DEFAULT 0,
    ot_early_min  INT NOT NULL DEFAULT 0,      -- punched in before shift start
    ot_late_min   INT NOT NULL DEFAULT 0,      -- punched out after shift end
    is_odd_punch  TINYINT(1) NOT NULL DEFAULT 0, -- odd number of punches
    status        VARCHAR(15) NOT NULL DEFAULT 'no_punch',
    computed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_emp   FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_att_shift FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    UNIQUE KEY uq_att (employee_id, work_date),
    KEY idx_att_period (period_key),
    KEY idx_att_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Attendance correction requests (Attendance Correction screen)
--  status: pending | head_ok | approved | rejected | applied
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS correction_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    status        VARCHAR(15) NOT NULL DEFAULT 'pending',
    requested_by  INT NULL,
    requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    head_by INT NULL, head_at DATETIME NULL,
    coo_by  INT NULL, coo_at  DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    CONSTRAINT fk_corr_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
    KEY idx_corr_status (status, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correction_details (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    request_id    INT NOT NULL,
    work_date     DATE NOT NULL,
    first_in      TIME NULL,
    first_out     TIME NULL,
    second_in     TIME NULL,
    second_out    TIME NULL,
    reason        VARCHAR(80) NULL,
    remarks       VARCHAR(255) NULL,
    CONSTRAINT fk_corrd_req FOREIGN KEY (request_id) REFERENCES correction_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Change of schedule requests (Change Schedule screen)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedule_change_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    work_date     DATE NOT NULL,
    old_shift_id  INT NULL,
    new_shift_id  INT NULL,
    change_against_date DATE NULL,
    claim_time    VARCHAR(40) NULL,
    status        VARCHAR(15) NOT NULL DEFAULT 'pending',
    requested_by  INT NULL,
    requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    head_by INT NULL, head_at DATETIME NULL,
    coo_by  INT NULL, coo_at  DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    CONSTRAINT fk_sc_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
    KEY idx_sc_status (status, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Overtime requests (Overtime screen)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS overtime_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    ot_date       DATE NOT NULL,
    day_type      VARCHAR(10) NOT NULL DEFAULT 'working', -- working | off
    from_time     TIME NULL,
    to_time       TIME NULL,
    total_minutes INT NOT NULL DEFAULT 0,
    ot_type       VARCHAR(10) NULL,           -- in | out
    is_split_day  TINYINT(1) NOT NULL DEFAULT 0,
    reason        VARCHAR(120) NULL,
    remark        VARCHAR(255) NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_by  INT NULL,
    requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sup_by INT NULL, sup_at DATETIME NULL,
    coo_by INT NULL, coo_at DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    CONSTRAINT fk_ot_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
    KEY idx_ot_status (status, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Leaves (used by the attendance engine to mark leave days)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leaves (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    from_date     DATE NOT NULL,
    to_date       DATE NOT NULL,
    leave_type    VARCHAR(40) NULL,
    remarks       VARCHAR(255) NULL,
    CONSTRAINT fk_leave_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
    KEY idx_leave_emp (employee_id, from_date, to_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Simple audit log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NULL,
    action        VARCHAR(80) NOT NULL,
    entity        VARCHAR(60) NULL,
    entity_id     VARCHAR(40) NULL,
    detail        VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
