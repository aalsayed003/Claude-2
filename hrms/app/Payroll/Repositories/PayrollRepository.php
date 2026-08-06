<?php
namespace App\Payroll\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Payroll runs and the monthly register.
 *
 * The register itself is the LEGACY `CurrentMonth` table (125 columns, one row
 * per employee per payroll month, keyed by Empid + CurrentMonth). It has no
 * surrogate key and no run reference, so the run header lives alongside it in
 * `Pay_Run` and the two are tied together by the payroll month.
 *
 * Column names are quoted throughout: the register has columns called
 * `LEAVE`, `total` and `CurrentMonth` — the last one shares its name with the
 * table.
 */
class PayrollRepository
{
    /** Run states. */
    public const DRAFT = 1, CALCULATED = 2, APPROVED = 3, LOCKED = 4, CANCELLED = 9;

    public const STATE_LABELS = [
        self::DRAFT      => 'Draft',
        self::CALCULATED => 'Calculated',
        self::APPROVED   => 'Approved',
        self::LOCKED     => 'Locked',
        self::CANCELLED  => 'Cancelled',
    ];

    public function __construct(private Database $db) {}

    // ---------------------------------------------------------------- runs --

    public function runs(int $limit = 24): array
    {
        $sql = "SELECT * FROM " . pt('run') . " ORDER BY PayrollMonth DESC";
        return $this->db->all($this->db->limit($sql, $limit));
    }

