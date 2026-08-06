<?php
namespace App\Roster\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class ScheduleChangeController extends Controller
{
    /** Change Schedule is now a tab on the merged Attendance page. */
    public function index(): void
    {
        Auth::require();
        $qs = $_GET;
        $qs['tab'] = 'schedule';
        $this->redirect('attendance?' . http_build_query($qs));
    }

    public function save(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId  = (int) $this->input('employee_id');
        $date   = $this->input('work_date');
        $period = $this->input('period', period_of(date('Y-m-d')));
        $back   = 'attendance?tab=schedule&employee_id=' . $empId . '&period=' . $period;
        if (!$empId || !$date) {
            $this->flash('error', 'Employee and schedule day are required.');
            $this->redirect($back);
        }

        $payload = [
            'employee_id'         => $empId,
            'work_date'           => $date,
            'old_shift_id'        => $this->input('old_shift_id'),
            'new_shift_id'        => $this->input('new_shift_id'),
            'change_against_date' => $this->input('change_against_date'),
            'claim_time'          => $this->input('claim_time'),
        ];

        if (legacy_mode()) {
            // Backstop: only if the form didn't send an Old Shift (e.g. JS didn't
            // run), fall back to the rostered shift for that day so ShiftID is
            // never NULL. A normal auto-filled request is untouched. Block only
            // when there's genuinely no old shift to change.
            if (($payload['old_shift_id'] ?? '') === '') {
                $current = (new \App\Roster\Repositories\RosterRepository($this->db))
                    ->forEmployeeRange($empId, $date, $date)[$date] ?? null;
                if ($current) {
                    $payload['old_shift_id'] = $current['shift_id'];
                }
            }
            if (($payload['old_shift_id'] ?? '') === '') {
                $this->flash('error', 'No shift is scheduled for that day, so there is nothing to change.');
                $this->redirect($back);
            }
            try {
                (new \App\Roster\Repositories\ScheduleChangeRepository($this->db))->create($payload);
                $this->flash('success', 'Schedule change request submitted.');
            } catch (\Throwable $e) {
                $this->flash('error', 'Could not save schedule change: ' . $e->getMessage());
            }
            $this->redirect($back);
        }

        $this->db->insert('schedule_change_requests', [
            'employee_id'  => $empId,
            'period_key'   => period_of($date),
            'work_date'    => $date,
            'old_shift_id' => ($payload['old_shift_id'] ?? '') ?: null,
            'new_shift_id' => ($payload['new_shift_id'] ?? '') ?: null,
            'change_against_date' => ($payload['change_against_date'] ?? '') ?: null,
            'claim_time'   => ($payload['claim_time'] ?? '') ?: null,
            'status'       => 'pending',
            'requested_by' => Auth::id(),
        ]);
        $this->flash('success', 'Schedule change request submitted.');
        $this->redirect($back);
    }
}
