<?php
namespace App\Payroll\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Per-employee leave balances (Pay_LeaveBalance), one row per
 * (employee, leave type, year).
 *
 *   Entitlement  days granted for the year
 *   Used         days consumed by APPROVED leave
 *   Pending      days held by submitted-but-undecided requests
 *   Available    = Entitlement - Used - Pending
 *
 * The lifecycle mirrors the request flow:
 *   submit   -> reserve()  (Pending += days, after checking Available)
 *   approve  -> commit()   (Pending -= days, Used += days)
 *   reject   -> release()  (Pending -= days)
 *
 * Only the leave types listed in payroll.leave_balance_types draw from a
 * balance; everything else (e.g. Unpaid) is unlimited and returns null here.
 */
class LeaveBalanceRepository
{
    public function __construct(private Database $db) {}

    /** Leave types that are tracked against a balance. */
    public function tracked(string $type): bool
    {
        $types = (array) Config::get('payroll.leave_balance_types', ['Annual']);
        return in_array($type, $types, true);
    }

    /** Default yearly entitlement for a type (0 if not configured). */
    private function entitlementFor(string $type): float
    {
        $map = (array) Config::get('payroll.leave_entitlement', []);
        return (float) ($map[$type] ?? 0);
    }

    /** Row for one employee/type/year, creating it with the default grant if absent. */
    public function getOrCreate(int $empId, string $type, ?int $year = null): array
    {
        $year = $year ?: (int) date('Y');
        $t = pt('leave_balance');
        $row = $this->db->one(
            "SELECT * FROM {$t} WHERE EmployeeID = :e AND LeaveType = :t AND LeaveYear = :y",
            [':e' => $empId, ':t' => $type, ':y' => $year]);
        if ($row) return $row;

        $this->db->insert($t, [
            'EmployeeID'  => $empId,
            'LeaveType'   => $type,
            'LeaveYear'   => $year,
            'Entitlement' => $this->entitlementFor($type),
            'Used'        => 0,
            'Pending'     => 0,
            'UpdatedAt'   => date('Y-m-d H:i:s'),
        ]);
        return $this->db->one(
            "SELECT * FROM {$t} WHERE EmployeeID = :e AND LeaveType = :t AND LeaveYear = :y",
            [':e' => $empId, ':t' => $type, ':y' => $year]) ?? [
                'Entitlement' => $this->entitlementFor($type), 'Used' => 0, 'Pending' => 0];
    }

    public function available(int $empId, string $type, ?int $year = null): float
    {
        $r = $this->getOrCreate($empId, $type, $year);
        return round((float) $r['Entitlement'] - (float) $r['Used'] - (float) $r['Pending'], 2);
    }

    /** All tracked-type balances for an employee (for the "My Leave" summary). */
    public function summary(int $empId, ?int $year = null): array
    {
        $year = $year ?: (int) date('Y');
        $out  = [];
        foreach ((array) Config::get('payroll.leave_balance_types', ['Annual']) as $type) {
            $r = $this->getOrCreate($empId, $type, $year);
            $ent = (float) $r['Entitlement']; $used = (float) $r['Used']; $pend = (float) $r['Pending'];
            $out[] = [
                'type'        => $type,
                'entitlement' => $ent,
                'used'        => $used,
                'pending'     => $pend,
                'available'   => round($ent - $used - $pend, 2),
            ];
        }
        return $out;
    }

    /** Hold `days` as pending. Returns false if there is not enough available. */
    public function reserve(int $empId, string $type, float $days, ?int $year = null): bool
    {
        if (!$this->tracked($type)) return true;             // untracked types are unlimited
        $year = $year ?: (int) date('Y');
        $this->getOrCreate($empId, $type, $year);
        if ($this->available($empId, $type, $year) + 1e-6 < $days) return false;
        $this->bump($empId, $type, $year, 0, $days);
        return true;
    }

    /** Move `days` from pending to used (on approval). */
    public function commit(int $empId, string $type, float $days, ?int $year = null): void
    {
        if (!$this->tracked($type)) return;
        $this->bump($empId, $type, $year ?: (int) date('Y'), $days, -$days);
    }

    /** Give back `days` of pending (on rejection / cancellation of a pending request). */
    public function release(int $empId, string $type, float $days, ?int $year = null): void
    {
        if (!$this->tracked($type)) return;
        $this->bump($empId, $type, $year ?: (int) date('Y'), 0, -$days);
    }

    /** Apply deltas to Used and Pending, clamped at zero, on an existing row. */
    private function bump(int $empId, string $type, int $year, float $dUsed, float $dPending): void
    {
        $r = $this->getOrCreate($empId, $type, $year);
        $used = max(0, (float) $r['Used'] + $dUsed);
        $pend = max(0, (float) $r['Pending'] + $dPending);
        $this->db->update(pt('leave_balance'),
            ['Used' => $used, 'Pending' => $pend, 'UpdatedAt' => date('Y-m-d H:i:s')],
            'EmployeeID = :e AND LeaveType = :t AND LeaveYear = :y',
            [':e' => $empId, ':t' => $type, ':y' => $year]);
    }

    /** HR override of the yearly grant. */
    public function setEntitlement(int $empId, string $type, int $year, float $days): void
    {
        $this->getOrCreate($empId, $type, $year);
        $this->db->update(pt('leave_balance'),
            ['Entitlement' => $days, 'UpdatedAt' => date('Y-m-d H:i:s')],
            'EmployeeID = :e AND LeaveType = :t AND LeaveYear = :y',
            [':e' => $empId, ':t' => $type, ':y' => $year]);
    }
}
