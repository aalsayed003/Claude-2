<?php
/**
 * Merged HRMS routes: one shared login, the roster dashboard as the landing,
 * then the duty-roster module (root paths) and the payroll module (payroll/,
 * me/, hr/ paths). The two modules keep their own namespaces so their
 * identically-named controllers coexist.
 */
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\HomeController;

// Roster module
use App\Roster\Controllers\DashboardController as RDash;
use App\Roster\Controllers\ShiftController;
use App\Roster\Controllers\EmployeeController as REmp;
use App\Roster\Controllers\EmployeeImportController;
use App\Roster\Controllers\DepartmentController;
use App\Roster\Controllers\RosterController;
use App\Roster\Controllers\AttendanceController;
use App\Roster\Controllers\WorkspaceController;
use App\Roster\Controllers\ApprovalController;
use App\Roster\Controllers\CorrectionController;
use App\Roster\Controllers\ScheduleChangeController;
use App\Roster\Controllers\OvertimeController;

// Payroll module
use App\Payroll\Controllers\DashboardController as PDash;
use App\Payroll\Controllers\PayrollController;
use App\Payroll\Controllers\SalaryStructureController;
use App\Payroll\Controllers\PayslipController;
use App\Payroll\Controllers\LoanController;
use App\Payroll\Controllers\SettlementController;
use App\Payroll\Controllers\SalaryHoldController;
use App\Payroll\Controllers\LeaveEncashmentController;
use App\Payroll\Controllers\IndemnityController;
use App\Payroll\Controllers\EmployeeController as PEmp;
use App\Payroll\Controllers\LeaveProvisionController;
use App\Payroll\Controllers\EssController;
use App\Payroll\Controllers\HrDeskController;

$r = new Router();

// ---- Shared auth + landing --------------------------------------------------
$r->get('login',  [AuthController::class, 'showLogin']);
$r->post('login', [AuthController::class, 'login']);
$r->get('logout', [AuthController::class, 'logout']);
$r->get('dashboard',      [HomeController::class, 'index']);  // combined landing ('' also maps here)
$r->get('dashboard/list', [RDash::class, 'detail']);         // roster tile drill-down
$r->get('roster/board',   [RDash::class, 'index']);          // the roster-only dashboard

// ==== DUTY ROSTER MODULE =====================================================
$r->get('shifts',        [ShiftController::class, 'index']);
$r->get('shifts/new',    [ShiftController::class, 'create']);
$r->post('shifts/save',  [ShiftController::class, 'save']);
$r->get('shifts/edit',   [ShiftController::class, 'edit']);
$r->post('shifts/delete',[ShiftController::class, 'delete']);

$r->get('employees',        [REmp::class, 'index']);
$r->get('employees/new',    [REmp::class, 'create']);
$r->post('employees/save',  [REmp::class, 'save']);
$r->get('employees/edit',   [REmp::class, 'edit']);
$r->get('employees/import',          [EmployeeImportController::class, 'form']);
$r->post('employees/import/preview', [EmployeeImportController::class, 'preview']);
$r->post('employees/import/commit',  [EmployeeImportController::class, 'commit']);
$r->get('employees/import/template', [EmployeeImportController::class, 'template']);
$r->get('departments',      [DepartmentController::class, 'index']);
$r->post('departments/save',[DepartmentController::class, 'save']);
$r->post('sections/save',   [DepartmentController::class, 'saveSection']);

$r->get('roster',            [RosterController::class, 'index']);
$r->get('roster/allot',      [RosterController::class, 'allot']);
$r->post('roster/save',      [RosterController::class, 'save']);
$r->get('roster/submit',     [RosterController::class, 'submitForm']);
$r->post('roster/submit',    [RosterController::class, 'submit']);
$r->get('roster/template',   [RosterController::class, 'template']);
$r->post('roster/import',    [RosterController::class, 'import']);

$r->get('approvals',         [ApprovalController::class, 'index']);
$r->post('approvals/act',    [ApprovalController::class, 'act']);
$r->post('approvals/correction',      [ApprovalController::class, 'actCorrection']);
$r->post('approvals/schedule-change', [ApprovalController::class, 'actScheduleChange']);

$r->get('attendance',        [WorkspaceController::class, 'index']);
$r->post('attendance/rebuild',[AttendanceController::class, 'rebuild']);

$r->get('correction',        [CorrectionController::class, 'index']);
$r->post('correction/save',  [CorrectionController::class, 'save']);
$r->get('schedule-change',   [ScheduleChangeController::class, 'index']);
$r->post('schedule-change/save',[ScheduleChangeController::class, 'save']);
$r->get('overtime',          [OvertimeController::class, 'index']);
$r->post('overtime/save',    [OvertimeController::class, 'save']);

