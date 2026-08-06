<?php
namespace App\Roster\Services;

use App\Core\Config;

/**
 * Attendance-correction approval state machine (DR_CorrectionRequest.StateID).
 *
 * Chain depends on the employee's category (via their department, see
 * RequestCategory) — the first gate goes straight to the clinical approver
 * for nurses/doctors (no Dept Head step), then HR applies either way:
 *   default : pending -> (Dept Head approves) -> head_ok -> (HR applies) -> applied
 *   nurse   : pending -> (CNO approves)        -> head_ok -> (HR applies) -> applied
 *   doctor  : pending -> (COO/MD approves)     -> head_ok -> (HR applies) -> applied
 * Reject at either gate -> rejected.
 *
 * State codes are configurable (legacy.correction_states); the app is the only
 * writer/reader now, so they are an internal convention aligned with dr_states.
 */
class CorrectionFlow
{
    public static function states(): array
    {
        return Config::get('legacy.correction_states', [
            'pending' => 1, 'head_ok' => 3, 'applied' => 14, 'rejected' => 11,
        ]);
    }

    /** Ordered 2-role chain for a category: [first approver, 'hr']. */
    public static function chainFor(string $category): array
    {
        $chains = Config::get('legacy.request_chains', [
            'nurse'   => ['cno', 'hr'],
            'doctor'  => ['coo_md', 'hr'],
            'default' => ['dept_head', 'hr'],
        ]);
        return $chains[$category] ?? ($chains['default'] ?? ['dept_head', 'hr']);
    }

    /** @return array{role:string,label:string,is_apply:bool,to_state:int}|null */
    public static function step(int $state, string $category = 'default'): ?array
    {
        $s = self::states();
        $chain = self::chainFor($category);
        if ($state === (int) $s['pending']) {
            return ['role' => $chain[0], 'label' => ApprovalFlow::roleLabel($chain[0]), 'is_apply' => false, 'to_state' => (int) $s['head_ok']];
        }
        if ($state === (int) $s['head_ok']) {
            return ['role' => $chain[1], 'label' => ApprovalFlow::roleLabel($chain[1]), 'is_apply' => true, 'to_state' => (int) $s['applied']];
        }
        return null;   // applied / rejected / unknown — terminal
    }

    public static function statusLabel(int $state, string $category = 'default'): string
    {
        $s = self::states();
        $chain = self::chainFor($category);
        return match ($state) {
            (int) $s['pending']  => 'Awaiting ' . ApprovalFlow::roleLabel($chain[0]),
            (int) $s['head_ok']  => 'Awaiting HR',
            (int) $s['applied']  => 'Applied',
            (int) $s['rejected'] => 'Rejected',
            default              => 'State ' . $state,
        };
    }

    public static function statusClass(int $state): string
    {
        $s = self::states();
        return match ($state) {
            (int) $s['applied']  => 'applied',
            (int) $s['rejected'] => 'rejected',
            (int) $s['head_ok']  => 'present',
            default              => 'pending',
        };
    }

    public static function rejectState(): int
    {
        return (int) (self::states()['rejected'] ?? 11);
    }

    public static function appliedState(): int
    {
        return (int) (self::states()['applied'] ?? 14);
    }
}
