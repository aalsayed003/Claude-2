<?php
namespace App\Roster\Repositories;

use App\Core\Database;

/**
 * Reads the legacy `DR_OvertimeReason` master — the shared reason list for
 * overtime and attendance corrections, with per-category maximum limits.
 * Columns: ReasonID, Reason, IsOvertime, MaximumLimitAdmin/Doctor/Nursing,
 * Status, OvertimeExpiry.
 */
class ReasonRepository
{
    public function __construct(private Database $db) {}

    public function all(): array
    {
        $t = lt('ot_reason');
        return array_map([$this, 'shape'], $this->db->all(
            "SELECT ReasonID, Reason, IsOvertime,
                    MaximumLimitAdmin, MaximumLimitDoctor, MaximumLimitNursing, OvertimeExpiry
               FROM {$t} ORDER BY Reason"
        ));
    }

    /** Reasons flagged as overtime (IsOvertime = 1). */
    public function overtime(): array
    {
        return array_values(array_filter($this->all(), fn($r) => $r['is_overtime'] === 1));
    }

    private function shape(array $r): array
    {
        return [
            'id'            => (int) $r['ReasonID'],
            'name'          => trim((string) $r['Reason']),
            'is_overtime'   => (int) ($r['IsOvertime'] ?? 0),
            'limit_admin'   => (int) ($r['MaximumLimitAdmin'] ?? 0),
            'limit_doctor'  => (int) ($r['MaximumLimitDoctor'] ?? 0),
            'limit_nursing' => (int) ($r['MaximumLimitNursing'] ?? 0),
            'expiry'        => $r['OvertimeExpiry'] !== null ? (int) $r['OvertimeExpiry'] : null,
        ];
    }
}
