-- =====================================================================
--  Seed data for Duty Roster.
--  Includes a default admin, sample departments, the shift set seen in
--  the legacy Shift Master, and a few employees from the sample punch feed.
--
--  DEFAULT LOGIN:  admin / admin123   <-- change this immediately.
-- =====================================================================

-- Departments (edit to match your org chart)
INSERT INTO departments (name, code) VALUES
    ('Information And Communication Technology', 'ICT'),
    ('Nursing', 'NUR'),
    ('Medical Records', 'MRD'),
    ('Finance', 'FIN'),
    ('Administration', 'ADM')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sections (department_id, name)
SELECT id, 'General' FROM departments WHERE code = 'ICT'
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Default admin user (bcrypt hash of "admin123")
INSERT INTO users (username, password_hash, full_name, role)
VALUES ('admin', '$2y$12$GjiaNYhJlVMJ2fFhGPMA5uRMK5lG6BEQT/jzEku7FoeEHwTbr1Md2', 'System Administrator', 'admin')
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

-- Shift master — mirrors the legacy Shift Master screen.
INSERT INTO shifts (code, name, first_in, first_out, second_in, second_out, total_hours, is_day_off, is_holiday, crosses_midnight) VALUES
    ('PUBLIC HOLIDAY', 'Public Holiday', NULL, NULL, NULL, NULL, 0.00, 0, 1, 0),
    ('DAY OFF',        'Day Off',        NULL, NULL, NULL, NULL, 0.00, 1, 0, 0),
    ('RM16', NULL, '00:00', '06:00', NULL, NULL, 6.00, 0, 0, 0),
    ('RM9',  NULL, '01:00', '07:00', NULL, NULL, 6.00, 0, 0, 0),
    ('ST78', NULL, '05:00', '13:00', NULL, NULL, 8.00, 0, 0, 0),
    ('SP17', NULL, '06:00', '10:00', '18:00', '22:00', 8.00, 0, 0, 0),
    ('ST54', NULL, '06:00', '12:00', NULL, NULL, 6.00, 0, 0, 0),
    ('ST2',  NULL, '06:00', '14:00', NULL, NULL, 8.00, 0, 0, 0),
    ('ST63', NULL, '06:00', '15:30', NULL, NULL, 9.50, 0, 0, 0),
    ('RM11', NULL, '06:30', '14:00', NULL, NULL, 7.50, 0, 0, 0),
    ('ST22', NULL, '06:30', '16:00', NULL, NULL, 9.50, 0, 0, 0),
    ('SP1',  NULL, '07:00', '11:00', '15:00', '19:00', 8.00, 0, 0, 0),
    ('BF01', NULL, '07:00', '13:00', NULL, NULL, 6.00, 0, 0, 0),
    ('BF05', NULL, '07:00', '14:00', NULL, NULL, 7.00, 0, 0, 0),
    ('ST3',  NULL, '07:00', '15:00', NULL, NULL, 8.00, 0, 0, 0),
    ('BF20', NULL, '07:00', '15:30', NULL, NULL, 8.50, 0, 0, 0),
    ('ST65', NULL, '07:00', '16:00', NULL, NULL, 9.00, 0, 0, 0),
    ('ST4',  NULL, '07:00', '16:30', NULL, NULL, 9.50, 0, 0, 0),
    ('BF10', NULL, '07:00', '17:00', NULL, NULL, 10.00, 0, 0, 0),
    ('ST26', NULL, '07:00', '17:30', NULL, NULL, 10.50, 0, 0, 0),
    ('ST5',  NULL, '07:00', '19:00', NULL, NULL, 12.00, 0, 0, 0),
    ('BF02', NULL, '07:30', '13:30', NULL, NULL, 6.00, 0, 0, 0),
    ('ST6',  NULL, '07:30', '15:30', NULL, NULL, 8.00, 0, 0, 0),
    ('SP2',  NULL, '08:00', '12:00', NULL, NULL, 4.00, 0, 0, 0),
    ('SP11', NULL, '08:00', '12:00', '16:00', '20:00', 8.00, 0, 0, 0),
    ('SP20', NULL, '08:00', '12:00', '17:00', '21:00', 8.00, 0, 0, 0),
    ('GEN8', 'General 8-4', '08:00', '16:00', NULL, NULL, 8.00, 0, 0, 0),
    ('NGT',  'Night 8pm-8am', '20:00', '08:00', NULL, NULL, 12.00, 0, 0, 1)
ON DUPLICATE KEY UPDATE first_in = VALUES(first_in), first_out = VALUES(first_out),
    second_in = VALUES(second_in), second_out = VALUES(second_out),
    total_hours = VALUES(total_hours), is_day_off = VALUES(is_day_off),
    is_holiday = VALUES(is_holiday), crosses_midnight = VALUES(crosses_midnight);

-- Sample employees pulled from the CheckInOut feed (PINs -> emp_id last 5 digits).
-- Replace with your real HR import.
INSERT INTO employees (emp_id, pin, full_name, department_id)
SELECT '01732', '000001732', 'Hawra Abdulhusain Ahmed Alasfoor', d.id FROM departments d WHERE d.code='ICT'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);
INSERT INTO employees (emp_id, pin, full_name, department_id)
SELECT '01013', '000001013', 'Joby Kaitharath George', d.id FROM departments d WHERE d.code='ICT'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);
INSERT INTO employees (emp_id, pin, full_name, department_id)
SELECT '01138', '000001138', 'Jeremy Baltazar Ignacio', d.id FROM departments d WHERE d.code='ICT'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);
