<?php
namespace App\Services;

use App\Core\Config;

/**
 * Roster-submission (Schedule_Request) approval state machine.
 *
 * The chain depends on the department's category (from payroll; until then a
 * config map, defaulting to the plain chain):
 *   nurse : Dept Head -> CNO      -> HR apply
 *   doctor: Dept Head -> COO/MD   -> HR apply
 *   other : Dept Head             -> HR apply
 *
 * Progress is carried by Schedule_Request.Approved (1 = Submitted, 2 = Dept
 * Head approved, 3 = second-level approved) and Uploaded (1 = HR applied).
 * Each non-final gate bumps Approved; the final gate (HR) sets Uploaded = 1.
 */
class ApprovalFlow
{
    /** Ordered role chain for a category (last role is always the HR "apply" step). */
    public static function chainFor(string $category): array
    {
        $chains = Config::get('legacy.approval_chains', [
            'nurse'   => ['dept_head', 'cno', 'hr'],
            'doctor'  => ['dept_head', 'coo_md', 'hr'],
            'default' => ['dept_head', 'hr'],
        ]);
        return $chains[$category] ?? ($chains['default'] ?? ['dept_head', 'hr']);
    }

    /**
     * The gate the request is currently waiting on, or null when it's finished
     * (applied or rejected).
     *
     * @return array{role:string,label:string,is_apply:bool,set:array}|null
     */
    public static function step(string $category, int $approved, int $uploaded): ?array
    {
        if ($uploaded === 1 || $approved === self::rejectCode()) {
            return null;   // applied or rejected — nothing left to do
        }
        $chain = self::chainFor($category);
        $n = count($chain);
        $gatesPassed = max(0, $approved - 1);   // approvals granted so far

        if ($gatesPassed < $n - 1) {
            $role = $chain[$gatesPassed];
            return [
                'role'  => $role,
                'label' => self::roleLabel($role),
                'is_apply' => false,
                'set'   => ['Approved' => $gatesPassed + 2],
            ];
        }

        // Final step: HR applies to the live roster.
        $role = $chain[$n - 1];
        return [
            'role'  => $role,
            'label' => self::roleLabel($role),
            'is_apply' => true,
            'set'   => ['Approved' => 3, 'Uploaded' => 1],
        ];
    }

    /** Human status for the list, e.g. "Awaiting CNO", "Applied", "Rejected". */
    public static function statusLabel(string $category, int $approved, int $uploaded): string
    {
        if ($approved === self::rejectCode()) return 'Rejected';
        if ($uploaded === 1) return 'Applied';
        $step = self::step($category, $approved, $uploaded);
        return $step ? ('Awaiting ' . $step['label']) : 'Applied';
    }

    /** CSS chip class matching statusLabel(). */
    public static function statusClass(string $category, int $approved, int $uploaded): string
    {
        if ($approved === self::rejectCode()) return 'rejected';
        if ($uploaded === 1) return 'applied';
        return $approved >= 2 ? 'present' : 'pending';
    }

    public static function rejectCode(): int
    {
        return (int) Config::get('legacy.schedule_reject_code', 9);
    }

    public static function roleLabel(string $role): string
    {
        $map = [
            'dept_head' => 'Dept Head', 'cno' => 'CNO', 'coo_md' => 'COO/MD',
            'hr' => 'HR', 'fa' => 'FA', 'mrd' => 'MRD', 'coo' => 'COO', 'admin' => 'Admin',
        ];
        return $map[$role] ?? ucfirst($role);
    }
}
