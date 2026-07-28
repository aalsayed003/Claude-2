<?php
namespace App\Services;

use App\Core\Config;

/**
 * Attendance-correction approval state machine (DR_CorrectionRequest.StateID).
 *
 * Chain: Dept Head -> HR apply.
 *   pending  -> (Dept Head approves) -> head_ok
 *   head_ok  -> (HR applies)         -> applied   [the override goes live]
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

    public static function chain(): array
    {
        return Config::get('legacy.correction_chain', ['dept_head', 'hr']);
    }

    /** @return array{role:string,label:string,is_apply:bool,to_state:int}|null */
    public static function step(int $state): ?array
    {
        $s = self::states();
        if ($state === (int) $s['pending']) {
            return ['role' => 'dept_head', 'label' => 'Dept Head', 'is_apply' => false, 'to_state' => (int) $s['head_ok']];
        }
        if ($state === (int) $s['head_ok']) {
            return ['role' => 'hr', 'label' => 'HR', 'is_apply' => true, 'to_state' => (int) $s['applied']];
        }
        return null;   // applied / rejected / unknown — terminal
    }

    public static function statusLabel(int $state): string
    {
        $s = self::states();
        return match ($state) {
            (int) $s['pending']  => 'Awaiting Dept Head',
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
