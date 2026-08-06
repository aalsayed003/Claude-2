<?php
namespace App\Payroll\Repositories;

use App\Core\Database;

/**
 * Salary holds (legacy "Salary Hold Memo / Release Memo / Hold And Release").
 *
 * A hold names one payroll month for one employee. While it is held the month
 * still calculates onto the register — so the figure is auditable — but the
 * employee is left out of the bank file. When released, the held net is paid in
 * the release month as an arrear, and the actual withheld amount (HeldNet) is
 * stamped when the held month is locked, exactly like a loan installment.
 */
class SalaryHoldRepository
{
    public const HELD = 1, RELEASED = 2, CANCELLED = 9;

    public const STATE_LABELS = [
        self::HELD => 'Held', self::RELEASED => 'Released', self::CANCELLED => 'Cancelled',
    ];

    public function __construct(private Database $db) {}

    public function forEmployee(int $empId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('salary_hold') . " WHERE EmployeeID = :e ORDER BY HoldMonth DESC",
            [':e' => $empId]
        );
    }

    public function find(int $holdId): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('salary_hold') . " WHERE HoldID = :id", [':id' => $holdId]);
    }

    /** Active holds (StateID = Held) for a payroll month, keyed by EmployeeID. */
    public function activeForMonth(string $payrollMonth): array
    {
        $map = [];
        $rows = $this->db->all(
            "SELECT * FROM " . pt('salary_hold') . " WHERE StateID = :s AND HoldMonth = :m",
            [':s' => self::HELD, ':m' => $this->monthStart($payrollMonth)]
        );
        foreach ($rows as $r) {
            $map[(int) $r['EmployeeID']] = $r;
        }
        return $map;
    }

    /** Is this employee's salary held for the month? */
    public function isHeld(int $empId, string $payrollMonth): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM " . pt('salary_hold') . "
              WHERE EmployeeID = :e AND HoldMonth = :m AND StateID = :s",
            [':e' => $empId, ':m' => $this->monthStart($payrollMonth), ':s' => self::HELD]
        ) > 0;
    }

    /**
     * Holds RELEASED into a payroll month, keyed by EmployeeID => total held net
     * to pay now. Drives the arrear the engine adds in the release month.
     */
    public function releasedIntoMonth(string $payrollMonth): array
    {
        $map = [];
        $rows = $this->db->all(
            "SELECT EmployeeID, HeldNet FROM " . pt('salary_hold') . "
              WHERE StateID = :s AND ReleaseMonth = :m AND HeldNet IS NOT NULL",
            [':s' => self::RELEASED, ':m' => $this->monthStart($payrollMonth)]
        );
        foreach ($rows as $r) {
            $emp = (int) $r['EmployeeID'];
            $map[$emp] = ($map[$emp] ?? 0) + (float) $r['HeldNet'];
        }
        return $map;
    }

    public function create(int $empId, string $holdMonth, ?string $reason, ?string $memo, string $user): int
    {
        $month = $this->monthStart($holdMonth);
        $exists = $this->db->one(
            "SELECT HoldID, StateID FROM " . pt('salary_hold') . " WHERE EmployeeID = :e AND HoldMonth = :m",
            [':e' => $empId, ':m' => $month]
        );
        if ($exists) {
            if ((int) $exists['StateID'] === self::CANCELLED) {
                $this->db->update(pt('salary_hold'), [
                    'StateID' => self::HELD, 'HoldReason' => $reason, 'HoldMemo' => $memo,
                    'CreatedBy' => $user, 'CreatedAt' => date('Y-m-d H:i:s'),
                    'ReleaseMonth' => null, 'ReleaseRunID' => null, 'HeldNet' => null,
                ], 'HoldID = :id', [':id' => $exists['HoldID']]);
                return (int) $exists['HoldID'];
            }
            return (int) $exists['HoldID'];   // already held/released
        }
        return $this->db->insert(pt('salary_hold'), [
            'EmployeeID' => $empId,
            'HoldMonth'  => $month,
            'StateID'    => self::HELD,
            'HoldReason' => $reason,
            'HoldMemo'   => $memo,
            'CreatedBy'  => $user,
        ]);
    }

    /** Mark a hold released into a payroll month. */
    public function release(int $holdId, string $releaseMonth, ?string $memo, string $user): void
    {
        $this->db->update(pt('salary_hold'), [
            'StateID'     => self::RELEASED,
            'ReleaseMonth'=> $this->monthStart($releaseMonth),
            'ReleaseMemo' => $memo,
            'ReleasedBy'  => $user,
            'ReleasedAt'  => date('Y-m-d H:i:s'),
        ], 'HoldID = :id', [':id' => $holdId]);
    }

    public function cancel(int $holdId): void
    {
        $this->db->update(pt('salary_hold'), ['StateID' => self::CANCELLED], 'HoldID = :id', [':id' => $holdId]);
    }

    /**
     * Stamp the actual withheld net onto held rows for a month. Called when the
     * held month is locked, so a recalculated draft never fixes the figure.
     */
    public function stampHeldNet(int $empId, string $payrollMonth, float $net): void
    {
        $this->db->update(pt('salary_hold'), ['HeldNet' => money_round($net)],
            'EmployeeID = :e AND HoldMonth = :m AND StateID = :s',
            [':e' => $empId, ':m' => $this->monthStart($payrollMonth), ':s' => self::HELD]);
    }

    /** All currently-held rows, with employee names, for the management screen. */
    public function heldList(): array
    {
        $emp = lt('employee');
        return $this->db->all(
            "SELECT h.*, e.EmpCode AS emp_code, e.Name AS emp_name
               FROM " . pt('salary_hold') . " h
               JOIN {$emp} e ON e.ID = h.EmployeeID
              WHERE h.StateID = :s ORDER BY h.HoldMonth DESC, e.Name",
            [':s' => self::HELD]
        );
    }

    private function monthStart(string $m): string
    {
        return date('Y-m-01', strtotime(substr($m, 0, 7) . '-01'));
    }
}
