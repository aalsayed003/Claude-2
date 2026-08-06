<?php
namespace App\Payroll\Services;

use App\Core\Database;
use App\Core\Config;
use App\Payroll\Repositories\SalaryStructureRepository;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Repositories\LoanRepository;

/**
 * End-of-service settlement: indemnity (leaving indemnity / gratuity), unused
 * annual leave encashment, notice pay, ticket, and recovery of any outstanding
 * loan.
 *
 * The indemnity formula follows the classic Bahrain Labour Law basis — 15 days'
 * wage for each of the first three years of service and 30 days for each year
 * after that, pro-rated for part years — and every number in it is
 * configurable under payroll.indemnity.
 *
 * !! Confirm the treatment that applies to your expat staff before paying a
 *    live settlement: the SIO end-of-service scheme changed how indemnity for
 *    expatriate employees is funded, and a hospital may also owe an accrued
 *    balance for service before the change. This calculator gives the gross
 *    entitlement; it does not know what has already been remitted to SIO.
 */
class SettlementCalculator
{
    private SalaryStructureRepository $structures;
    private StatutoryRepository $statutory;
    private LoanRepository $loans;

    public function __construct(private Database $db)
    {
        $this->structures = new SalaryStructureRepository($db);
        $this->statutory  = new StatutoryRepository($db);
        $this->loans      = new LoanRepository($db);
    }

    /**
     * @param array $emp    employee row (id, emp_code, full_name, joined_at)
     * @param array $inputs lastWorkingDay, reasonId, noticeAmount, ticketAmount,
     *                      otherEarnings, otherDeduction, leaveDaysOverride
     */
    public function compute(array $emp, array $inputs): array
    {
        $empId  = (int) $emp['id'];
        $lastDay = $inputs['last_working_day'] ?? date('Y-m-d');
        $stat    = $this->statutory->forEmployee($empId);

        $joined = $stat['JoiningDate'] ?? $emp['joined_at'] ?? null;
        $joined = $joined ? date('Y-m-d', strtotime((string) $joined)) : null;
        if (!$joined) {
            throw new \RuntimeException('No joining date on record for this employee.');
        }

        $structure = $this->structures->effectiveFor($empId, date('Y-m-01', strtotime($lastDay)));
        $basic = SalaryStructureRepository::basicOf($structure);
        $gross = SalaryStructureRepository::grossOf($structure);

        $service = $this->serviceLength($joined, $lastDay);

        // ---- indemnity -------------------------------------------------------
        $cfg      = (array) Config::get('payroll.indemnity', []);
        $wage     = ($cfg['wage_basis'] ?? 'gross') === 'basic' ? $basic : $gross;
        $dayRate  = $wage / (float) Config::get('payroll.fixed_month_days', 30);
        $minMonths = (float) ($cfg['min_service_months'] ?? 3);

        $days = 0.0;
        if (!empty($cfg['enabled']) && $service['months'] >= $minMonths) {
            $days = $this->indemnityDays($service['years'], $cfg);
        }
        $indemnity = money_round($days * $dayRate);

        // ---- leave encashment -------------------------------------------------
        $leaveCfg   = (array) Config::get('payroll.leave_encash', []);
        $leaveWage  = ($leaveCfg['wage_basis'] ?? 'gross') === 'basic' ? $basic : $gross;
        $leaveDay   = $leaveWage / (float) ($leaveCfg['day_divisor'] ?? 30);
        $given      = $inputs['leave_days'] ?? null;
        $leaveDays  = ($given !== null && $given !== '')
            ? (float) $given
            : $this->leaveBalanceDays($empId);
        $encashment = money_round($leaveDays * $leaveDay);

        // ---- other lines -------------------------------------------------------
        $notice   = money_round($inputs['notice_amount'] ?? 0);
        $ticket   = money_round($inputs['ticket_amount'] ?? 0);
        $otherIn  = money_round($inputs['other_earnings'] ?? 0);
        $loanBal  = money_round($this->loans->outstandingFor($empId));
        $otherOut = money_round($inputs['other_deduction'] ?? 0);

        $earnings   = $indemnity + $encashment + $notice + $ticket + $otherIn;
        $deductions = $loanBal + $otherOut;

        return [
            'employee'        => $emp,
            'joining_date'    => $joined,
            'last_working_day'=> $lastDay,
            'service'         => $service,
            'basic'           => money_round($basic),
            'gross'           => money_round($gross),
            'day_rate'        => money_round($dayRate),
            'indemnity_days'  => round($days, 2),
            'indemnity'       => $indemnity,
            'leave_days'      => round($leaveDays, 2),
            'leave_encash'    => $encashment,
            'notice'          => $notice,
            'ticket'          => $ticket,
            'other_earnings'  => $otherIn,
            'loan_recovery'   => $loanBal,
            'other_deduction' => $otherOut,
            'total_earnings'  => money_round($earnings),
            'total_deduction' => money_round($deductions),
            'net'             => money_round($earnings - $deductions),
        ];
    }

