<?php
namespace App\Services;

use App\Core\Config;

/**
 * Schedule-change approval state machine (DR_ChangeSchedule.StateID).
 *
 * Same shape and same category routing as CorrectionFlow — first gate goes
 * straight to the clinical approver for nurses/doctors (no Dept Head step),
 * then HR applies either way:
 *   default : pending -> (Dept Head approves) -> head_ok -> (HR applies) -> applied
 *   nurse   : pending -> (CNO approves)        -> head_ok -> (HR applies) -> applied
 *   doctor  : pending -> (COO/MD approves)     -> head_ok -> (HR applies) -> applied
 * Reject at either gate -> rejected.
 *
 * Uses the same state codes as corrections by default (legacy.correction_states)
 * since both tables share the DR request StateID convention (see config.php),
 * but can be overridden independently via legacy.schedule_change_states.
 */
class ScheduleChangeFlow
{
    public static function states(): array
    {
        return Config::get('legacy.schedule_change_states',
            Config::get('legacy.correction_states', [
                'pending' => 1, 'head_ok' => 3, 'applied' => 14, 'rejected' => 11,
            ])
        );
    }

    /** @return array{role:string,label:string,is_apply:bool,to_state:int}|null */
    public static function step(int $state, string $category = 'default'): ?array
    {
        $s = self::states();
        $chain = CorrectionFlow::chainFor($category);
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
        $chain = CorrectionFlow::chainFor($category);
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
