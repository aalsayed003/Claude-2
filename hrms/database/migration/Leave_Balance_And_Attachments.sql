-- =====================================================================
--  Leave balances + leave-request attachments (idempotent upgrade)
--  Run once against the payroll/HR database. Safe to re-run.
--
--  Adds:
--    * Pay_LeaveBalance          per employee / leave type / year
--    * Pay_LeaveRequest columns  AttachmentName, AttachmentPath, AttachmentOcr
-- =====================================================================
SET NOCOUNT ON;

-- ---- Attachment columns on the leave-request table -------------------
IF COL_LENGTH('dbo.Pay_LeaveRequest', 'AttachmentName') IS NULL
    ALTER TABLE dbo.Pay_LeaveRequest ADD AttachmentName VARCHAR(255) NULL;
IF COL_LENGTH('dbo.Pay_LeaveRequest', 'AttachmentPath') IS NULL
    ALTER TABLE dbo.Pay_LeaveRequest ADD AttachmentPath VARCHAR(400) NULL;
IF COL_LENGTH('dbo.Pay_LeaveRequest', 'AttachmentOcr') IS NULL
    ALTER TABLE dbo.Pay_LeaveRequest ADD AttachmentOcr VARCHAR(MAX) NULL;
GO

-- ---- Leave balance table --------------------------------------------
IF OBJECT_ID(N'dbo.Pay_LeaveBalance', N'U') IS NULL
CREATE TABLE dbo.Pay_LeaveBalance (
    BalanceID    INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID   INT           NOT NULL,
    LeaveType    VARCHAR(40)   NOT NULL,
    LeaveYear    INT           NOT NULL,
    Entitlement  NUMERIC(9,2)  NOT NULL DEFAULT 0,
    Used         NUMERIC(9,2)  NOT NULL DEFAULT 0,
    Pending      NUMERIC(9,2)  NOT NULL DEFAULT 0,
    UpdatedAt    DATETIME      NOT NULL DEFAULT GETDATE()
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'UX_Pay_LeaveBal' AND object_id = OBJECT_ID('dbo.Pay_LeaveBalance'))
    CREATE UNIQUE INDEX UX_Pay_LeaveBal ON dbo.Pay_LeaveBalance (EmployeeID, LeaveType, LeaveYear);
GO