// ==== PAYROLL MODULE =========================================================
$r->get('payroll/home',       [PDash::class, 'index']);

$r->get('payroll',            [PayrollController::class, 'index']);
$r->post('payroll/create',    [PayrollController::class, 'create']);
$r->get('payroll/run',        [PayrollController::class, 'show']);
$r->post('payroll/calculate', [PayrollController::class, 'calculate']);
$r->post('payroll/approve',   [PayrollController::class, 'approve']);
$r->post('payroll/lock',      [PayrollController::class, 'lock']);
$r->post('payroll/reopen',    [PayrollController::class, 'reopen']);
$r->get('payroll/register',   [PayrollController::class, 'register']);
$r->get('payroll/wps',        [PayrollController::class, 'wps']);

$r->get('payroll/structures',      [SalaryStructureController::class, 'index']);
$r->get('payroll/structure',       [SalaryStructureController::class, 'edit']);
$r->post('payroll/structure/save', [SalaryStructureController::class, 'save']);
$r->post('payroll/statutory/save', [SalaryStructureController::class, 'saveStatutory']);
$r->get('payroll/increment',       [SalaryStructureController::class, 'increment']);
$r->post('payroll/increment/save', [SalaryStructureController::class, 'applyIncrement']);

$r->get('payroll/payslip',       [PayslipController::class, 'index']);
$r->get('payroll/payslip/print', [PayslipController::class, 'print']);

$r->get('payroll/loans',            [LoanController::class, 'index']);
$r->post('payroll/loans/save',      [LoanController::class, 'save']);
$r->post('payroll/loans/state',     [LoanController::class, 'setState']);
$r->get('payroll/settlement',       [SettlementController::class, 'index']);
$r->post('payroll/settlement/save', [SettlementController::class, 'save']);

$r->get('payroll/holds',          [SalaryHoldController::class, 'index']);
$r->post('payroll/holds/hold',    [SalaryHoldController::class, 'hold']);
$r->post('payroll/holds/release', [SalaryHoldController::class, 'release']);
$r->post('payroll/holds/cancel',  [SalaryHoldController::class, 'cancel']);

$r->get('payroll/encashment',        [LeaveEncashmentController::class, 'index']);
$r->post('payroll/encashment/save',  [LeaveEncashmentController::class, 'save']);
$r->post('payroll/encashment/state', [LeaveEncashmentController::class, 'setState']);

$r->get('payroll/indemnity',          [IndemnityController::class, 'index']);
$r->post('payroll/indemnity/snapshot',[IndemnityController::class, 'snapshot']);

$r->get('payroll/leave-provision',          [LeaveProvisionController::class, 'index']);
$r->post('payroll/leave-provision/snapshot',[LeaveProvisionController::class, 'snapshot']);

$r->get('payroll/employees',      [PEmp::class, 'index']);
$r->get('payroll/employees/new',  [PEmp::class, 'create']);
$r->get('payroll/employees/edit', [PEmp::class, 'edit']);
$r->post('payroll/employees/save',[PEmp::class, 'save']);

// Employee self-service
$r->get('me',              [EssController::class, 'home']);
$r->get('me/payslips',     [EssController::class, 'payslips']);
$r->get('me/leave',        [EssController::class, 'leave']);
$r->post('me/leave/save',  [EssController::class, 'leaveSave']);
$r->get('me/leave/attachment', [EssController::class, 'leaveAttachment']);
$r->get('me/hr',           [EssController::class, 'hr']);
$r->post('me/hr/save',     [EssController::class, 'hrSave']);
$r->get('me/cme',          [EssController::class, 'cme']);
$r->post('me/cme/save',    [EssController::class, 'cmeSave']);

// HR desk
$r->get('hr/leave',            [HrDeskController::class, 'leave']);
$r->post('hr/leave/decide',    [HrDeskController::class, 'leaveDecide']);
$r->get('hr/requests',         [HrDeskController::class, 'requests']);
$r->post('hr/requests/respond',[HrDeskController::class, 'requestRespond']);
$r->get('hr/cme',              [HrDeskController::class, 'cme']);
$r->post('hr/cme/require',      [HrDeskController::class, 'cmeRequire']);
$r->post('hr/cme/verify',       [HrDeskController::class, 'cmeVerify']);
$r->get('hr/cme/categories',       [HrDeskController::class, 'cmeCategories']);
$r->post('hr/cme/categories/save', [HrDeskController::class, 'cmeCategorySave']);

return $r;
