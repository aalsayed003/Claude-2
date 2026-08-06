<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Standalone leave encashment (legacy "Leave System -> Leave Encashment"):
 * paying out unused annual leave while the employee is still serving, into a
 * chosen payroll month. The end-of-service kind stays in the settlement.
 *
 * The engine adds every APPROVED request for a payroll month to that month's
 * leave_encash component, and marks the request paid when the month is locked.
 */
class LeaveEncashmentRepository
{
    public const PENDING = 1, APPROVED = 2, PAID = 3, CANCELLED = 9;

    public const STATE_LABELS = [
        self::PENDING => 'Pending', self::APPROVED => 'Approved',
        self::PAID => 'Paid', self::CANCELLED => 'Cancelled',
    ];

    public function __construct(private Database $db) {}

    public function forEmployee(int $empId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('leave_encash') . " WHERE EmployeeID = :e ORDER BY PayrollMonth DESC",
            [':e' => $empId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('leave_encash') . " WHERE EncashID = :id", [':id' => $id]);
    }

    /** Approved encashments to pay in a payroll month, keyed by EmployeeID => total. */
    public function approvedForMonth(string $payrollMonth): array
    {
        $map = [];
        $rows = $this->db->all(
            "SELECT EmployeeID, Amount FROM " . pt('leave_encash') . "
              WHERE StateID = :s AND PayrollMonth = :m",
            [':s' => self::APPROVED, ':m' => $this->monthStart($payrollMonth)]
        );
        foreach ($rows as $r) {
            $emp = (int) $r['EmployeeID'];
            $map[$emp] = ($map[$emp] ?? 0) + (float) $r['Amount'];
        }
        return $map;
    }

    /** Compute the day rate and amount for a number of days from the structure. */
    public function priceDays(?array $structure, float $days): array
    {
        $basis = Config::get('payroll.leave_encash.wage_basis', 'gross') === 'basic'
            ? SalaryStructureRepository::basicOf($structure)
            : SalaryStructureRepository::grossOf($structure);
        $divisor = (float) Config::get('payroll.leave_encash.day_divisor', 30);
        $rate = $divisor > 0 ? $basis / $divisor : 0.0;
        return ['day_rate' => money_round($rate), 'amount' => money_round($rate * $days)];
    }

    public function create(array $data, string $user): int
    {
        return $this->db->insert(pt('leave_encash'), [
            'EmployeeID'   => (int) $data['employee_id'],
            'Days'         => (float) $data['days'],
            'DayRate'      => money_round($data['day_rate'] ?? 0),
            'Amount'       => money_round($data['amount'] ?? 0),
            'PayrollMonth' => $this->monthStart($data['payroll_month'] . '-01'),
            'StateID'      => self::PENDING,
            'Reason'       => $data['reason'] ?? null,
            'CreatedBy'    => $user,
        ]);
    }

    public function setState(int $id, int $state, string $user): void
    {
        $set = ['StateID' => $state];
        if ($state === self::APPROVED) {
            $set['ApprovedBy'] = $user;
            $set['ApprovedAt'] = date('Y-m-d H:i:s');
        }
        $this->db->update(pt('leave_encash'), $set, 'EncashID = :id', [':id' => $id]);
    }

    /** Mark approved encashments for a month paid when it locks. */
    public function markPaidForMonth(string $payrollMonth, int $runId): int
    {
        return $this->db->run(
            "UPDATE " . pt('leave_encash') . " SET StateID = :paid, PaidRunID = :r
              WHERE StateID = :appr AND PayrollMonth = :m",
            [':paid' => self::PAID, ':r' => $runId, ':appr' => self::APPROVED,
             ':m' => $this->monthStart($payrollMonth)]
        )->rowCount();
    }

    private function monthStart(string $m): string
    {
        return date('Y-m-01', strtotime(substr($m, 0, 7) . '-01'));
    }
}
