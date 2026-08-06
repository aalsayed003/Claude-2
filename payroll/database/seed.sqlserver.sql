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
    INSERT INTO Pay_GosiRate (EffectiveFrom, IsBahraini, EmployeePct, EmployerPct, MinWage, MaxWage, Notes)
    VALUES
      ('2024-05-01', 1, 8.000, 13.000, 0, 4000, 'PROVISIONAL Bahraini - confirm with SIO'),
      ('2025-01-01', 1, 8.000, 14.000, 0, 4000, 'PROVISIONAL Bahraini 2024 reform step - confirm with SIO'),
      ('2026-01-01', 1, 8.000, 15.000, 0, 4000, 'PROVISIONAL Bahraini - confirm with SIO'),
      ('2024-05-01', 0, 1.000, 4.000, 0, 4000, 'PROVISIONAL Expat - confirm with SIO');

-- Common Bahrain banks for the WPS file (extend as needed).
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='BBK')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('BBK','Bank of Bahrain and Kuwait','BBKUBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='NBB')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('NBB','National Bank of Bahrain','NBOBBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='AUB')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('AUB','Ahli United Bank','AUBBBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='BISB') INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('BISB','Bahrain Islamic Bank','BIBBBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='SCB')  INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('SCB','Standard Chartered Bahrain','SCBLBHBM');
IF NOT EXISTS (SELECT 1 FROM Pay_Bank WHERE Code='HSBC') INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('HSBC','HSBC Bahrain','BBMEBHBX');
