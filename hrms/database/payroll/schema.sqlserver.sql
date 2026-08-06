-- =====================================================================
--  Payroll — standalone app schema (Microsoft SQL Server 2016+)
--
--  Runs against the SQL Server database that already holds the HR masters
--  and the legacy payroll register (Employee, Department, CurrentDetails,
--  CurrentMonth). It adds this app's own login table (Pay_Users) and the
--  additive Pay_* payroll tables. Nothing legacy is altered.
--
--  Installer-friendly: one statement per IF, no BEGIN/END, no GO batches,
--  statements separated by ';' so ingest/install.php can execute them.
--  Idempotent: safe to re-run.
-- =====================================================================

-- ---- Login (this app's own users; separate from the roster app) -----
IF OBJECT_ID(N'dbo.Pay_Users', N'U') IS NULL
CREATE TABLE Pay_Users (
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
    CONSTRAINT uq_pay_username UNIQUE (username)
);

-- ---- Run header: one row per payroll month --------------------------
IF OBJECT_ID(N'dbo.Pay_Run', N'U') IS NULL
CREATE TABLE Pay_Run (
    RunID          INT IDENTITY(1,1) PRIMARY KEY,
    PayrollMonth   DATETIME      NOT NULL,
    PeriodFrom     DATETIME      NOT NULL,
    PeriodTo       DATETIME      NOT NULL,
    StateID        TINYINT       NOT NULL DEFAULT 1,
    EmployeeCount  INT           NULL,
    TotalEarnings  NUMERIC(18,3) NULL,
    TotalDeduction NUMERIC(18,3) NULL,
    NetPayment     NUMERIC(18,3) NULL,
    CreatedBy      VARCHAR(20)   NULL,
    CreatedAt      DATETIME      NOT NULL DEFAULT GETDATE(),
    CalculatedBy   VARCHAR(20)   NULL,
    CalculatedAt   DATETIME      NULL,
    ApprovedBy     VARCHAR(20)   NULL,
    ApprovedAt     DATETIME      NULL,
    LockedBy       VARCHAR(20)   NULL,
    LockedAt       DATETIME      NULL,
    Remarks        VARCHAR(500)  NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_Run_Month' AND object_id=OBJECT_ID('dbo.Pay_Run'))
CREATE UNIQUE INDEX UX_Pay_Run_Month ON Pay_Run (PayrollMonth);

-- ---- Run audit ------------------------------------------------------
IF OBJECT_ID(N'dbo.Pay_RunAudit', N'U') IS NULL
CREATE TABLE Pay_RunAudit (
    AuditID    INT IDENTITY(1,1) PRIMARY KEY,
    RunID      INT          NOT NULL,
    ActionID   TINYINT      NOT NULL,
    ActionName VARCHAR(30)  NOT NULL,
    UserID     VARCHAR(20)  NOT NULL,
    ActionDate DATETIME     NOT NULL DEFAULT GETDATE(),
    EmployeeID INT          NULL,
    Remarks    VARCHAR(500) NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_RunAudit_Run' AND object_id=OBJECT_ID('dbo.Pay_RunAudit'))
CREATE INDEX IX_Pay_RunAudit_Run ON Pay_RunAudit (RunID, ActionDate);

-- ---- Per-employee statutory & banking -------------------------------
IF OBJECT_ID(N'dbo.Pay_EmployeeStatutory', N'U') IS NULL
CREATE TABLE Pay_EmployeeStatutory (
    EmployeeID   INT          NOT NULL PRIMARY KEY,
    IsBahraini   TINYINT      NOT NULL DEFAULT 0,
    CPR          VARCHAR(20)  NULL,
    GosiNumber   VARCHAR(20)  NULL,
    GosiJoinDate DATETIME     NULL,
    ExcludeGosi  TINYINT      NOT NULL DEFAULT 0,
    LmraId       VARCHAR(20)  NULL,
    BankID       INT          NULL,
    IBAN         VARCHAR(34)  NULL,
    AccountNo    VARCHAR(50)  NULL,
    PaymentMode  TINYINT      NOT NULL DEFAULT 1,
    JoiningDate  DATETIME     NULL,
    ContractType TINYINT      NULL,
    ModifiedBy   VARCHAR(20)  NULL,
    ModifiedAt   DATETIME     NULL
);

-- ---- Bank master ----------------------------------------------------
IF OBJECT_ID(N'dbo.Pay_Bank', N'U') IS NULL
CREATE TABLE Pay_Bank (
    BankID    INT IDENTITY(1,1) PRIMARY KEY,
    Code      VARCHAR(20)  NOT NULL,
    Name      VARCHAR(100) NOT NULL,
    SwiftCode VARCHAR(20)  NULL,
    Deleted   BIT          NOT NULL DEFAULT 0
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_Bank_Code' AND object_id=OBJECT_ID('dbo.Pay_Bank'))
CREATE UNIQUE INDEX UX_Pay_Bank_Code ON Pay_Bank (Code);

-- ---- Effective-dated GOSI rates -------------------------------------
IF OBJECT_ID(N'dbo.Pay_GosiRate', N'U') IS NULL
CREATE TABLE Pay_GosiRate (
    RateID        INT IDENTITY(1,1) PRIMARY KEY,
    EffectiveFrom DATETIME      NOT NULL,
    IsBahraini    TINYINT       NOT NULL,
    EmployeePct   NUMERIC(6,3)  NOT NULL,
    EmployerPct   NUMERIC(6,3)  NOT NULL,
    MinWage       NUMERIC(18,3) NULL,
    MaxWage       NUMERIC(18,3) NULL,
    Notes         VARCHAR(200)  NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_GosiRate_Eff' AND object_id=OBJECT_ID('dbo.Pay_GosiRate'))
CREATE INDEX IX_Pay_GosiRate_Eff ON Pay_GosiRate (IsBahraini, EffectiveFrom);

-- ---- Loans & installments -------------------------------------------
IF OBJECT_ID(N'dbo.Pay_Loan', N'U') IS NULL
CREATE TABLE Pay_Loan (
    LoanID            INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID        INT           NOT NULL,
    LoanType          TINYINT       NOT NULL DEFAULT 1,
    Reference         VARCHAR(40)   NULL,
    PrincipalAmount   NUMERIC(18,3) NOT NULL,
    InstallmentAmount NUMERIC(18,3) NOT NULL,
    StartMonth        DATETIME      NOT NULL,
    TotalInstallments INT           NOT NULL,
    RecoveredAmount   NUMERIC(18,3) NOT NULL DEFAULT 0,
    StateID           TINYINT       NOT NULL DEFAULT 1,
    Remarks           VARCHAR(300)  NULL,
    CreatedBy         VARCHAR(20)   NULL,
    CreatedAt         DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_Loan_Emp' AND object_id=OBJECT_ID('dbo.Pay_Loan'))
CREATE INDEX IX_Pay_Loan_Emp ON Pay_Loan (EmployeeID, StateID);

IF OBJECT_ID(N'dbo.Pay_LoanInstallment', N'U') IS NULL
CREATE TABLE Pay_LoanInstallment (
    InstallmentID INT IDENTITY(1,1) PRIMARY KEY,
    LoanID        INT           NOT NULL,
    PayrollMonth  DATETIME      NOT NULL,
    Amount        NUMERIC(18,3) NOT NULL,
    RunID         INT           NULL,
    StateID       TINYINT       NOT NULL DEFAULT 1,
    PostedAt      DATETIME      NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_LoanInst' AND object_id=OBJECT_ID('dbo.Pay_LoanInstallment'))
CREATE UNIQUE INDEX UX_Pay_LoanInst ON Pay_LoanInstallment (LoanID, PayrollMonth);

-- ---- End-of-service settlement --------------------------------------
IF OBJECT_ID(N'dbo.Pay_Settlement', N'U') IS NULL
CREATE TABLE Pay_Settlement (
    SettlementID    INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID      INT           NOT NULL,
    JoiningDate     DATETIME      NOT NULL,
    LastWorkingDay  DATETIME      NOT NULL,
    ReasonID        TINYINT       NOT NULL DEFAULT 1,
    ServiceYears    NUMERIC(9,4)  NULL,
    LastBasic       NUMERIC(18,3) NULL,
    LastGross       NUMERIC(18,3) NULL,
    IndemnityDays   NUMERIC(9,2)  NULL,
    IndemnityAmount NUMERIC(18,3) NULL,
    LeaveBalanceDays NUMERIC(9,2) NULL,
    LeaveEncashment NUMERIC(18,3) NULL,
    NoticeAmount    NUMERIC(18,3) NULL,
    TicketAmount    NUMERIC(18,3) NULL,
    OtherEarnings   NUMERIC(18,3) NULL,
    LoanRecovery    NUMERIC(18,3) NULL,
    OtherDeduction  NUMERIC(18,3) NULL,
    NetSettlement   NUMERIC(18,3) NULL,
    StateID         TINYINT       NOT NULL DEFAULT 1,
    Remarks         VARCHAR(500)  NULL,
    CreatedBy       VARCHAR(20)   NULL,
    CreatedAt       DATETIME      NOT NULL DEFAULT GETDATE(),
    ApprovedBy      VARCHAR(20)   NULL,
    ApprovedAt      DATETIME      NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_Settlement_Emp' AND object_id=OBJECT_ID('dbo.Pay_Settlement'))
CREATE INDEX IX_Pay_Settlement_Emp ON Pay_Settlement (EmployeeID);

-- ---- WPS / bank file export log -------------------------------------
IF OBJECT_ID(N'dbo.Pay_WpsExport', N'U') IS NULL
CREATE TABLE Pay_WpsExport (
    ExportID     INT IDENTITY(1,1) PRIMARY KEY,
    RunID        INT           NOT NULL,
    PayrollMonth DATETIME      NOT NULL,
    FileName     VARCHAR(120)  NOT NULL,
    RecordCount  INT           NOT NULL,
    TotalAmount  NUMERIC(18,3) NOT NULL,
    FileHash     VARCHAR(64)   NULL,
    ExportedBy   VARCHAR(20)   NULL,
    ExportedAt   DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_WpsExport_Run' AND object_id=OBJECT_ID('dbo.Pay_WpsExport'))
CREATE INDEX IX_Pay_WpsExport_Run ON Pay_WpsExport (RunID);

-- ---- Salary hold / release ------------------------------------------
IF OBJECT_ID(N'dbo.Pay_SalaryHold', N'U') IS NULL
CREATE TABLE Pay_SalaryHold (
    HoldID       INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID   INT           NOT NULL,
    HoldMonth    DATETIME      NOT NULL,
    HeldNet      NUMERIC(18,3) NULL,
    StateID      TINYINT       NOT NULL DEFAULT 1,
    ReleaseMonth DATETIME      NULL,
    ReleaseRunID INT           NULL,
    HoldReason   VARCHAR(300)  NULL,
    HoldMemo     VARCHAR(50)   NULL,
    ReleaseMemo  VARCHAR(50)   NULL,
    CreatedBy    VARCHAR(20)   NULL,
    CreatedAt    DATETIME      NOT NULL DEFAULT GETDATE(),
    ReleasedBy   VARCHAR(20)   NULL,
    ReleasedAt   DATETIME      NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_SalaryHold' AND object_id=OBJECT_ID('dbo.Pay_SalaryHold'))
CREATE UNIQUE INDEX UX_Pay_SalaryHold ON Pay_SalaryHold (EmployeeID, HoldMonth);

-- ---- Standalone leave encashment ------------------------------------
IF OBJECT_ID(N'dbo.Pay_LeaveEncashment', N'U') IS NULL
CREATE TABLE Pay_LeaveEncashment (
    EncashID     INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID   INT           NOT NULL,
    RequestDate  DATETIME      NOT NULL DEFAULT GETDATE(),
    Days         NUMERIC(9,2)  NOT NULL,
    DayRate      NUMERIC(18,3) NOT NULL,
    Amount       NUMERIC(18,3) NOT NULL,
    PayrollMonth DATETIME      NOT NULL,
    StateID      TINYINT       NOT NULL DEFAULT 1,
    PaidRunID    INT           NULL,
    Reason       VARCHAR(300)  NULL,
    CreatedBy    VARCHAR(20)   NULL,
    CreatedAt    DATETIME      NOT NULL DEFAULT GETDATE(),
    ApprovedBy   VARCHAR(20)   NULL,
    ApprovedAt   DATETIME      NULL
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_LeaveEncash_Month' AND object_id=OBJECT_ID('dbo.Pay_LeaveEncashment'))
CREATE INDEX IX_Pay_LeaveEncash_Month ON Pay_LeaveEncashment (PayrollMonth, StateID);

-- ---- Indemnity provision snapshots ----------------------------------
IF OBJECT_ID(N'dbo.Pay_IndemnityProvision', N'U') IS NULL
CREATE TABLE Pay_IndemnityProvision (
    ProvID       INT IDENTITY(1,1) PRIMARY KEY,
    AsOfDate     DATETIME      NOT NULL,
    EmployeeID   INT           NOT NULL,
    JoiningDate  DATETIME      NULL,
    ServiceYears NUMERIC(9,4)  NULL,
    Wage         NUMERIC(18,3) NULL,
    AccruedDays  NUMERIC(9,2)  NULL,
    Amount       NUMERIC(18,3) NULL,
    CreatedBy    VARCHAR(20)   NULL,
    CreatedAt    DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_IndemnityProv' AND object_id=OBJECT_ID('dbo.Pay_IndemnityProvision'))
CREATE UNIQUE INDEX UX_Pay_IndemnityProv ON Pay_IndemnityProvision (AsOfDate, EmployeeID);

-- ---- Leave provision snapshots --------------------------------------
IF OBJECT_ID(N'dbo.Pay_LeaveProvision', N'U') IS NULL
CREATE TABLE Pay_LeaveProvision (
    ProvID         INT IDENTITY(1,1) PRIMARY KEY,
    AsOfDate       DATETIME      NOT NULL,
    EmployeeID     INT           NOT NULL,
    Basic          NUMERIC(18,3) NULL,
    EntitledDays   NUMERIC(9,2)  NULL,
    UsedDays       NUMERIC(9,2)  NULL,
    BalanceDays    NUMERIC(9,2)  NULL,
    ForfeitedDays  NUMERIC(9,2)  NULL,
    DayRate        NUMERIC(18,3) NULL,
    Amount         NUMERIC(18,3) NULL,
    CreatedBy      VARCHAR(20)   NULL,
    CreatedAt      DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_LeaveProv' AND object_id=OBJECT_ID('dbo.Pay_LeaveProvision'))
CREATE UNIQUE INDEX UX_Pay_LeaveProv ON Pay_LeaveProvision (AsOfDate, EmployeeID);

-- ---- Employee self-service: leave requests --------------------------
IF OBJECT_ID(N'dbo.Pay_LeaveRequest', N'U') IS NULL
CREATE TABLE Pay_LeaveRequest (
    RequestID    INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID   INT           NOT NULL,
    LeaveType    VARCHAR(40)   NOT NULL,
    FromDate     DATETIME      NOT NULL,
    ToDate       DATETIME      NOT NULL,
    Days         NUMERIC(9,2)  NOT NULL,
    Reason       VARCHAR(500)  NULL,
    Contact      VARCHAR(60)   NULL,
    StateID      TINYINT       NOT NULL DEFAULT 1,   -- 1 pending 2 approved 3 rejected 9 cancelled
    DecidedBy    VARCHAR(20)   NULL,
    DecidedAt    DATETIME      NULL,
    DecisionNote VARCHAR(300)  NULL,
    CreatedAt    DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_LeaveReq' AND object_id=OBJECT_ID('dbo.Pay_LeaveRequest'))
CREATE INDEX IX_Pay_LeaveReq ON Pay_LeaveRequest (EmployeeID, StateID);

-- ---- Employee self-service: requests to HR --------------------------
IF OBJECT_ID(N'dbo.Pay_HrRequest', N'U') IS NULL
CREATE TABLE Pay_HrRequest (
    RequestID    INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID   INT           NOT NULL,
    Category     VARCHAR(40)   NOT NULL,
    Subject      VARCHAR(150)  NOT NULL,
    Message      VARCHAR(2000) NULL,
    StateID      TINYINT       NOT NULL DEFAULT 1,   -- 1 open 2 in progress 3 resolved 9 closed
    Response     VARCHAR(2000) NULL,
    HandledBy    VARCHAR(20)   NULL,
    HandledAt    DATETIME      NULL,
    CreatedAt    DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_HrReq' AND object_id=OBJECT_ID('dbo.Pay_HrRequest'))
CREATE INDEX IX_Pay_HrReq ON Pay_HrRequest (EmployeeID, StateID);

-- ---- Training / CME: per-employee yearly requirement + activities ---
IF OBJECT_ID(N'dbo.Pay_CmeRequirement', N'U') IS NULL
CREATE TABLE Pay_CmeRequirement (
    ReqID         INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID    INT           NOT NULL,
    Year          INT           NOT NULL,
    RequiredHours NUMERIC(9,2)  NOT NULL,
    SetBy         VARCHAR(20)   NULL,
    SetAt         DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_CmeReq' AND object_id=OBJECT_ID('dbo.Pay_CmeRequirement'))
CREATE UNIQUE INDEX UX_Pay_CmeReq ON Pay_CmeRequirement (EmployeeID, Year);

IF OBJECT_ID(N'dbo.Pay_CmeActivity', N'U') IS NULL
CREATE TABLE Pay_CmeActivity (
    ActivityID   INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID   INT           NOT NULL,
    Year         INT           NOT NULL,
    Title        VARCHAR(200)  NOT NULL,
    Provider     VARCHAR(120)  NULL,
    Hours        NUMERIC(9,2)  NOT NULL,
    ActivityDate DATETIME      NULL,
    StateID      TINYINT       NOT NULL DEFAULT 1,   -- 1 recorded 2 verified 9 rejected
    Certificate  VARCHAR(200)  NULL,
    CreatedAt    DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_Pay_CmeAct' AND object_id=OBJECT_ID('dbo.Pay_CmeActivity'))
CREATE INDEX IX_Pay_CmeAct ON Pay_CmeActivity (EmployeeID, Year);

-- ---- CME requirement master, by staff category + year ---------------
IF OBJECT_ID(N'dbo.Pay_CmeCategoryRequirement', N'U') IS NULL
CREATE TABLE Pay_CmeCategoryRequirement (
    ReqID         INT IDENTITY(1,1) PRIMARY KEY,
    CategoryID    INT           NOT NULL,   -- = Employee.CategoryID
    CategoryName  VARCHAR(60)   NULL,       -- label for display
    Year          INT           NOT NULL,
    RequiredHours NUMERIC(9,2)  NOT NULL,
    SetBy         VARCHAR(20)   NULL,
    SetAt         DATETIME      NOT NULL DEFAULT GETDATE()
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='UX_Pay_CmeCatReq' AND object_id=OBJECT_ID('dbo.Pay_CmeCategoryRequirement'))
CREATE UNIQUE INDEX UX_Pay_CmeCatReq ON Pay_CmeCategoryRequirement (CategoryID, Year);
