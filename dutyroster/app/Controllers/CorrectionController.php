<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class CorrectionController extends Controller
{
    /** Attendance Correction is now a tab on the merged Attendance page. */
    public function index(): void
    {
        Auth::require();
        $qs = $_GET;
        $qs['tab'] = 'correction';
        $this->redirect('attendance?' . http_build_query($qs));
    }

    /**
     * The approved duty-roster schedule per day for an employee over a range,
     * keyed by 'Y-m-d' => [first_in, first_out, second_in, second_out, split].
     * A correction "resets" a punch to one of these scheduled times, so the
     * form and the save both resolve the target time from here — never from
     * anything the employee types.
     */
    private function scheduledForRange(int $empId, string $from, string $to): array
    {
        if (legacy_mode()) {
            $rows = (new \App\Repositories\RosterRepository($this->db))->forEmployeeRange($empId, $from, $to);
        } else {
            $rows = [];
            foreach ($this->db->all(
                "SELECT r.work_date, s.first_in, s.first_out, s.second_in, s.second_out
                   FROM roster r JOIN shifts s ON s.id = r.shift_id
                  WHERE r.employee_id = :e AND r.work_date BETWEEN :a AND :b",
                [':e' => $empId, ':a' => $from, ':b' => $to]
            ) as $r) {
                $rows[substr((string) $r['work_date'], 0, 10)] = $r;
            }
        }
        $out = [];
        foreach ($rows as $date => $r) {
            $out[$date] = $this->schedShape($r);
        }
        return $out;
    }

    /** Normalise a roster row's four scheduled times to 'H:i' and flag split duty. */
    private function schedShape(array $r): array
    {
        $norm = fn($t) => ($t !== null && trim((string) $t) !== '')
            ? date('H:i', strtotime((string) $t)) : null;
        $fi = $norm($r['first_in']   ?? null); $fo = $norm($r['first_out']  ?? null);
        $si = $norm($r['second_in']  ?? null); $so = $norm($r['second_out'] ?? null);
        return [
            'first_in' => $fi, 'first_out' => $fo, 'second_in' => $si, 'second_out' => $so,
            'split'    => ($si !== null || $so !== null),
        ];
    }

    private const PUNCH_LABELS = [
        'first_in' => 'First In', 'first_out' => 'First Out',
        'second_in' => 'Second In', 'second_out' => 'Second Out',
    ];

    public function save(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId  = (int) $this->input('employee_id');
        $period = $this->input('period');
        $date   = $this->input('work_date');
        $back   = 'attendance?tab=correction&employee_id=' . $empId . '&period=' . $period;
        if (!$empId || !$date) {
            $this->flash('error', 'Employee and date are required.');
            $this->redirect('correction');
        }

        // The employee only says WHICH punch(es) to correct — never the time.
        $want = [
            'first_in'   => (bool) $this->input('fix_first_in'),
            'first_out'  => (bool) $this->input('fix_first_out'),
            'second_in'  => (bool) $this->input('fix_second_in'),
            'second_out' => (bool) $this->input('fix_second_out'),
        ];
        if (!array_filter($want)) {
            $this->flash('error', 'Tick at least one punch (First In / First Out / …) to correct.');
            $this->redirect($back);
        }

        // Resolve the target time(s) from the approved roster for that exact day.
        $sched = $this->scheduledForRange($empId, $date, $date)[$date] ?? null;
        if (!$sched) {
            $this->flash('error', 'There is no approved duty roster for that day, so there is no scheduled time to reset the punch to.');
            $this->redirect($back);
        }

        $times = ['first_in' => null, 'first_out' => null, 'second_in' => null, 'second_out' => null];
        $missing = [];
        foreach ($want as $field => $on) {
            if (!$on) continue;
            if (empty($sched[$field])) {
                $missing[] = self::PUNCH_LABELS[$field];
            } else {
                $times[$field] = $sched[$field];   // 'H:i' from the roster
            }
        }
        if ($missing) {
            $this->flash('error', 'The roster has no scheduled ' . implode(', ', $missing)
                . ' for that day' . ($sched['split'] ? '.' : ' (it is not a split-duty day).'));
            $this->redirect($back);
        }

        if (legacy_mode()) {
            // TypeId: 0 = an IN punch is being corrected, 1 = an OUT punch.
            $typeId = ($want['first_in'] || $want['second_in']) ? 0 : 1;
            try {
                (new \App\Repositories\CorrectionRepository($this->db))->create([
                    'employee_id' => $empId,
                    'work_date'   => $date,
                    'first_in'    => $times['first_in'],  'first_out'  => $times['first_out'],
                    'second_in'   => $times['second_in'], 'second_out' => $times['second_out'],
                    'reason_id'   => $this->input('reason', ''),
                    'type_id'     => $typeId,
                    'remarks'     => $this->input('remarks') ?: '',
                ]);
                $this->flash('success', 'Correction request submitted — the punch will be reset to the rostered time once approved.');
            } catch (\Throwable $e) {
                $this->flash('error', 'Could not save correction: ' . $e->getMessage());
            }
            $this->redirect($back);
        }

        $reqId = $this->db->insert('correction_requests', [
            'employee_id' => $empId,
            'period_key'  => $period,
            'status'      => 'pending',
            'requested_by'=> Auth::id(),
        ]);
        $this->db->insert('correction_details', [
            'request_id' => $reqId,
            'work_date'  => $date,
            'first_in'   => $times['first_in'],
            'first_out'  => $times['first_out'],
            'second_in'  => $times['second_in'],
            'second_out' => $times['second_out'],
            'reason'     => $this->input('reason') ?: null,
            'remarks'    => $this->input('remarks') ?: null,
        ]);
        $this->flash('success', 'Correction request submitted — the punch will be reset to the rostered time once approved.');
        $this->redirect($back);
    }
}
