-- =====================================================================
--  GOSI employer rates (not deducted — tracked as total staff cost for
--  reconciliation against the SIO invoice).
--    Bahraini : 18% employer (17% SI + 1% unemployment)
--    Expat    : 3%  employer
--    Retiree  : none
--  Idempotent; only touches rows still carrying the old placeholder employer %.
-- =====================================================================
SET NOCOUNT ON;

UPDATE dbo.Pay_GosiRate
   SET SocialErPct = 17.000, UnempErPct = 1.000, EmployerPct = 18.000
 WHERE Category = 'bahraini' AND (EmployerPct IS NULL OR EmployerPct < 18.000);

UPDATE dbo.Pay_GosiRate
   SET SocialErPct = 3.000, UnempErPct = 0.000, EmployerPct = 3.000
 WHERE Category = 'expat' AND (EmployerPct IS NULL OR EmployerPct <> 3.000);

UPDATE dbo.Pay_GosiRate
   SET SocialErPct = 0.000, UnempErPct = 0.000, EmployerPct = 0.000
 WHERE Category = 'retiree';
GO
