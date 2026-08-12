-- =====================================================================
--  Seed data for the standalone Payroll app (SQL Server).
--  DEFAULT LOGIN:  admin / admin123   <-- change immediately.
--  Idempotent: safe to re-run (guarded by NOT EXISTS).
-- =====================================================================

-- Default admin (bcrypt hash of "admin123")
IF NOT EXISTS (SELECT 1 FROM Pay_Users WHERE username='admin')
    INSERT INTO Pay_Users (username, password_hash, full_name, role)
    VALUES ('admin', '$2y$12$GjiaNYhJlVMJ2fFhGPMA5uRMK5lG6BEQT/jzEku7FoeEHwTbr1Md2', 'System Administrator', 'admin');

-- GOSI / SIO rates — PROVISIONAL. Confirm against the current SIO circular
-- before the first live run; correct these rows, no code change needed.
IF NOT EXISTS (SELECT 1 FROM Pay_GosiRate)
    INSERT INTO Pay_GosiRate
      (EffectiveFrom, Category, IsBahraini, SocialEmpPct, UnempEmpPct, SocialErPct, UnempErPct, EmployeePct, EmployerPct, MinWage, MaxWage, Notes)
    VALUES
      -- Bahraini: employee 7% SI + 1% unemployment (=8%); employer 17% SI + 1% unemployment (=18%).
      ('2024-05-01', 'bahraini', 1, 7.000, 1.000, 17.000, 1.000, 8.000, 18.000, 0, 4000, 'Bahraini employee 8% (7 SI + 1 unemployment), employer 18%.'),
      -- Bahraini retiree / pensioner: employee 1% SI, no employer share.
      ('2024-05-01', 'retiree', 1, 1.000, 0.000, 0.000, 0.000, 1.000, 0.000, 0, 4000, 'Bahraini retiree employee 1% SI, no employer share.'),
      -- Expat: employee 1% SI, employer 3%.
      ('2024-05-01', 'expat', 0, 1.000, 0.000, 3.000, 0.000, 1.000, 3.000, 0, 4000, 'Expat employee 1% SI, employer 3%.');

-- Common Bahrain banks for the WPS file (extend as needed).
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='BBK')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('BBK','Bank of Bahrain and Kuwait','BBKUBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='NBB')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('NBB','National Bank of Bahrain','NBOBBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='AUB')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('AUB','Ahli United Bank','AUBBBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='BISB') INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('BISB','Bahrain Islamic Bank','BIBBBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='SCB')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('SCB','Standard Chartered Bahrain','SCBLBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='HSBC') INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('HSBC','HSBC Bahrain','BBMEBHBX');
