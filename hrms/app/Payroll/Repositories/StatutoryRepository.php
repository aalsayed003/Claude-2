<?php
namespace App\Payroll\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Per-employee statutory and banking facts (Pay_EmployeeStatutory) plus the
 * effective-dated GOSI rate table (Pay_GosiRate) and the bank master used by
 * the WPS export.
 *
 * None of this exists in the legacy Employee master: nationality there is a
 * NationId with no reliable Bahraini/expat flag, and there is no GOSI number,
 * IBAN or contract type. Rather than guess from NationId, payroll keeps its
 * own record and treats a missing row as "expat, no bank details" — which
 * shows up on the pre-run validation report instead of silently mispaying.
 */
class StatutoryRepository
{
    public function __construct(private Database $db) {}

    // ------------------------------------------------------ employee facts --

    public function forEmployee(int $empId): ?array
    {
        return $this->db->one(
            "SELECT * FROM " . pt('statutory') . " WHERE EmployeeID = :e",
            [':e' => $empId]
        );
    }

    /** All statutory rows keyed by EmployeeID (one query per run). */
    public function all(): array
    {
        $map = [];
        foreach ($this->db->all("SELECT * FROM " . pt('statutory')) as $r) {
            $map[(int) $r['EmployeeID']] = $r;
        }
        return $map;
    }

    public function save(int $empId, array $data, string $user): void
    {
        $allowed = ['IsBahraini', 'CPR', 'GosiNumber', 'GosiJoinDate', 'ExcludeGosi',
                    'LmraId', 'BankID', 'IBAN', 'AccountNo', 'PaymentMode',
                    'JoiningDate', 'ContractType'];
        $set = array_intersect_key($data, array_flip($allowed));
        $set['ModifiedBy'] = $user;
        $set['ModifiedAt'] = date('Y-m-d H:i:s');

        $exists = (int) $this->db->value(
            "SELECT COUNT(*) FROM " . pt('statutory') . " WHERE EmployeeID = :e",
            [':e' => $empId]
        );
        if ($exists > 0) {
            $this->db->update(pt('statutory'), $set, 'EmployeeID = :e', [':e' => $empId]);
            return;
        }
        $set['EmployeeID'] = $empId;
        $this->db->insert(pt('statutory'), $set);
    }

    /**
     * Employees who cannot be paid correctly yet — missing bank details when
     * paid by transfer, or GOSI-liable with no GOSI number. Drives the
     * pre-run validation panel.
     */
    public function exceptions(array $employees): array
    {
        $stat = $this->all();
        $out  = [];
        foreach ($employees as $e) {
            $s = $stat[(int) $e['id']] ?? null;
            $problems = [];
            if (!$s) {
                $problems[] = 'no statutory record (treated as expat, unpaid by transfer)';
            } else {
                if ((int) ($s['PaymentMode'] ?? 1) === 1 && empty($s['IBAN']) && empty($s['AccountNo'])) {
                    $problems[] = 'no IBAN / account number';
                }
                if (!(int) ($s['ExcludeGosi'] ?? 0) && empty($s['GosiNumber'])) {
                    $problems[] = 'no GOSI number';
                }
            }
            if ($problems) {
                $out[] = $e + ['problems' => $problems];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ GOSI rate --

    /**
     * The rate in force on a date for a class of employee.
     * Falls back to payroll.gosi.fallback when the rate table has no row, so a
     * fresh install still calculates rather than silently zeroing GOSI.
     */
    public function gosiRate(bool $isBahraini, string $onDate): array
    {
        $row = null;
        try {
            $row = $this->db->one(
                $this->db->limit(
                    "SELECT * FROM " . pt('gosi_rate') . "
                      WHERE IsBahraini = :b AND EffectiveFrom <= :d
                      ORDER BY EffectiveFrom DESC", 1),
                [':b' => $isBahraini ? 1 : 0, ':d' => $onDate]
            );
        } catch (\Throwable $e) {
            $row = null;   // rate table not installed yet
        }

        if ($row) {
            return [
                'employee_pct' => (float) $row['EmployeePct'],
                'employer_pct' => (float) $row['EmployerPct'],
                'min_wage'     => $row['MinWage'] !== null ? (float) $row['MinWage'] : null,
                'max_wage'     => $row['MaxWage'] !== null ? (float) $row['MaxWage'] : null,
                'source'       => 'Pay_GosiRate #' . $row['RateID'],
            ];
        }

        $fb  = (array) Config::get('payroll.gosi.fallback', []);
        $key = $isBahraini ? 'bahraini' : 'expat';
        return [
            'employee_pct' => (float) ($fb[$key]['employee'] ?? 0),
            'employer_pct' => (float) ($fb[$key]['employer'] ?? 0),
            'min_wage'     => null,
            'max_wage'     => isset($fb['cap']) ? (float) $fb['cap'] : null,
            'source'       => 'config fallback',
        ];
    }

    public function gosiRates(): array
    {
        try {
            return $this->db->all(
                "SELECT * FROM " . pt('gosi_rate') . " ORDER BY IsBahraini DESC, EffectiveFrom DESC");
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------- banks ----

    public function banks(): array
    {
        try {
            return $this->db->all(
                "SELECT * FROM " . pt('bank') . " WHERE Deleted = 0 ORDER BY Name");
        } catch (\Throwable $e) {
            return [];
        }
    }
}
