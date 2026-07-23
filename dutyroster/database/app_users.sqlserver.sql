/* ================================================================
   App login table for running the Duty Roster app against the legacy
   ASSH/TestASSH database. Named dr_app_users so it can't collide with
   any existing legacy table. Seeds admin / admin123 (CHANGE IT).
   ================================================================ */
USE TestASSH;      -- change to ASSH for production
GO

IF OBJECT_ID('dbo.dr_app_users', 'U') IS NULL
CREATE TABLE dbo.dr_app_users (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    username      NVARCHAR(80)  NOT NULL,
    password_hash NVARCHAR(255) NOT NULL,
    full_name     NVARCHAR(200) NOT NULL,
    role          NVARCHAR(20)  NOT NULL DEFAULT 'employee',  -- employee|dept_head|fa|mrd|coo|admin
    employee_id   INT NULL,        -- link to Employee.ID for self-service screens
    department_id INT NULL,
    active        BIT NOT NULL DEFAULT 1,
    last_login    DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT uq_dr_app_users_username UNIQUE (username)
);
GO

-- Default admin (bcrypt hash of "admin123"). Change the password after first login.
IF NOT EXISTS (SELECT 1 FROM dbo.dr_app_users WHERE username = 'admin')
INSERT INTO dbo.dr_app_users (username, password_hash, full_name, role)
VALUES ('admin', '$2y$12$GjiaNYhJlVMJ2fFhGPMA5uRMK5lG6BEQT/jzEku7FoeEHwTbr1Md2',
        'System Administrator', 'admin');
GO

SELECT id, username, role, active FROM dbo.dr_app_users;
