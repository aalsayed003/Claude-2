<?php
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ShiftController;
use App\Controllers\EmployeeController;
use App\Controllers\EmployeeImportController;
use App\Controllers\DepartmentController;
use App\Controllers\RosterController;
use App\Controllers\AttendanceController;
use App\Controllers\ApprovalController;
use App\Controllers\CorrectionController;
use App\Controllers\ScheduleChangeController;
use App\Controllers\OvertimeController;

$r = new Router();

// Auth
$r->get('login',  [AuthController::class, 'showLogin']);
$r->post('login', [AuthController::class, 'login']);
$r->get('logout', [AuthController::class, 'logout']);

// Dashboard
$r->get('dashboard', [DashboardController::class, 'index']);

// Duty Roster Master (shifts)
$r->get('shifts',        [ShiftController::class, 'index']);
$r->get('shifts/new',    [ShiftController::class, 'create']);
$r->post('shifts/save',  [ShiftController::class, 'save']);
$r->get('shifts/edit',   [ShiftController::class, 'edit']);
$r->post('shifts/delete',[ShiftController::class, 'delete']);

// Employees & org
$r->get('employees',        [EmployeeController::class, 'index']);
$r->get('employees/new',    [EmployeeController::class, 'create']);
$r->post('employees/save',  [EmployeeController::class, 'save']);
$r->get('employees/edit',   [EmployeeController::class, 'edit']);
$r->get('employees/import',          [EmployeeImportController::class, 'form']);
$r->post('employees/import/preview', [EmployeeImportController::class, 'preview']);
$r->post('employees/import/commit',  [EmployeeImportController::class, 'commit']);
$r->get('employees/import/template', [EmployeeImportController::class, 'template']);
$r->get('departments',      [DepartmentController::class, 'index']);
$r->post('departments/save',[DepartmentController::class, 'save']);
$r->post('sections/save',   [DepartmentController::class, 'saveSection']);

// Duty Roster (allot shift)
$r->get('roster',            [RosterController::class, 'index']);
$r->get('roster/allot',      [RosterController::class, 'allot']);
$r->post('roster/save',      [RosterController::class, 'save']);
$r->get('roster/submit',     [RosterController::class, 'submitForm']);
$r->post('roster/submit',    [RosterController::class, 'submit']);

// Approvals
$r->get('approvals',         [ApprovalController::class, 'index']);
$r->post('approvals/act',    [ApprovalController::class, 'act']);

// Attendance
$r->get('attendance',        [AttendanceController::class, 'index']);
$r->post('attendance/rebuild',[AttendanceController::class, 'rebuild']);

// Requests
$r->get('correction',        [CorrectionController::class, 'index']);
$r->post('correction/save',  [CorrectionController::class, 'save']);
$r->get('schedule-change',   [ScheduleChangeController::class, 'index']);
$r->post('schedule-change/save',[ScheduleChangeController::class, 'save']);
$r->get('overtime',          [OvertimeController::class, 'index']);
$r->post('overtime/save',    [OvertimeController::class, 'save']);

return $r;
