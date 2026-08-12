-- =====================================================================
--  GOSI: three categories (Bahraini / Retiree / Expat) + social/unemployment
--  split, effective-dated. Idempotent; safe to re-run.
--
--  Bahraini : 7% social insurance + 1% unemployment (employee)
--  Retiree  : 1% social insurance only
--  Expat    : 1% social insurance (employee)
--  Employer % are placeholders — set them in the GOSI Rates master.
-- =====================================================================
SET NOCOUNT ON;

-- ---- Pay_GosiRate: new columns -------------------------------------
IF COL_LENGTH('dbo.Pay_GosiRate','Category')     IS NULL ALTER TABLE dbo.Pay_GosiRate ADD Category     VARCHAR(20)  NULL;
IF COL_LENGTH('dbo.Pay_GosiRate','SocialEmpPct') IS NULL ALTER TABLE dbo.Pay_GosiRate ADD SocialEmpPct NUMERIC(6,3) NULL;
IF COL_LENGTH('dbo.Pay_GosiRate','UnempEmpPct')  IS NULL ALTER TABLE dbo.Pay_GosiRate ADD UnempEmpPct  NUMERIC(6,3) NULL;
IF COL_LENGTH('dbo.Pay_GosiRate','SocialErPct')  IS NULL ALTER TABLE dbo.Pay_GosiRate ADD SocialErPct  NUMERIC(6,3) NULL;
IF COL_LENGTH('dbo.Pay_GosiRate','UnempErPct')   IS NULL ALTER TABLE dbo.Pay_GosiRate ADD UnempErPct   NUMERIC(6,3) NULL;
GO

-- ---- Pay_EmployeeStatutory: retiree flag ---------------------------
IF COL_LENGTH('dbo.Pay_EmployeeStatutory','IsRetiree') IS NULL
    ALTER TABLE dbo.Pay_EmployeeStatutory ADD IsRetiree TINYINT NOT NULL DEFAULT 0;
GO

-- ---- Backfill category + split from the legacy columns -------------
UPDATE dbo.Pay_GosiRate SET Category = CASE WHEN IsBahraini = 1 THEN 'bahraini' ELSE 'expat' END WHERE Category IS NULL;
-- Bahraini total historically included 1% unemployment; split it out. Expat/retiree: all social.
UPDATE dbo.Pay_GosiRate SET UnempEmpPct = CASE WHEN Category = 'bahraini' THEN 1.000 ELSE 0.000 END WHERE UnempEmpPct IS NULL;
UPDATE dbo.Pay_GosiRate SET SocialEmpPct = ISNULL(EmployeePct,0) - ISNULL(UnempEmpPct,0) WHERE SocialEmpPct IS NULL;
UPDATE dbo.Pay_GosiRate SET SocialErPct = ISNULL(EmployerPct,0), UnempErPct = 0 WHERE SocialErPct IS NULL;
GO

-- ---- Canonical retiree rate (1% SI) if none present ----------------
IF NOT EXISTS (SELECT 1 FROM dbo.Pay_GosiRate WHERE Category = 'retiree')
    INSERT INTO dbo.Pay_GosiRate
      (EffectiveFrom, Category, IsBahraini, SocialEmpPct, UnempEmpPct, SocialErPct, UnempErPct, EmployeePct, EmployerPct, MinWage, MaxWage, Notes)
    VALUES
      ('2024-05-01', 'retiree', 1, 1.000, 0.000, 0.000, 0.000, 1.000, 0.000, 0, 4000, 'Bahraini retiree: 1% social insurance only.');
GO
