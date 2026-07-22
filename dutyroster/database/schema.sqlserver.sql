-- =====================================================================
--  Duty Roster — On-Premise Rebuild
--  Schema for MICROSOFT SQL SERVER (2016+)
--
--  This is the application's OWN database (create it first, e.g. duty_roster).
--  The raw biometric feed stays in zkteco_biotime.checkinout; the ingest job
--  copies punches into `punches` and derives `attendance` from them.
--
--  Attendance month runs on a cutoff cycle (default 16th -> 15th).
--  Statements are separated by ';' (no GO batches) so the PHP installer can run them.
-- =====================================================================

IF OBJECT_ID(N'dbo.departments', N'U') IS NULL
CREATE TABLE departments (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    name          NVARCHAR(150) NOT NULL,
    code          NVARCHAR(40)  NULL,
    active        BIT NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT uq_dept_name UNIQUE (name)
);

IF OBJECT_ID(N'dbo.sections', N'U') IS NULL
CREATE TABLE sections (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    department_id INT NOT NULL,
    name          NVARCHAR(150) NOT NULL,
    active        BIT NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT fk_section_dept FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT uq_section UNIQUE (department_id, name)
);

IF OBJECT_ID(N'dbo.employees', N'U') IS NULL
CREATE TABLE employees (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    emp_id        NVARCHAR(20)  NOT NULL,
    pin           NVARCHAR(30)  NOT NULL,
    full_name     NVARCHAR(200) NOT NULL,
    department_id INT NULL,
    section_id    INT NULL,
    designation   NVARCHAR(150) NULL,
    is_dept_head  BIT NOT NULL DEFAULT 0,
    date_joined   DATE NULL,
    active        BIT NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT fk_emp_dept    FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_emp_section FOREIGN KEY (section_id)    REFERENCES sections(id),
    CONSTRAINT uq_emp_id  UNIQUE (emp_id),
    CONSTRAINT uq_emp_pin UNIQUE (pin)
);

IF OBJECT_ID(N'dbo.users', N'U') IS NULL
CREATE TABLE users (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    username      NVARCHAR(80)  NOT NULL,
    password_hash NVARCHAR(255) NOT NULL,
    full_name     NVARCHAR(200) NOT NULL,
    role          NVARCHAR(20)  NOT NULL DEFAULT 'employee',
    employee_id   INT NULL,
    department_id INT NULL,
    active        BIT NOT NULL DEFAULT 1,
    last_login    DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT fk_user_emp  FOREIGN KEY (employee_id)   REFERENCES employees(id),
    CONSTRAINT fk_user_dept FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT uq_username UNIQUE (username)
);

IF OBJECT_ID(N'dbo.shifts', N'U') IS NULL
CREATE TABLE shifts (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    code          NVARCHAR(30)  NOT NULL,
    name          NVARCHAR(100) NULL,
    first_in      TIME NULL,
    first_out     TIME NULL,
    second_in     TIME NULL,
    second_out    TIME NULL,
    total_hours   DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_day_off    BIT NOT NULL DEFAULT 0,
    is_holiday    BIT NOT NULL DEFAULT 0,
    crosses_midnight BIT NOT NULL DEFAULT 0,
    active        BIT NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT uq_shift_code UNIQUE (code)
);

IF OBJECT_ID(N'dbo.holidays', N'U') IS NULL
CREATE TABLE holidays (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    holiday_date  DATE NOT NULL,
    name          NVARCHAR(150) NOT NULL,
    CONSTRAINT uq_holiday UNIQUE (holiday_date)
);

IF OBJECT_ID(N'dbo.roster', N'U') IS NULL
CREATE TABLE roster (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    employee_id   INT NOT NULL,
    work_date     DATE NOT NULL,
    shift_id      INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    updated_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT fk_roster_emp   FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_roster_shift FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    CONSTRAINT uq_roster UNIQUE (employee_id, work_date)
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='idx_roster_period' AND object_id=OBJECT_ID('dbo.roster'))
CREATE INDEX idx_roster_period ON roster(period_key);

IF OBJECT_ID(N'dbo.roster_submissions', N'U') IS NULL
CREATE TABLE roster_submissions (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    period_key    CHAR(7) NOT NULL,
    department_id INT NOT NULL,
    section_id    INT NULL,
    status        NVARCHAR(20) NOT NULL DEFAULT 'submitted',
    submitted_by  INT NULL,
    submitted_at  DATETIME NULL,
    head_by INT NULL, head_at DATETIME NULL,
    fa_by   INT NULL, fa_at   DATETIME NULL,
    mrd_by  INT NULL, mrd_at  DATETIME NULL,
    coo_by  INT NULL, coo_at  DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    comments      NVARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT fk_sub_dept FOREIGN KEY (department_id) REFERENCES departments(id)
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='idx_sub_period' AND object_id=OBJECT_ID('dbo.roster_submissions'))
CREATE INDEX idx_sub_period ON roster_submissions(period_key, status);

