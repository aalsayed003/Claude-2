<?php
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\PayrollController;
use App\Controllers\SalaryStructureController;
use App\Controllers\PayslipController;
use App\Controllers\LoanController;
use App\Controllers\SettlementController;
use App\Controllers\SalaryHoldController;
use App\Controllers\LeaveEncashmentController;
use App\Controllers\IndemnityController;
use App\Controllers\EmployeeController;
use App\Controllers\LeaveProvisionController;
use App\Controllers\EssController;
use App\Controllers\HrDeskController;

$r = new Router();

// Auth
$r->get('login',  [AuthController::class, 'showLogin']);
$r->post('login', [AuthController::class, 'login']);
$r->get('logout', [AuthController::class, 'logout']);

// Dashboard
$r->get('dashboard', [DashboardController::class, 'index']);

// Payroll — runs, register, bank file
$r->get('payroll',            [PayrollController::class, 'index']);
$r->post('payroll/create',    [PayrollController::class, 'create']);
$r->get('payroll/run',        [PayrollController::class, 'show']);
$r->post('payroll/calculate', [PayrollController::class, 'calculate']);
$r->post('payroll/approve',   [PayrollController::class, 'approve']);
$r->post('payroll/lock',      [PayrollController::class, 'lock']);
$r->post('payroll/reopen',    [PayrollController::class, 'reopen']);
$r->get('payroll/register',   [PayrollController::class, 'register']);
$r->get('payroll/wps',        [PayrollController::class, 'wps']);

// Salary structures & statutory details
$r->get('payroll/structures',      [SalaryStructureController::class, 'index']);
$r->get('payroll/structure',       [SalaryStructureController::class, 'edit']);
$r->post('payroll/structure/save', [SalaryStructureController::class, 'save']);
$r->post('payroll/statutory/save', [SalaryStructureController::class, 'saveStatutory']);
$r->get('payroll/increment',       [SalaryStructureController::class, 'increment']);
$r->post('payroll/increment/save', [SalaryStructureController::class, 'applyIncrement']);

// Payslips
$r->get('payroll/payslip',       [PayslipController::class, 'index']);
$r->get('payroll/payslip/print', [PayslipController::class, 'print']);

// Loans & settlements
$r->get('payroll/loans',            [LoanController::class, 'index']);
$r->post('payroll/loans/save',      [LoanController::class, 'save']);
$r->post('payroll/loans/state',     [LoanController::class, 'setState']);
$r->get('payroll/settlement',       [SettlementController::class, 'index']);
$r->post('payroll/settlement/save', [SettlementController::class, 'save']);

// Salary hold / release
$r->get('payroll/holds',          [SalaryHoldController::class, 'index']);
$r->post('payroll/holds/hold',    [SalaryHoldController::class, 'hold']);
$r->post('payroll/holds/release', [SalaryHoldController::class, 'release']);
$r->post('payroll/holds/cancel',  [SalaryHoldController::class, 'cancel']);

// Leave encashment
$r->get('payroll/encashment',        [LeaveEncashmentController::class, 'index']);
$r->post('payroll/encashment/save',  [LeaveEncashmentController::class, 'save']);
$r->post('payroll/encashment/state', [LeaveEncashmentController::class, 'setState']);

// Indemnity provision
$r->get('payroll/indemnity',          [IndemnityController::class, 'index']);
$r->post('payroll/indemnity/snapshot',[IndemnityController::class, 'snapshot']);

// Leave provision
$r->get('payroll/leave-provision',          [LeaveProvisionController::class, 'index']);
$r->post('payroll/leave-provision/snapshot',[LeaveProvisionController::class, 'snapshot']);

// Employee master
$r->get('payroll/employees',      [EmployeeController::class, 'index']);
$r->get('payroll/employees/new',  [EmployeeController::class, 'create']);
$r->get('payroll/employees/edit', [EmployeeController::class, 'edit']);
$r->post('payroll/employees/save',[EmployeeController::class, 'save']);

// Employee self-service
$r->get('me',              [EssController::class, 'home']);
$r->get('me/payslips',     [EssController::class, 'payslips']);
$r->get('me/leave',        [EssController::class, 'leave']);
$r->post('me/leave/save',  [EssController::class, 'leaveSave']);
$r->get('me/hr',           [EssController::class, 'hr']);
$r->post('me/hr/save',     [EssController::class, 'hrSave']);
$r->get('me/cme',          [EssController::class, 'cme']);
$r->post('me/cme/save',    [EssController::class, 'cmeSave']);

// HR desk (staff side of self-service)
$r->get('hr/leave',           [HrDeskController::class, 'leave']);
$r->post('hr/leave/decide',   [HrDeskController::class, 'leaveDecide']);
$r->get('hr/requests',        [HrDeskController::class, 'requests']);
$r->post('hr/requests/respond',[HrDeskController::class, 'requestRespond']);
$r->get('hr/cme',             [HrDeskController::class, 'cme']);
$r->post('hr/cme/require',     [HrDeskController::class, 'cmeRequire']);
$r->post('hr/cme/verify',      [HrDeskController::class, 'cmeVerify']);
$r->get('hr/cme/categories',       [HrDeskController::class, 'cmeCategories']);
$r->post('hr/cme/categories/save', [HrDeskController::class, 'cmeCategorySave']);

return $r;
