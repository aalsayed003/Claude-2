<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class ShiftController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('dept_head');
        if (legacy_mode()) {
            // Include blocked shifts in the admin list so they can be un-hidden;
            // the roster dropdowns use all() (default) which excludes blocked ones.
            $shifts = (new \App\Repositories\ShiftRepository($this->db))->all(false, true);
            // Newest first (highest ID) so a just-created shift is immediately
            // visible at the top of the list — the master list can be long, and
            // sorting by punch-in time used to bury a new shift out of view.
            usort($shifts, fn($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
        } else {
            $shifts = $this->db->all("SELECT * FROM shifts ORDER BY first_in ASC");
        }
        $this->view('shifts/index', ['title' => 'Duty Roster Master — Shifts', 'shifts' => $shifts]);
    }

    public function create(): void
    {
        Auth::requireRole('dept_head');
        $this->view('shifts/form', ['title' => 'New Shift', 'shift' => null]);
    }

    public function edit(): void
    {
        Auth::requireRole('dept_head');
        $id = (int) $this->input('id');
        $shift = legacy_mode()
            ? (new \App\Repositories\ShiftRepository($this->db))->find($id)
            : $this->db->one("SELECT * FROM shifts WHERE id = :id", [':id' => $id]);
        if (!$shift) {
            $this->flash('error', 'Shift not found.');
            $this->redirect('shifts');
        }
        $this->view('shifts/form', ['title' => 'Edit Shift', 'shift' => $shift]);
    }

    public function save(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $id = (int) ($this->input('id') ?: 0);

        $isDayOff  = $this->input('is_day_off') ? 1 : 0;
        $isHoliday = $this->input('is_holiday') ? 1 : 0;

        $data = [
            'code'        => strtoupper($this->input('code', '')),
            'name'        => $this->input('name') ?: null,
            'first_in'    => $this->timeOrNull('first_in'),
            'first_out'   => $this->timeOrNull('first_out'),
            'second_in'   => $this->timeOrNull('second_in'),
            'second_out'  => $this->timeOrNull('second_out'),
            'is_day_off'  => $isDayOff,
            'is_holiday'  => $isHoliday,
        ];
        $data['total_hours'] = $this->computeHours($data);
        $data['crosses_midnight'] = $this->crossesMidnight($data) ? 1 : 0;

        if ($data['code'] === '') {
            $this->flash('error', 'Shift code is required.');
            $this->redirect('shifts/new');
        }

        if (legacy_mode()) {
            $this->saveLegacyShift($id, $data);
            $this->flash('success', $id ? 'Shift updated.' : 'Shift created.');
            $this->redirect('shifts');
        }

        if ($id) {
            $this->db->update('shifts', $data, 'id = :id', [':id' => $id]);
            $this->flash('success', 'Shift updated.');
        } else {
            $this->db->insert('shifts', $data);
            $this->flash('success', 'Shift created.');
        }
        $this->redirect('shifts');
    }

    /**
     * Write a shift to the legacy `Shift` master. Legacy stores the code in the
     * Name column and the times as varchar (FromTime/ToTime + the split pair
     * FromTime1/ToTime1); DAY OFF / PUBLIC HOLIDAY are recognised by Name. ID is
     * supplied via the identity-safe helper (TestASSH dropped IDENTITY).
     */
    private function saveLegacyShift(int $id, array $data): void
    {
        $shift = lt('shift');
        $name = $data['is_day_off'] ? 'DAY OFF'
              : ($data['is_holiday'] ? 'PUBLIC HOLIDAY' : $data['code']);
        $split = ($data['second_in'] || $data['second_out']) ? 1 : 0;
        $row = [
            'Name'       => $name,
            'FromTime'   => $data['first_in'],
            'ToTime'     => $data['first_out'],
            'FromTime1'  => $data['second_in'],
            'ToTime1'    => $data['second_out'],
            'TotalHours' => $data['total_hours'],
            'split'      => $split,
            'operatorid' => Auth::id(),
        ];
        if ($id) {
            $this->db->update($shift, $row, 'ID = :id', [':id' => $id]);
        } else {
            $row['StartDateTime'] = date('Y-m-d H:i:s');
            $row['Deleted']  = 0;
            $row['IsBlocked'] = 0;
            $this->db->insertLegacy($shift, $row, 'ID');
        }
    }

    /**
     * In legacy mode the Shift master is shared and must never be hard-deleted,
     * so this toggles IsBlocked: a blocked shift disappears from the roster
     * dropdowns (all()), which is how the long shift list is trimmed. Toggling
     * again restores it. In the clean schema it keeps the delete/deactivate
     * behaviour.
     */
    public function delete(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $id = (int) $this->input('id');

        if (legacy_mode()) {
            $shift = (new \App\Repositories\ShiftRepository($this->db))->find($id);
            if (!$shift) {
                $this->flash('error', 'Shift not found.');
                $this->redirect('shifts');
            }
            // shape 'Blocked' == 1 means currently visible -> hide it (IsBlocked = 1).
            $block = ((int) $shift['Blocked'] === 1) ? 1 : 0;
            $this->db->update(lt('shift'), ['IsBlocked' => $block], 'ID = :id', [':id' => $id]);
            $this->flash('success', $block
                ? 'Shift hidden from the roster dropdowns.'
                : 'Shift restored to the roster dropdowns.');
            $this->redirect('shifts');
        }

        $inUse = (int) $this->db->value("SELECT COUNT(*) FROM roster WHERE shift_id = :id", [':id' => $id]);
        if ($inUse > 0) {
            $this->db->update('shifts', ['active' => 0], 'id = :id', [':id' => $id]);
            $this->flash('success', 'Shift is in use — deactivated instead of deleted.');
        } else {
            $this->db->run("DELETE FROM shifts WHERE id = :id", [':id' => $id]);
            $this->flash('success', 'Shift deleted.');
        }
        $this->redirect('shifts');
    }

    private function timeOrNull(string $key): ?string
    {
        $v = $this->input($key);
        return $v ? $v : null;
    }

    private function computeHours(array $d): float
    {
        if ($d['is_day_off'] || $d['is_holiday']) {
            return 0.0;
        }
        $mins = 0;
        $mins += $this->pairMinutes($d['first_in'], $d['first_out']);
        $mins += $this->pairMinutes($d['second_in'], $d['second_out']);
        return round($mins / 60, 2);
    }

    private function pairMinutes(?string $in, ?string $out): int
    {
        if (!$in || !$out) {
            return 0;
        }
        $a = strtotime($in);
        $b = strtotime($out);
        if ($b <= $a) {
            $b += 86400; // crosses midnight
        }
        return (int) round(($b - $a) / 60);
    }

    private function crossesMidnight(array $d): bool
    {
        $out = $d['second_out'] ?: $d['first_out'];
        $in  = $d['first_in'];
        return $in && $out && strtotime($out) <= strtotime($in);
    }
}