    /** Persist a computed settlement as a draft. */
    public function save(array $r, int $reasonId, string $user, ?string $remarks): int
    {
        return $this->db->insert(pt('settlement'), [
            'EmployeeID'      => (int) $r['employee']['id'],
            'JoiningDate'     => $r['joining_date'],
            'LastWorkingDay'  => $r['last_working_day'],
            'ReasonID'        => $reasonId,
            'ServiceYears'    => round($r['service']['years'], 4),
            'LastBasic'       => $r['basic'],
            'LastGross'       => $r['gross'],
            'IndemnityDays'   => $r['indemnity_days'],
            'IndemnityAmount' => $r['indemnity'],
            'LeaveBalanceDays'=> $r['leave_days'],
            'LeaveEncashment' => $r['leave_encash'],
            'NoticeAmount'    => $r['notice'],
            'TicketAmount'    => $r['ticket'],
            'OtherEarnings'   => $r['other_earnings'],
            'LoanRecovery'    => $r['loan_recovery'],
            'OtherDeduction'  => $r['other_deduction'],
            'NetSettlement'   => $r['net'],
            'StateID'         => 1,
            'Remarks'         => $remarks,
            'CreatedBy'       => $user,
        ]);
    }

    public function forEmployee(int $empId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('settlement') . " WHERE EmployeeID = :e ORDER BY CreatedAt DESC",
            [':e' => $empId]
        );
    }

    // ---------------------------------------------------------------- pieces --

    /**
     * Indemnity days for a length of service. Delegates to the shared
     * IndemnityCalculator so the settlement and the balance-sheet provision
     * always use identical rules.
     */
    private function indemnityDays(float $years, array $cfg): float
    {
        return IndemnityCalculator::days($years, $cfg);
    }

    private function serviceLength(string $from, string $to): array
    {
        $a = new \DateTime($from);
        $b = new \DateTime($to);
        $d = $a->diff($b);
        $totalDays = (int) $d->days + 1;
        return [
            'years'  => $totalDays / 365.25,
            'months' => $d->y * 12 + $d->m + ($d->d > 0 ? $d->d / 30 : 0),
            'text'   => sprintf('%d year%s %d month%s %d day%s',
                            $d->y, $d->y == 1 ? '' : 's',
                            $d->m, $d->m == 1 ? '' : 's',
                            $d->d, $d->d == 1 ? '' : 's'),
            'days'   => $totalDays,
        ];
    }

    /** Unused annual-leave days from the legacy leavebalance table. */
    private function leaveBalanceDays(int $empId): float
    {
        try {
            $v = $this->db->value(
                "SELECT SUM(Balance) FROM " . lt('leave_bal') . "
                  WHERE EmpID = :e AND (deleted = 0 OR deleted IS NULL)",
                [':e' => $empId]
            );
            return (float) ($v ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
