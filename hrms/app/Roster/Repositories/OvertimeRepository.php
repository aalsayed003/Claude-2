<?php
namespace App\Roster\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads overtime requests from the legacy `DR_OverTime` table and shapes them
 * for the Overtime screen's request list. Columns used: RequestID, EmployeeID,
 * OverTimeDate, TotalOverTime (numeric hours), ReasonID, Remarks, StateID.
 */
class OvertimeRepository
{
    public function __construct(private Database $db) {}

    /** Requests for one employee whose OverTimeDate falls in [$from, $to]. */
    public function forEmployee(int $employeeId, string $from, string $to, int $limit = 100): array
    {
        $t   = lt('ot');
        $rt  = lt('ot_reason');
        $rows = $this->db->all(
            $this->db->limit(
                "SELECT ot.RequestID, ot.OverTimeDate, ot.TotalOverTime, ot.Remarks, ot.StateID,
                        r.Reason AS reason_name
                   FROM {$t} ot
                   LEFT JOIN {$rt} r ON r.ReasonID = ot.ReasonID
                  WHERE ot.EmployeeID = :e AND ot.OverTimeDate BETWEEN :a AND :b
                  ORDER BY ot.OverTimeDate DESC", $limit),
            [':e' => $employeeId, ':a' => $from, ':b' => $to . ' 23:59:59']
        );
        return array_map([$this, 'shape'], $rows);
    }

    private function shape(array $r): array
    {
        return [
            'ot_date'       => substr((string) ($r['OverTimeDate'] ?? ''), 0, 10),
            'day_type'      => '',
            'total_minutes' => (int) round(((float) ($r['TotalOverTime'] ?? 0)) * 60),
            'reason'        => trim((string) ($r['reason_name'] ?? $r['Remarks'] ?? '')),
            'status'        => self::statusLabel((int) ($r['StateID'] ?? 0)),
        ];
    }

    /** Map a DR_OverTime StateID to a lowercase status word for the UI chip. */
    public static function statusLabel(int $stateId): string
    {
        $expired = (int) Config::get('legacy.ot_state_expired', 10);
        if ($stateId === $expired) return 'expired';
        return match ($stateId) {
            1              => 'pending',
            3, 4, 5, 6     => 'approved',
            14             => 'applied',
            11, 15         => 'rejected',
            default        => 'pending',
        };
    }
}
