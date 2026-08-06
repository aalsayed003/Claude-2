<?php
namespace App\Payroll\Services;

use App\Core\Database;
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Repositories\SalaryStructureRepository;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Repositories\LoanRepository;
use App\Payroll\Repositories\SalaryHoldRepository;
use App\Payroll\Repositories\LeaveEncashmentRepository;

/**
 * The payroll calculation.
 *
 * For one payroll month it walks every payable employee and produces a
 * component breakdown, then writes it to the legacy register (CurrentMonth).
 * The same breakdown feeds the payslip, so what an employee sees is what was
 * posted — there is no second calculation path.
 *
 * Money model
 * -----------
 *   employment factor   mid-month joiners and leavers are prorated by the days
 *                       they were actually employed in the cycle
 *   earnings            structure value x employment factor (per component)
 *   day rate            monthly wage / divisor (fixed 30, calendar or rostered
 *                       days — payroll.day_rate_basis)
 *   absence, unpaid     day rate x days, deducted rather than netted off
 *   lates, early-outs   minute rate x minutes, after the monthly grace, and
 *                       waived for days with an approved correction
 *   overtime            hourly rate x multiplier by day type, approved OT only
 *   GOSI                employee share on the contributory components
 *   loans               the installment due this month, capped at the balance
 *
 * Nothing is written for a run that is already approved or locked.
 */
class PayrollEngine
{
    private PayrollRepository $payroll;
    private SalaryStructureRepository $structures;
    private StatutoryRepository $statutory;
    private LoanRepository $loans;
    private SalaryHoldRepository $holds;
    private LeaveEncashmentRepository $encashments;
    private PayrollAttendance $attendance;
    private GosiCalculator $gosi;

    public function __construct(private Database $db)
    {
        $this->payroll     = new PayrollRepository($db);
        $this->structures  = new SalaryStructureRepository($db);
        $this->statutory   = new StatutoryRepository($db);
        $this->loans       = new LoanRepository($db);
        $this->holds       = new SalaryHoldRepository($db);
        $this->encashments = new LeaveEncashmentRepository($db);
        $this->attendance  = new PayrollAttendance($db);
        $this->gosi        = new GosiCalculator($this->statutory);
    }