IF OBJECT_ID(N'dbo.punches', N'U') IS NULL
CREATE TABLE punches (
    id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    source_id     BIGINT NULL,
    pin           NVARCHAR(30) NOT NULL,
    employee_id   INT NULL,
    punch_time    DATETIME NOT NULL,
    check_type    NVARCHAR(10) NULL,   -- always '1'; IN/OUT is inferred by punch order
    device_name   NVARCHAR(100) NULL,  -- sn (floor / location)
    device_sn     NVARCHAR(60) NULL,   -- sn_name (device code, BRCP...)
    area_name     NVARCHAR(100) NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT uq_punch_source UNIQUE (source_id)
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='idx_punch_pin_time' AND object_id=OBJECT_ID('dbo.punches'))
CREATE INDEX idx_punch_pin_time ON punches(pin, punch_time);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='idx_punch_emp_time' AND object_id=OBJECT_ID('dbo.punches'))
CREATE INDEX idx_punch_emp_time ON punches(employee_id, punch_time);

IF OBJECT_ID(N'dbo.attendance', N'U') IS NULL
CREATE TABLE attendance (
    id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    employee_id   INT NOT NULL,
    work_date     DATE NOT NULL,
    period_key    CHAR(7) NOT NULL,
    shift_id      INT NULL,
    act_first_in   DATETIME NULL,
    act_first_out  DATETIME NULL,
    act_second_in  DATETIME NULL,
    act_second_out DATETIME NULL,
    punch_count   INT NOT NULL DEFAULT 0,
    late_in_min   INT NOT NULL DEFAULT 0,
    early_out_min INT NOT NULL DEFAULT 0,
    worked_min    INT NOT NULL DEFAULT 0,
    ot_early_min  INT NOT NULL DEFAULT 0,
    ot_late_min   INT NOT NULL DEFAULT 0,
    is_odd_punch  BIT NOT NULL DEFAULT 0,
    status        NVARCHAR(15) NOT NULL DEFAULT 'no_punch',
    computed_at   DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT fk_att_emp   FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_att_shift FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    CONSTRAINT uq_att UNIQUE (employee_id, work_date)
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='idx_att_period' AND object_id=OBJECT_ID('dbo.attendance'))
CREATE INDEX idx_att_period ON attendance(period_key);

IF OBJECT_ID(N'dbo.correction_requests', N'U') IS NULL
CREATE TABLE correction_requests (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    employee_id   INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    status        NVARCHAR(15) NOT NULL DEFAULT 'pending',
    requested_by  INT NULL,
    requested_at  DATETIME NOT NULL DEFAULT GETDATE(),
    head_by INT NULL, head_at DATETIME NULL,
    coo_by  INT NULL, coo_at  DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    CONSTRAINT fk_corr_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
);

IF OBJECT_ID(N'dbo.correction_details', N'U') IS NULL
CREATE TABLE correction_details (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    request_id    INT NOT NULL,
    work_date     DATE NOT NULL,
    first_in      TIME NULL,
    first_out     TIME NULL,
    second_in     TIME NULL,
    second_out    TIME NULL,
    reason        NVARCHAR(80) NULL,
    remarks       NVARCHAR(255) NULL,
    CONSTRAINT fk_corrd_req FOREIGN KEY (request_id) REFERENCES correction_requests(id) ON DELETE CASCADE
);

IF OBJECT_ID(N'dbo.schedule_change_requests', N'U') IS NULL
CREATE TABLE schedule_change_requests (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    employee_id   INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    work_date     DATE NOT NULL,
    old_shift_id  INT NULL,
    new_shift_id  INT NULL,
    change_against_date DATE NULL,
    claim_time    NVARCHAR(40) NULL,
    status        NVARCHAR(15) NOT NULL DEFAULT 'pending',
    requested_by  INT NULL,
    requested_at  DATETIME NOT NULL DEFAULT GETDATE(),
    head_by INT NULL, head_at DATETIME NULL,
    coo_by  INT NULL, coo_at  DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    CONSTRAINT fk_sc_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
);

IF OBJECT_ID(N'dbo.overtime_requests', N'U') IS NULL
CREATE TABLE overtime_requests (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    employee_id   INT NOT NULL,
    period_key    CHAR(7) NOT NULL,
    ot_date       DATE NOT NULL,
    day_type      NVARCHAR(10) NOT NULL DEFAULT 'working',
    from_time     TIME NULL,
    to_time       TIME NULL,
    total_minutes INT NOT NULL DEFAULT 0,
    ot_type       NVARCHAR(10) NULL,
    is_split_day  BIT NOT NULL DEFAULT 0,
    reason        NVARCHAR(120) NULL,
    remark        NVARCHAR(255) NULL,
    status        NVARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_by  INT NULL,
    requested_at  DATETIME NOT NULL DEFAULT GETDATE(),
    sup_by INT NULL, sup_at DATETIME NULL,
    coo_by INT NULL, coo_at DATETIME NULL,
    rejected_by INT NULL, rejected_at DATETIME NULL,
    CONSTRAINT fk_ot_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
);

IF OBJECT_ID(N'dbo.leaves', N'U') IS NULL
CREATE TABLE leaves (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    employee_id   INT NOT NULL,
    from_date     DATE NOT NULL,
    to_date       DATE NOT NULL,
    leave_type    NVARCHAR(40) NULL,
    remarks       NVARCHAR(255) NULL,
    CONSTRAINT fk_leave_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
);

IF OBJECT_ID(N'dbo.audit_log', N'U') IS NULL
CREATE TABLE audit_log (
    id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    user_id       INT NULL,
    action        NVARCHAR(80) NOT NULL,
    entity        NVARCHAR(60) NULL,
    entity_id     NVARCHAR(40) NULL,
    detail        NVARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE()
);
