<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Annual-leave provision — the accrued liability for untaken leave across all
 * active staff, valued on the latest basic salary.
 *
 * Per employee:
 *   entitled   = annual entitlement, pro-rated to service in the year
 *   used       = leave taken this year
 *   balance    = untaken leave owed (from the HR leavebalance when available,
 *                else entitled − used)
 *   forfeited  = balance above the carry-over cap (not a liability)
 *   provision  = (balance − forfeited) × latest basic ÷ divisor
 *
 * The HR balance/used come from the shared `leavebalance` / `LeaveApplication`
 * tables when reachable; otherwise it falls back to the entitlement, so the
 * screen still works before the Duty Roster / HR link is live.
 */
class LeaveProvisionRepository
{
    public function __construct(private Database $db) {}

    public function compute(string $asOf, ?int $departmentId = null): array
    {
        $asOf   = date('Y-m-d', strtotime($asOf));
        $month  = date('Y-m-01', strtotime($asOf));
        $year   = (int) date('Y', strtotime($asOf));
        $cfg    = (array) Config::get('payroll.leave_provision', []);
        $annual = (float) ($cfg['annual_entitlement_days'] ?? 30);
        $basisB = ($cfg['wage_basis'] ?? 'basic') !== 'gross';
        $divisor= (float) ($cfg['day_divisor'] ?? 30);
        $cap    = (float) ($cfg['carryover_cap_days'] ?? 60);
        $useHr  = !empty($cfg['use_hr_tables']);

        $structures = (new SalaryStructureRepository($this->db))->effectiveForAll($month);
        $statutory  = (new StatutoryRepository($this->db))->all();
        $balances   = $useHr ? $this->hrBalances() : [];

        $emp = lt('employee');
        $dep = lt('department');
        $params = [':asof' => $asOf . ' 23:59:59'];
        $where = '';
        if ($departmentId) { $where = ' AND e.DepartmentId = :d'; $params[':d'] = $departmentId; }

        $employees = $this->db->all(
            "SELECT e.ID AS id, e.EmpCode AS emp_code, e.Name AS full_name,
                    e.DepartmentId AS department_id, d.Name AS dept_name, e.StartDateTime AS joined_at
               FROM {$emp} e LEFT JOIN {$dep} d ON d.Id = e.DepartmentId
              WHERE e.Deleted = 0 AND (e.EndDateTime IS NULL OR e.EndDateTime > :asof) {$where}
              ORDER BY d.Name, e.Name",
            $params
        );

        $rows = []; $issues = [];
        $tAmt = $tBal = 0.0;

        foreach ($employees as $e) {
            $id = (int) $e['id'];
            $structure = $structures[$id] ?? null;
            $basic = $structure
                ? ($basisB ? SalaryStructureRepository::basicOf($structure)
                           : SalaryStructureRepository::grossOf($structure))
                : 0.0;
            if (!$structure) {
                $issues[] = $e + ['problem' => 'no salary structure — accrues 0'];
            }

            $joining = ($statutory[$id]['JoiningDate'] ?? null) ?: ($e['joined_at'] ?? null);
            $entitled = $this->entitledThisYear($annual, $joining, $year, $asOf, $cfg);

            $hr = $balances[$id] ?? null;
            $used    = $hr['used']    ?? 0.0;
            $balance = $hr['balance'] ?? max(0.0, $entitled - $used);

            $forfeited = max(0.0, $balance - $cap);
            $provisionable = $balance - $forfeited;
            $dayRate = $divisor > 0 ? $basic / $divisor : 0.0;
            $amount  = money_round($provisionable * $dayRate);

            $rows[] = [
                'id'        => $id,
                'emp_code'  => $e['emp_code'],
                'full_name' => $e['full_name'],
                'dept_name' => $e['dept_name'],
                'basic'     => money_round($basic),
                'entitled'  => round($entitled, 1),
                'used'      => round($used, 1),
                'balance'   => round($balance, 1),
                'forfeited' => round($forfeited, 1),
                'day_rate'  => money_round($dayRate),
                'amount'    => $amount,
            ];
            $tAmt += $amount; $tBal += $balance;
        }

        return [
            'rows'   => $rows,
            'issues' => $issues,
            'as_of'  => $asOf,
            'basis'  => $basisB ? 'Basic' : 'Gross',
            'totals' => ['count' => count($rows), 'amount' => money_round($tAmt), 'balance' => round($tBal, 1)],
        ];
    }

    /** Entitlement earned so far this year, pro-rated to service when configured. */
    private function entitledThisYear(float $annual, $joining, int $year, string $asOf, array $cfg): float
    {
        $yearStart = "$year-01-01";
        $start = $yearStart;
        if (!empty($cfg['accrual_from_join']) && $joining) {
            $j = date('Y-m-d', strtotime((string) $joining));
            if ($j > $start) { $start = $j; }
        }
        if ($start > $asOf) { return 0.0; }
        $daysInYear = (int) date('L', strtotime($yearStart)) ? 366 : 365;
        $served = (int) round((strtotime($asOf) - strtotime($start)) / 86400) + 1;
        return min($annual, $annual * $served / $daysInYear);
    }

    /** Balance + used per employee from the HR leavebalance table (best effort). */
    private function hrBalances(): array
    {
        $map = [];
        $ids = array_map('intval', (array) Config::get('payroll.leave_provision.annual_leave_ids', []));
        $filter = $ids ? ' AND leaveid IN (' . implode(',', $ids) . ')' : '';
        try {
            $rows = $this->db->all(
                "SELECT EmpID, SUM(Balance) AS bal, SUM(AvailedLeaves) AS used
                   FROM " . lt('leave_bal') . "
                  WHERE (deleted = 0 OR deleted IS NULL) {$filter}
                  GROUP BY EmpID");
            foreach ($rows as $r) {
                $map[(int) $r['EmpID']] = [
                    'balance' => (float) ($r['bal'] ?? 0),
                    'used'    => (float) ($r['used'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            // leavebalance not reachable yet — caller falls back to entitlement
        }
        return $map;
    }

    // ---------------------------------------------------------- snapshots --

    public function saveSnapshot(array $c, string $user): int
    {
        $asOf = date('Y-m-d', strtotime($c['as_of']));
        $this->db->run("DELETE FROM " . pt('leave_prov') . " WHERE AsOfDate = :d", [':d' => $asOf]);
        $n = 0;
        foreach ($c['rows'] as $r) {
            $this->db->insert(pt('leave_prov'), [
                'AsOfDate'      => $asOf,
                'EmployeeID'    => $r['id'],
                'Basic'         => $r['basic'],
                'EntitledDays'  => $r['entitled'],
                'UsedDays'      => $r['used'],
                'BalanceDays'   => $r['balance'],
                'ForfeitedDays' => $r['forfeited'],
                'DayRate'       => $r['day_rate'],
                'Amount'        => $r['amount'],
                'CreatedBy'     => $user,
            ]);
            $n++;
        }
        return $n;
    }

    public function snapshotDates(): array
    {
        try {
            return array_map(
                fn($r) => date('Y-m-d', strtotime((string) $r['AsOfDate'])),
                $this->db->all("SELECT DISTINCT AsOfDate FROM " . pt('leave_prov') . " ORDER BY AsOfDate DESC"));
        } catch (\Throwable $e) { return []; }
    }
}
