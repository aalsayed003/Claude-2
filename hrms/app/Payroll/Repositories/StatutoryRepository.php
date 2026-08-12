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
        $allowed = ['IsBahraini', 'IsRetiree', 'CPR', 'GosiNumber', 'GosiJoinDate', 'ExcludeGosi',
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
    /**
     * The effective GOSI rate for a category ('bahraini'|'retiree'|'expat') at a
     * date — the row with the latest EffectiveFrom on or before it, so a future
     * rate change (e.g. Bahraini 2030-02 = 9%) never applies to older months.
     * Returns the social-insurance / unemployment split and their totals.
     */
    public function gosiRate(string $category, string $onDate): array
    {
        $row = null;
        try {
            $row = $this->db->one(
                $this->db->limit(
                    "SELECT * FROM " . pt('gosi_rate') . "
                      WHERE Category = :c AND EffectiveFrom <= :d
                      ORDER BY EffectiveFrom DESC", 1),
                [':c' => $category, ':d' => $onDate]
            );
        } catch (\Throwable $e) {
            $row = null;   // Category column not present yet -> try legacy shape below
        }
        if (!$row) {
            $row = $this->gosiRateLegacy($category, $onDate);
        }

        if ($row) {
            // Prefer the split columns; fall back to the legacy total if absent.
            $socialE = isset($row['SocialEmpPct']) && $row['SocialEmpPct'] !== null
                ? (float) $row['SocialEmpPct'] : (float) ($row['EmployeePct'] ?? 0);
            $unempE  = (float) ($row['UnempEmpPct'] ?? 0);
            $socialR = isset($row['SocialErPct']) && $row['SocialErPct'] !== null
                ? (float) $row['SocialErPct'] : (float) ($row['EmployerPct'] ?? 0);
            $unempR  = (float) ($row['UnempErPct'] ?? 0);
            return [
                'social_emp_pct' => $socialE,
                'unemp_emp_pct'  => $unempE,
                'social_er_pct'  => $socialR,
                'unemp_er_pct'   => $unempR,
                'employee_pct'   => round($socialE + $unempE, 3),
                'employer_pct'   => round($socialR + $unempR, 3),
                'min_wage'       => isset($row['MinWage']) && $row['MinWage'] !== null ? (float) $row['MinWage'] : null,
                'max_wage'       => isset($row['MaxWage']) && $row['MaxWage'] !== null ? (float) $row['MaxWage'] : null,
                'source'         => 'Pay_GosiRate #' . ($row['RateID'] ?? '?'),
            ];
        }

        $fb  = (array) Config::get('payroll.gosi.fallback', []);
        $key = $category === 'expat' ? 'expat' : ($category === 'retiree' ? 'retiree' : 'bahraini');
        $empPct = (float) ($fb[$key]['employee'] ?? 0);
        return [
            'social_emp_pct' => $empPct, 'unemp_emp_pct' => 0.0,
            'social_er_pct'  => (float) ($fb[$key]['employer'] ?? 0), 'unemp_er_pct' => 0.0,
            'employee_pct'   => $empPct, 'employer_pct' => (float) ($fb[$key]['employer'] ?? 0),
            'min_wage'       => null,
            'max_wage'       => isset($fb['cap']) ? (float) $fb['cap'] : null,
            'source'         => 'config fallback',
        ];
    }

    /** Legacy IsBahraini-only lookup, for a DB not yet migrated to Category. */
    private function gosiRateLegacy(string $category, string $onDate): ?array
    {
        try {
            return $this->db->one(
                $this->db->limit(
                    "SELECT * FROM " . pt('gosi_rate') . "
                      WHERE IsBahraini = :b AND EffectiveFrom <= :d
                      ORDER BY EffectiveFrom DESC", 1),
                [':b' => $category === 'expat' ? 0 : 1, ':d' => $onDate]
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Add a new effective-dated GOSI rate row (master screen). */
    public function saveGosiRate(array $d): int
    {
        $social = (float) ($d['social_emp_pct'] ?? 0);
        $unemp  = (float) ($d['unemp_emp_pct'] ?? 0);
        $socialR = (float) ($d['social_er_pct'] ?? 0);
        $unempR  = (float) ($d['unemp_er_pct'] ?? 0);
        $cat = in_array($d['category'] ?? '', ['bahraini', 'retiree', 'expat'], true) ? $d['category'] : 'expat';
        return $this->db->insert(pt('gosi_rate'), [
            'EffectiveFrom' => $d['effective_from'],
            'Category'      => $cat,
            'IsBahraini'    => $cat === 'expat' ? 0 : 1,
            'SocialEmpPct'  => $social,
            'UnempEmpPct'   => $unemp,
            'SocialErPct'   => $socialR,
            'UnempErPct'    => $unempR,
            'EmployeePct'   => round($social + $unemp, 3),
            'EmployerPct'   => round($socialR + $unempR, 3),
            'MinWage'       => $d['min_wage'] !== '' ? (float) $d['min_wage'] : null,
            'MaxWage'       => $d['max_wage'] !== '' ? (float) $d['max_wage'] : null,
            'Notes'         => $d['notes'] ?? null,
        ]);
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
