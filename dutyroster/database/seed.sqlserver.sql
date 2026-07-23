-- =====================================================================
--  Seed data for Duty Roster (SQL Server).
--  DEFAULT LOGIN:  admin / admin123   <-- change immediately.
--  Idempotent: safe to re-run (guarded by NOT EXISTS).
-- =====================================================================

IF NOT EXISTS (SELECT 1 FROM departments WHERE name='Information And Communication Technology')
    INSERT INTO departments (name, code) VALUES ('Information And Communication Technology','ICT');
IF NOT EXISTS (SELECT 1 FROM departments WHERE name='Nursing')
    INSERT INTO departments (name, code) VALUES ('Nursing','NUR');
IF NOT EXISTS (SELECT 1 FROM departments WHERE name='Medical Records')
    INSERT INTO departments (name, code) VALUES ('Medical Records','MRD');
IF NOT EXISTS (SELECT 1 FROM departments WHERE name='Finance')
    INSERT INTO departments (name, code) VALUES ('Finance','FIN');
IF NOT EXISTS (SELECT 1 FROM departments WHERE name='Administration')
    INSERT INTO departments (name, code) VALUES ('Administration','ADM');

IF NOT EXISTS (SELECT 1 FROM sections s JOIN departments d ON d.id=s.department_id WHERE d.code='ICT' AND s.name='General')
    INSERT INTO sections (department_id, name) SELECT id, 'General' FROM departments WHERE code='ICT';

-- Default admin (bcrypt hash of "admin123")
IF NOT EXISTS (SELECT 1 FROM users WHERE username='admin')
    INSERT INTO users (username, password_hash, full_name, role)
    VALUES ('admin', '$2y$12$GjiaNYhJlVMJ2fFhGPMA5uRMK5lG6BEQT/jzEku7FoeEHwTbr1Md2', 'System Administrator', 'admin');

-- Shift master (mirrors the legacy Shift Master screen)
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='PUBLIC HOLIDAY') INSERT INTO shifts (code,name,total_hours,is_holiday) VALUES ('PUBLIC HOLIDAY','Public Holiday',0,1);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='DAY OFF')        INSERT INTO shifts (code,name,total_hours,is_day_off) VALUES ('DAY OFF','Day Off',0,1);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='RM16') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('RM16','00:00','06:00',6.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='RM9')  INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('RM9','01:00','07:00',6.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST78') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST78','05:00','13:00',8.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='SP17') INSERT INTO shifts (code,first_in,first_out,second_in,second_out,total_hours) VALUES ('SP17','06:00','10:00','18:00','22:00',8.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST54') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST54','06:00','12:00',6.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST2')  INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST2','06:00','14:00',8.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST63') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST63','06:00','15:30',9.50);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='RM11') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('RM11','06:30','14:00',7.50);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST22') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST22','06:30','16:00',9.50);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='SP1')  INSERT INTO shifts (code,first_in,first_out,second_in,second_out,total_hours) VALUES ('SP1','07:00','11:00','15:00','19:00',8.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='BF01') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('BF01','07:00','13:00',6.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST3')  INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST3','07:00','15:00',8.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='BF10') INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('BF10','07:00','17:00',10.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='ST5')  INSERT INTO shifts (code,first_in,first_out,total_hours) VALUES ('ST5','07:00','19:00',12.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='GEN8') INSERT INTO shifts (code,name,first_in,first_out,total_hours) VALUES ('GEN8','General 8-4','08:00','16:00',8.00);
IF NOT EXISTS (SELECT 1 FROM shifts WHERE code='NGT')  INSERT INTO shifts (code,name,first_in,first_out,total_hours,crosses_midnight) VALUES ('NGT','Night 8pm-8am','20:00','08:00',12.00,1);

-- Sample employees (replace with your real HR import)
IF NOT EXISTS (SELECT 1 FROM employees WHERE emp_id='01732')
    INSERT INTO employees (emp_id, pin, full_name, department_id)
    SELECT '01732','000001732','Hawra Abdulhusain Ahmed Alasfoor', id FROM departments WHERE code='ICT';
IF NOT EXISTS (SELECT 1 FROM employees WHERE emp_id='01013')
    INSERT INTO employees (emp_id, pin, full_name, department_id)
    SELECT '01013','000001013','Joby Kaitharath George', id FROM departments WHERE code='ICT';
IF NOT EXISTS (SELECT 1 FROM employees WHERE emp_id='01138')
    INSERT INTO employees (emp_id, pin, full_name, department_id)
    SELECT '01138','000001138','Jeremy Baltazar Ignacio', id FROM departments WHERE code='ICT';