    /**
     * Calculate a whole month and write the register.
     *
     * @return array{employees:int,earnings:float,deductions:float,net:float,skipped:array,rows:array}
     */
    public function calculate(array $run, ?int $departmentId, int $operatorId, string $user): array
    {
        if (!$this->payroll->isEditable($run)) {
            throw new \RuntimeException('This payroll run is approved or locked and cannot be recalculated.');
        }

        $month = date('Y-m-01', strtotime((string) $run['PayrollMonth']));
        $from  = substr((string) $run['PeriodFrom'], 0, 10);
        $to    = substr((string) $run['PeriodTo'], 0, 10);

        $employees  = $this->payroll->payableEmployees($month, $departmentId);
        $structures = $this->structures->effectiveForAll($month);
        $statutory  = $this->statutory->all();
        $loansDue   = $this->loans->dueByEmployee($month);
        $released   = $this->holds->releasedIntoMonth($month);       // held pay paid now
        $heldNow    = $this->holds->activeForMonth($month);          // withheld this month
        $encashDue  = $this->encashments->approvedForMonth($month);  // leave encashment
        $this->attendance->load($from, $to);

        $rows = [];
        $skipped = [];
        $tE = $tD = $tN = 0.0;

        $this->db->begin();
        try {
            if ($departmentId === null) {
                $this->payroll->clearRegisterMonth($month);
            }

            foreach ($employees as $emp) {
                $empId = (int) $emp['id'];
                $structure = $structures[$empId] ?? null;
                if (!$structure) {
                    $skipped[] = $emp + ['reason' => 'no salary structure in force'];
                    continue;
                }

                $summary = $this->attendance->summarize($emp);
                $result  = $this->computeEmployee(
                    $emp, $structure, $summary, $month,
                    $statutory[$empId] ?? null,
                    $loansDue[$empId] ?? [],
                    ['released' => $released[$empId] ?? 0, 'encash' => $encashDue[$empId] ?? 0,
                     'held' => isset($heldNow[$empId])]
                );

                $this->payroll->writeRegisterRow(
                    $empId, $month,
                    $this->toRegisterRow($result, $emp, $statutory[$empId] ?? null, $operatorId, (int) $run['StateID'])
                );

                $rows[] = $result;
                $tE += $result['totals']['earnings'];
                $tD += $result['totals']['deductions'];
                $tN += $result['totals']['net'];
            }

            $this->payroll->updateRunTotals((int) $run['RunID'], count($rows), $tE, $tD, $tN);
            $this->payroll->setRunState((int) $run['RunID'], PayrollRepository::CALCULATED, $user,
                count($rows) . ' employees, ' . count($skipped) . ' skipped');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return [
            'employees'  => count($rows),
            'earnings'   => money_round($tE),
            'deductions' => money_round($tD),
            'net'        => money_round($tN),
            'skipped'    => $skipped,
            'rows'       => $rows,
        ];
    }

    /**
     * Calculate one employee without writing anything — used by the payslip
     * preview and the "explain this figure" panel.
     */
    public function preview(array $emp, string $payrollMonth): ?array
    {
        $month = date('Y-m-01', strtotime(substr($payrollMonth, 0, 7) . '-01'));
        $period = period_of_payroll_month($month);
        [$from, $to] = period_bounds($period);

        $structure = $this->structures->effectiveFor((int) $emp['id'], $month);
        if (!$structure) {
            return null;
        }
        $this->attendance->load($from, $to);
        $summary = $this->attendance->summarize($emp);

        $empId = (int) $emp['id'];
        return $this->computeEmployee(
            $emp, $structure, $summary, $month,
            $this->statutory->forEmployee($empId),
            $this->loans->dueByEmployee($month)[$empId] ?? [],
            [
                'released' => $this->holds->releasedIntoMonth($month)[$empId] ?? 0,
                'encash'   => $this->encashments->approvedForMonth($month)[$empId] ?? 0,
                'held'     => $this->holds->isHeld($empId, $month),
            ]
        );
    }

    // ------------------------------------------------------------- the maths --

    public function computeEmployee(
        array $emp, array $structure, array $summary, string $month,
        ?array $stat, array $loansDue, array $extra = []
    ): array {
        $cfg   = (array) Config::get('payroll.components', []);
        $divisor = $this->divisor($summary);
        $hoursPerDay = (float) Config::get('payroll.hours_per_day', 8);

        $fullBasic = SalaryStructureRepository::basicOf($structure);
        $fullGross = SalaryStructureRepository::grossOf($structure);

        $factor = $this->employmentFactor($emp, $summary);

        // ---- earnings from the structure -----------------------------------
        $components = [];
        $contributoryWage = 0.0;
        foreach ($cfg as $key => $c) {
            if (($c['type'] ?? 'earning') !== 'earning' || empty($c['structure'])) {
                continue;
            }
            $amount = (float) ($structure[$c['structure']] ?? 0);
            if (!empty($c['prorate'])) {
                $amount *= $factor;
            }
            $amount = money_round($amount);
            if ($amount != 0.0) {
                $components[$key] = $amount;
            }
            if (!empty($c['gosi'])) {
                $contributoryWage += $amount;
            }
        }

        // ---- rates ---------------------------------------------------------
        $dayWage   = Config::get('payroll.day_rate_on', 'gross') === 'basic' ? $fullBasic : $fullGross;
        $penWage   = Config::get('payroll.penalty_rate_on', 'basic') === 'gross' ? $fullGross : $fullBasic;
        $otWage    = Config::get('payroll.ot_rate_on', 'basic') === 'gross' ? $fullGross : $fullBasic;
        $dayRate    = $divisor > 0 ? $dayWage / $divisor : 0.0;
        $penMinute  = $divisor > 0 ? $penWage / ($divisor * $hoursPerDay * 60) : 0.0;
        $otHour     = $divisor > 0 ? $otWage / ($divisor * $hoursPerDay) : 0.0;

        // ---- attendance-driven deductions ----------------------------------
        $absentDays = (float) $summary['absent_days'];
        $unpaidDays = (float) $summary['unpaid_leave_days'];
        if ($absentDays > 0) {
            $components['absence'] = money_round($dayRate * $absentDays);
        }
        if ($unpaidDays > 0) {
            $components['unpaid_leave'] = money_round($dayRate * $unpaidDays);
        }

        $graceMonth = (int) Config::get('payroll.late_grace_minutes_month', 0);
        $lateMin = max(0, (int) $summary['late_minutes'] - $graceMonth);
        if (Config::get('payroll.deduct_lates', true) && $lateMin > 0) {
            $components['lates'] = money_round($penMinute * $lateMin);
        }
        if (Config::get('payroll.deduct_undertime', true) && $summary['undertime_minutes'] > 0) {
            $components['undertime'] = money_round($penMinute * (int) $summary['undertime_minutes']);
        }

        // ---- overtime -------------------------------------------------------
        $otRates = (array) Config::get('payroll.ot_rates', []);
        $otAmount = 0.0;
        $otDetail = [];
        foreach ((array) $summary['ot_minutes'] as $type => $minutes) {
            if ($minutes <= 0) {
                continue;
            }
            $mult   = (float) ($otRates[$type] ?? 1.0);
            $amount = money_round(($minutes / 60) * $otHour * $mult);
            $otAmount += $amount;
            $otDetail[$type] = ['minutes' => $minutes, 'multiplier' => $mult, 'amount' => $amount];
        }
        if ($otAmount > 0) {
            $components['overtime'] = money_round($otAmount);
        }

        // ---- ad-hoc adjustments captured for the month ----------------------
        foreach ($this->monthlyAdjustments((int) $emp['id'], $month) as $key => $amount) {
            if ($amount != 0.0) {
                $components[$key] = money_round(($components[$key] ?? 0) + $amount);
            }
        }

        // ---- leave encashment approved for this month -----------------------
        if (($extra['encash'] ?? 0) > 0) {
            $components['leave_encash'] = money_round(($components['leave_encash'] ?? 0) + (float) $extra['encash']);
        }

        // ---- salary previously held, released into this month ---------------
        if (($extra['released'] ?? 0) > 0) {
            $components['arrear'] = money_round(($components['arrear'] ?? 0) + (float) $extra['released']);
        }

        // ---- GOSI ------------------------------------------------------------
        $gosi = $this->gosi->compute($contributoryWage, $stat, $month);
        if ($gosi['employee'] > 0) {
            $components['gosi'] = $gosi['employee'];
        }

        // ---- loans -----------------------------------------------------------
        foreach ($loansDue as $key => $amount) {
            if ($key === '_loans' || !is_numeric($amount)) {
                continue;
            }
            $components[$key] = money_round(($components[$key] ?? 0) + (float) $amount);
        }

        // ---- totals ----------------------------------------------------------
        $earnings = $deductions = 0.0;
        foreach ($components as $key => $amount) {
            if (($cfg[$key]['type'] ?? 'earning') === 'deduction') {
                $deductions += $amount;
            } else {
                $earnings += $amount;
            }
        }
        $net = $earnings - $deductions;

        return [
            'employee'   => $emp,
            'month'      => $month,
            'structure'  => $structure,
            'summary'    => $summary,
            'components' => $components,
            'held'       => (bool) ($extra['held'] ?? false),
            'ot_detail'  => $otDetail,
            'gosi'       => $gosi,
            'loans'      => $loansDue['_loans'] ?? [],
            'rates'      => [
                'divisor'          => $divisor,
                'employment_factor'=> round($factor, 6),
                'day_rate'         => money_round($dayRate),
                'minute_rate'      => round($penMinute, 6),
                'hour_rate'        => money_round($otHour),
                'full_basic'       => money_round($fullBasic),
                'full_gross'       => money_round($fullGross),
            ],
            'totals'     => [
                'earnings'   => money_round($earnings),
                'deductions' => money_round($deductions),
                'net'        => money_round($net),
                'payable_days' => round(max(0, $divisor - $absentDays - $unpaidDays), 2),
            ],
        ];
    }

    /** Map the component breakdown onto CurrentMonth's columns. */
    private function toRegisterRow(array $r, array $emp, ?array $stat, int $operatorId, int $runState): array
    {
        $cfg = (array) Config::get('payroll.components', []);
        $row = [];

        foreach ($r['components'] as $key => $amount) {
            $col = $cfg[$key]['register'] ?? null;
            if ($col) {
                $row[$col] = $amount;
            }
        }

        $s = $r['summary'];
        $row += [
            'Basicsalary'      => $r['rates']['full_basic'],
            'NoofDaysattended' => $s['present_days'],
            'LEAVE'            => $s['paid_leave_days'],
            'NHoliDays'        => $s['off_days'],
            'payabledays'      => $r['totals']['payable_days'],
            'absentdays'       => $s['absent_days'],
            'unpaidleavedays'  => $s['unpaid_leave_days'],
            'TotalEarnings'    => $r['totals']['earnings'],
            'TotalDeduction'   => $r['totals']['deductions'],
            'NetPayment'       => $r['totals']['net'],
            'Departmentid'     => $emp['department_id'] ?? null,
            'Designationid'    => $emp['designation_id'] ?? null,
            'Categoryid'       => $emp['category_id'] ?? null,
            'Operatorid'       => $operatorId,
            'StateID'          => $runState,
        ];

        if ($stat) {
            $row['bankid'] = $stat['BankID'] ?? null;
            $row['Accno']  = ($stat['IBAN'] ?? null) ?: ($stat['AccountNo'] ?? null);
            $row['Mode']   = (int) ($stat['PaymentMode'] ?? 1);
        }

        return $row;
    }

    /**
     * Ad-hoc amounts captured against the month in MonthlyAllowances, keyed by
     * component. Refund columns reduce the matching attendance deduction.
     */
    private function monthlyAdjustments(int $empId, string $month): array
    {
        $row = $this->payroll->monthlyAllowances($empId, $month);
        if (!$row) {
            return [];
        }
        $out = [
            'pos_adjust'  => (float) ($row['PositiveAdjust'] ?? 0),
            'neg_adjust'  => (float) ($row['NegativeAdjust'] ?? 0),
            'phone_bills' => (float) ($row['PhoneBills'] ?? 0),
            'elec_bills'  => (float) ($row['ElecBills'] ?? 0),
            'other_ded1'  => (float) ($row['OtherDed'] ?? 0),
        ];
        // Refunds are stored positive; they cancel part of the deduction.
        $out['lates']     = -1 * (float) ($row['LatesRefund'] ?? 0);
        $out['undertime'] = -1 * (float) ($row['UndertimesRefund'] ?? 0);
        $out['absence']   = -1 * (float) ($row['AbsencesRefund'] ?? 0);
        return array_filter($out, fn($v) => $v != 0.0);
    }

    /** Days in the month used as the divisor for day and hour rates. */
    private function divisor(array $summary): float
    {
        switch (Config::get('payroll.day_rate_basis', 'fixed')) {
            case 'calendar':
                return (float) max(1, $summary['calendar_days']);
            case 'scheduled':
                return (float) max(1, $summary['scheduled_days']);
            case 'fixed':
            default:
                return (float) Config::get('payroll.fixed_month_days', 30);
        }
    }

    /**
     * Proportion of the cycle the employee was actually employed for, so a
     * mid-month joiner or leaver is paid for their days and not the month.
     */
    private function employmentFactor(array $emp, array $summary): float
    {
        $from = strtotime($summary['period_from']);
        $to   = strtotime($summary['period_to']);
        $days = (int) $summary['calendar_days'];
        if ($days <= 0) {
            return 1.0;
        }

        $joined = !empty($emp['joined_at']) ? strtotime((string) $emp['joined_at']) : null;
        $left   = !empty($emp['left_at'])   ? strtotime((string) $emp['left_at'])   : null;

        $start = ($joined && $joined > $from) ? $joined : $from;
        $end   = ($left   && $left   < $to)   ? $left   : $to;
        if ($end < $start) {
            return 0.0;
        }
        $employed = (int) round(($end - $start) / 86400) + 1;
        return min(1.0, $employed / $days);
    }
}
