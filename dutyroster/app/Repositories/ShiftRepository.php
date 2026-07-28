<?php
namespace App\Repositories;

use App\Core\Database;

/**
 * Reads shifts from the legacy `Shift` master and shapes them into the app's
 * shift structure. Legacy stores times as varchar (FromTime/ToTime and the
 * split pair FromTime1/ToTime1). DAY OFF / PUBLIC HOLIDAY are identified by
 * name, as in the legacy Shift Master.
 */
class ShiftRepository
{
    public function __construct(private Database $db) {}

    public function all(bool $includeDeleted = false, bool $includeIsBlocked = false): array
    {
        $shift = lt('shift');
        $where = $includeDeleted ? '1=1' : 'Deleted = 0';
        $where2 = $includeIsBlocked ? '1=1' : 'IsBlocked = 0';
        $rows = $this->db->all(
            "SELECT ID AS id, Name AS name, FromTime AS first_in, ToTime AS first_out,
                    FromTime1 AS second_in, ToTime1 AS second_out, TotalHours AS total_hours,
                    split, IsTwoShifts, ColorCode AS color, Deleted, IsBlocked
               FROM {$shift} WHERE {$where} AND {$where2} ORDER BY FromTime"
        );
        return array_map([$this, 'shape'], $rows);
    }

    public function find(int $id): ?array
    {
        $shift = lt('shift');
        $row = $this->db->one(
            "SELECT ID AS id, Name AS name, FromTime AS first_in, ToTime AS first_out,
                    FromTime1 AS second_in, ToTime1 AS second_out, TotalHours AS total_hours,
                    split, IsTwoShifts, ColorCode AS color, Deleted, IsBlocked
               FROM {$shift} WHERE ID = :id",
            [':id' => $id]
        );
        return $row ? $this->shape($row) : null;
    }

    private function shape(array $r): array
    {
        $name = strtoupper(trim((string) ($r['name'] ?? '')));
        $isDayOff  = $name === 'DAY OFF';
        $isHoliday = in_array($name, ['PUBLIC HOLIDAY', 'PUBLIC HOLIDAY '], true) || str_contains($name, 'HOLIDAY');
        return [
            'id'          => (int) $r['id'],
            'code'        => trim((string) ($r['name'] ?? '')),
            'name'        => trim((string) ($r['name'] ?? '')),
            'first_in'    => $r['first_in'] ?: null,
            'first_out'   => $r['first_out'] ?: null,
            'second_in'   => $r['second_in'] ?: null,
            'second_out'  => $r['second_out'] ?: null,
            'total_hours' => (float) ($r['total_hours'] ?? 0),
            'is_day_off'  => $isDayOff ? 1 : 0,
            'is_holiday'  => $isHoliday ? 1 : 0,
            'crosses_midnight' => 0,
            'color'       => $r['color'] ?? null,
            'active'      => (int) ($r['Deleted'] ?? 0) === 0 ? 1 : 0,
            'Blocked'      => (int) ($r['IsBlocked'] ?? 0) === 0 ? 1 : 0,
        ];
    }
}
