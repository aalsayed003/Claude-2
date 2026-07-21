<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class ShiftController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('dept_head');
        $shifts = $this->db->all("SELECT * FROM shifts ORDER BY is_day_off DESC, is_holiday DESC, code");
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
        $shift = $this->db->one("SELECT * FROM shifts WHERE id = :id", [':id' => $id]);
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

        if ($id) {
            $this->db->update('shifts', $data, 'id = :id', [':id' => $id]);
            $this->flash('success', 'Shift updated.');
        } else {
            $this->db->insert('shifts', $data);
            $this->flash('success', 'Shift created.');
        }
        $this->redirect('shifts');
    }

    public function delete(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $id = (int) $this->input('id');
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
