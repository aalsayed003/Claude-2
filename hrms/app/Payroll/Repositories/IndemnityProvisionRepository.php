<?php
namespace App\Payroll\Repositories;

use App\Core\Database;
use App\Core\Config;
use App\Payroll\Services\IndemnityCalculator;

/**
 * End-of-service indemnity PROVISION — the accrued liability for every active
 * employee as of a reporting date, not the payout for one leaver.
 *
 * For each employee it takes the service length to the reporting date and the
 * wage in force, and accrues indemnity on the same rules as the settlement
 * (IndemnityCalculator), so the provision reconciles to what would actually be
 * paid on that day.
 *
 * A run can be SNAPSHOT into Pay_IndemnityProvision so month-end balances are
 * kept, and the period charge (movement) is this month's balance minus the
 * prior snapshot's, employee by employee.
 */
class IndemnityProvisionRepository
{
    public function __construct(private Database $db) {}

    /**
     * Compute the provision as of a date.
     *
     * @return array{
     *   rows: array, totals: array{count:int, amount:float, days:float},
     *   issues: array, as_of: string, wage_basis: string
     * }
     */
    public function compute(string $asOf, ?int $departmentId = null): array
    {
        $asOf   = date('Y-m-d', strtotime($asOf));
        $month  = date('Y-m-01', strtotime($asOf));
        $basis  = Config::get('payroll.indemnity.wage_basis', 'gross');
        $minMon = (float) Config::get('payroll.indemnity.provision_min_service_months', 0);

        $structures = (new SalaryStructureRepository($this->db))->effectiveForAll($month);
        $statutory  = (new StatutoryRepository($this->db))->all();

        $emp = lt('employee');
        $dep = lt('department');
        $params = [':asof' => $asOf . ' 23:59:59'];
        $where = '';
        if ($departmentId) {
            $where = ' AND e.DepartmentId = :d';
            $params[':d'] = $departmentId;
        }
        $employees = $this->db->all(
            "SELECT e.ID AS id, e.EmpCode AS emp_code, e.Name AS full_name,
                    e.DepartmentId AS department_id, d.Name AS dept_name,
                    e.StartDateTime AS joined_at, e.EndDateTime AS left_at
               FROM {$emp} e
               LEFT JOIN {$dep} d ON d.Id = e.DepartmentId
              WHERE e.Deleted = 0
                AND (e.EndDateTime IS NULL OR e.EndDateTime > :asof)
                {$where}
              ORDER BY d.Name, e.Name",
            $params
        );

        $rows = [];
        $issues = [];
        $totalAmt = 0.0;
        $totalDays = 0.0;

        foreach ($employees as $e) {
            $id = (int) $e['id'];
            $stat = $statutory[$id] ?? null;
            $structure = $structures[$id] ?? null;

            $joining = ($stat['JoiningDate'] ?? null) ?: ($e['joined_at'] ?? null);
            $joining = $joining ? date('Y-m-d', strtotime((string) $joining)) : null;

            if (!$joining) {
                $issues[] = $e + ['problem' => 'no joining date'];
                continue;
            }
            if ($joining > $asOf) {
                continue;   // not yet joined on the reporting date
            }
            if (!$structure) {
                $issues[] = $e + ['problem' => 'no salary structure — accrues 0'];
            }

            $wage = $structure
                ? ($basis === 'basic'
                    ? SalaryStructureRepository::basicOf($structure)
                    : SalaryStructureRepository::grossOf($structure))
                : 0.0;

            $years = IndemnityCalculator::serviceYears($joining, $asOf);
            $months = $years * 12;

            $accrued = ($months >= $minMon)
                ? IndemnityCalculator::accrue($wage, $years)
                : ['days' => 0.0, 'day_rate' => 0.0, 'amount' => 0.0];

            $rows[] = [
                'id'          => $id,
                'emp_code'    => $e['emp_code'],
                'full_name'   => $e['full_name'],
                'dept_name'   => $e['dept_name'],
                'joining'     => $joining,
                'service'     => IndemnityCalculator::serviceText($joining, $asOf),
                'years'       => round($years, 2),
                'wage'        => money_round($wage),
                'days'        => $accrued['days'],
                'amount'      => $accrued['amount'],
            ];
            $totalAmt  += $accrued['amount'];
            $totalDays += $accrued['days'];
        }

        return [
            'rows'       => $rows,
            'issues'     => $issues,
            'as_of'      => $asOf,
            'wage_basis' => $basis,
            'totals'     => [
                'count'  => count($rows),
                'amount' => money_round($totalAmt),
                'days'   => round($totalDays, 2),
            ],
        ];
    }

    // ---------------------------------------------------------- snapshots --

    /** Save a computed provision as the snapshot for its reporting date. */
    public function saveSnapshot(array $computed, string $user): int
    {
        $asOf = date('Y-m-d', strtotime($computed['as_of']));
        $this->db->run(
            "DELETE FROM " . pt('indemnity_prov') . " WHERE AsOfDate = :d",
            [':d' => $asOf]
        );
        $n = 0;
        foreach ($computed['rows'] as $r) {
            $this->db->insert(pt('indemnity_prov'), [
                'AsOfDate'     => $asOf,
                'EmployeeID'   => $r['id'],
                'JoiningDate'  => $r['joining'],
                'ServiceYears' => $r['years'],
                'Wage'         => $r['wage'],
                'AccruedDays'  => $r['days'],
                'Amount'       => $r['amount'],
                'CreatedBy'    => $user,
            ]);
            $n++;
        }
        return $n;
    }

    /** Distinct reporting dates already snapshotted, newest first. */
    public function snapshotDates(): array
    {
        try {
            return array_map(
                fn($r) => date('Y-m-d', strtotime((string) $r['AsOfDate'])),
                $this->db->all("SELECT DISTINCT AsOfDate FROM " . pt('indemnity_prov') . " ORDER BY AsOfDate DESC")
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A snapshot's per-employee amounts, keyed by EmployeeID (for movement). */
    public function snapshotMap(string $asOf): array
    {
        $map = [];
        try {
            $rows = $this->db->all(
                "SELECT EmployeeID, Amount FROM " . pt('indemnity_prov') . " WHERE AsOfDate = :d",
                [':d' => date('Y-m-d', strtotime($asOf))]
            );
            foreach ($rows as $r) {
                $map[(int) $r['EmployeeID']] = (float) $r['Amount'];
            }
        } catch (\Throwable $e) {
            // table not installed yet
        }
        return $map;
    }
}