    public function findRun(int $runId): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('run') . " WHERE RunID = :id", [':id' => $runId]);
    }

    public function findRunByMonth(string $payrollMonth): ?array
    {
        return $this->db->one(
            "SELECT * FROM " . pt('run') . " WHERE PayrollMonth = :m",
            [':m' => $this->monthStart($payrollMonth)]
        );
    }

    /** Create the run header for a payroll month. Returns the existing one if any. */
    public function createRun(string $payrollMonth, string $from, string $to, string $user): array
    {
        $existing = $this->findRunByMonth($payrollMonth);
        if ($existing) {
            return $existing;
        }
        $this->db->run(
            "INSERT INTO " . pt('run') . " (PayrollMonth, PeriodFrom, PeriodTo, StateID, CreatedBy)
             VALUES (:m, :a, :b, :s, :u)",
            [':m' => $this->monthStart($payrollMonth), ':a' => $from, ':b' => $to,
             ':s' => self::DRAFT, ':u' => $user]
        );
        $run = $this->findRunByMonth($payrollMonth);
        $this->audit((int) $run['RunID'], 1, 'create', $user, "Period {$from} .. {$to}");
        return $run;
    }

    public function setRunState(int $runId, int $state, string $user, ?string $remarks = null): void
    {
        $set = ['StateID' => $state];
        $stamp = [self::CALCULATED => 'Calculated', self::APPROVED => 'Approved', self::LOCKED => 'Locked'];
        if (isset($stamp[$state])) {
            $set[$stamp[$state] . 'By'] = $user;
            $set[$stamp[$state] . 'At'] = date('Y-m-d H:i:s');
        }
        $this->db->update(pt('run'), $set, 'RunID = :id', [':id' => $runId]);
        $action = [self::CALCULATED => [2, 'calculate'], self::APPROVED => [3, 'approve'],
                   self::LOCKED => [4, 'lock'], self::DRAFT => [5, 'reopen'],
                   self::CANCELLED => [9, 'cancel']][$state] ?? [0, 'update'];
        $this->audit($runId, $action[0], $action[1], $user, $remarks);
    }

    public function updateRunTotals(int $runId, int $employees, float $earnings, float $deductions, float $net): void
    {
        $this->db->update(pt('run'), [
            'EmployeeCount'  => $employees,
            'TotalEarnings'  => money_round($earnings),
            'TotalDeduction' => money_round($deductions),
            'NetPayment'     => money_round($net),
        ], 'RunID = :id', [':id' => $runId]);
    }

    public function audit(int $runId, int $actionId, string $name, string $user, ?string $remarks = null, ?int $empId = null): void
    {
        $this->db->run(
            "INSERT INTO " . pt('run_audit') . " (RunID, ActionID, ActionName, UserID, EmployeeID, Remarks)
             VALUES (:r, :a, :n, :u, :e, :m)",
            [':r' => $runId, ':a' => $actionId, ':n' => $name, ':u' => $user,
             ':e' => $empId, ':m' => $remarks]
        );
    }

    public function auditTrail(int $runId, int $limit = 100): array
    {
        return $this->db->all(
            $this->db->limit(
                "SELECT * FROM " . pt('run_audit') . " WHERE RunID = :r ORDER BY ActionDate DESC", $limit),
            [':r' => $runId]
        );
    }

    /** A run is editable until it is approved. */
    public function isEditable(?array $run): bool
    {
        return $run && (int) $run['StateID'] < self::APPROVED;
    }

    // ------------------------------------------------------------ register --

    /** One employee's register row for a payroll month. */
    public function registerRow(int $empId, string $payrollMonth): ?array
    {
        $t = $this->q(pt('register'));
        return $this->db->one(
            "SELECT * FROM {$t} WHERE Empid = :e AND {$this->q('CurrentMonth')} = :m AND Deleted = 0",
            [':e' => $empId, ':m' => $this->monthStart($payrollMonth)]
        );
    }

    /** The whole register for a month, with employee/department names attached. */
    public function register(string $payrollMonth, ?int $departmentId = null): array
    {
        $t   = $this->q(pt('register'));
        $emp = lt('employee');
        $dep = lt('department');
        $params = [':m' => $this->monthStart($payrollMonth)];
        $where  = '';
        if ($departmentId) {
            $where = ' AND c.Departmentid = :d';
            $params[':d'] = $departmentId;
        }
        return $this->db->all(
            "SELECT c.*, e.EmpCode AS emp_code, e.Name AS emp_name,
                    d.Name AS dept_name
               FROM {$t} c
               JOIN {$emp} e ON e.ID = c.Empid
               LEFT JOIN {$dep} d ON d.Id = c.Departmentid
              WHERE c.{$this->q('CurrentMonth')} = :m AND c.Deleted = 0 {$where}
              ORDER BY d.Name, e.Name",
            $params
        );
    }

    /** Month totals straight from the register (used for the run summary). */
    public function registerTotals(string $payrollMonth): array
    {
        $t = $this->q(pt('register'));
        $row = $this->db->one(
            "SELECT COUNT(*) AS emp_count,
                    SUM(TotalEarnings) AS earnings,
                    SUM(TotalDeduction) AS deductions,
                    SUM(NetPayment) AS net
               FROM {$t}
              WHERE {$this->q('CurrentMonth')} = :m AND Deleted = 0",
            [':m' => $this->monthStart($payrollMonth)]
        );
        return [
            'emp_count'  => (int) ($row['emp_count'] ?? 0),
            'earnings'   => (float) ($row['earnings'] ?? 0),
            'deductions' => (float) ($row['deductions'] ?? 0),
            'net'        => (float) ($row['net'] ?? 0),
        ];
    }

    /**
     * Insert or replace one employee's register row.
     * $data is keyed by CurrentMonth column name; Empid / CurrentMonth are set here.
     */
    public function writeRegisterRow(int $empId, string $payrollMonth, array $data): void
    {
        $t     = $this->q(pt('register'));
        $month = $this->monthStart($payrollMonth);

        $data['Deleted'] = 0;
        unset($data['Empid'], $data['CurrentMonth']);

        $exists = $this->db->value(
            "SELECT COUNT(*) FROM {$t} WHERE Empid = :e AND {$this->q('CurrentMonth')} = :m",
            [':e' => $empId, ':m' => $month]
        );

        [$cols, $params] = $this->bind($data);

        if ((int) $exists > 0) {
            $set = [];
            foreach (array_keys($data) as $c) {
                $set[] = $this->q($c) . ' = :p_' . $this->param($c);
            }
            $params[':e'] = $empId;
            $params[':m'] = $month;
            $this->db->run(
                "UPDATE {$t} SET " . implode(', ', $set) .
                " WHERE Empid = :e AND {$this->q('CurrentMonth')} = :m",
                $params
            );
            return;
        }

        $names  = array_merge(['Empid', 'CurrentMonth'], $cols);
        $place  = array_merge([':e', ':m'], array_map(fn($c) => ':p_' . $this->param($c), $cols));
        $params[':e'] = $empId;
        $params[':m'] = $month;
        $this->db->run(
            "INSERT INTO {$t} (" . implode(', ', array_map([$this, 'q'], $names)) . ")
             VALUES (" . implode(', ', $place) . ")",
            $params
        );
    }

    /** Soft-delete a month's register rows (used when a draft run is recalculated). */
    public function clearRegisterMonth(string $payrollMonth): int
    {
        $t = $this->q(pt('register'));
        return $this->db->run(
            "DELETE FROM {$t} WHERE {$this->q('CurrentMonth')} = :m",
            [':m' => $this->monthStart($payrollMonth)]
        )->rowCount();
    }

    /** Payroll months that have a posted register row for one employee (self-service payslip list). */
    public function payslipMonths(int $empId, int $limit = 36): array
    {
        $t = $this->q(pt('register'));
        return $this->db->all(
            $this->db->limit(
                "SELECT {$this->q('CurrentMonth')} AS month, NetPayment, TotalEarnings, TotalDeduction
                   FROM {$t} WHERE Empid = :e AND Deleted = 0
                  ORDER BY {$this->q('CurrentMonth')} DESC", $limit),
            [':e' => $empId]);
    }

    /** Ad-hoc adjustments captured against the month (MonthlyAllowances). */
    public function monthlyAllowances(int $empId, string $payrollMonth): ?array
    {
        try {
            return $this->db->one(
                "SELECT * FROM " . $this->q(pt('monthly_allow')) . "
                  WHERE empid = :e AND currentmonth = :m AND (deleted = 0 OR deleted IS NULL)",
                [':e' => $empId, ':m' => $this->monthStart($payrollMonth)]
            );
        } catch (\Throwable $e) {
            return null;   // table absent in a stripped test DB
        }
    }

    // --------------------------------------------------------------- utils --

    /** Employees to be paid in a month: active, not deleted, with a structure row. */
    public function payableEmployees(string $payrollMonth, ?int $departmentId = null): array
    {
        $emp = lt('employee');
        $dep = lt('department');
        $str = $this->q(pt('structure'));
        $params = [':m' => $this->monthStart($payrollMonth)];
        $where = '';
        if ($departmentId) {
            $where = ' AND e.DepartmentId = :d';
            $params[':d'] = $departmentId;
        }
        return $this->db->all(
            "SELECT e.ID AS id, e.EmpCode AS emp_code, e.Name AS full_name,
                    e.DepartmentId AS department_id, d.Name AS dept_name,
                    e.DesignationId AS designation_id, e.CategoryID AS category_id,
                    e.StartDateTime AS joined_at, e.EndDateTime AS left_at
               FROM {$emp} e
               LEFT JOIN {$dep} d ON d.Id = e.DepartmentId
              WHERE e.Deleted = 0
                AND (e.EndDateTime IS NULL OR e.EndDateTime > :m)
                AND EXISTS (SELECT 1 FROM {$str} s
                             WHERE s.Empid = e.ID
                               AND s.{$this->q('CurrentMonth')} <= :m
                               AND (s.Deleted = 0 OR s.Deleted IS NULL))
                {$where}
              ORDER BY e.Name",
            $params
        );
    }

    /**
     * One employee in the shape the engine wants — the standard employee shape
     * plus the joining/leaving dates and the costing ids the register needs.
     */
    public function employee(int $empId): ?array
    {
        $emp = lt('employee');
        $dep = lt('department');
        $row = $this->db->one(
            "SELECT e.ID AS id, e.EmpCode AS emp_code, e.Name AS full_name,
                    e.DepartmentId AS department_id, d.Name AS dept_name,
                    e.DesignationId AS designation_id, e.CategoryID AS category_id,
                    e.StartDateTime AS joined_at, e.EndDateTime AS left_at
               FROM {$emp} e
               LEFT JOIN {$dep} d ON d.Id = e.DepartmentId
              WHERE e.ID = :id",
            [':id' => $empId]
        );
        return $row ?: null;
    }

    private function monthStart(string $m): string
    {
        return date('Y-m-01', strtotime(substr($m, 0, 7) . '-01'));
    }

    /** Named-parameter-safe version of a column name. */
    private function param(string $col): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $col);
    }

    /** Quote an identifier for the active driver. */
    private function q(string $ident): string
    {
        return $this->db->driver() === 'mysql' ? "`{$ident}`" : "[{$ident}]";
    }

    /** @return array{0: string[], 1: array<string,mixed>} column list + bound params */
    private function bind(array $data): array
    {
        $cols = array_keys($data);
        $params = [];
        foreach ($data as $c => $v) {
            $params[':p_' . $this->param($c)] = $v;
        }
        return [$cols, $params];
    }
}
