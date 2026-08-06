<?php
namespace App\Repositories;

use App\Core\Database;

/**
 * Staff loans, advances and their installment schedule.
 *
 * The legacy register has the deduction columns (LoanAmount, bankloan,
 * otherloan, Advance) but no loan master behind them, so a balance could only
 * be reconstructed by adding up historical registers. Loans are therefore kept
 * here, and the engine posts the installment due in the payroll month to the
 * column matching the loan type.
 */
class LoanRepository
{
    /** LoanType -> register column (mirrors the config component keys). */
    public const TYPE_COLUMN = [
        1 => 'loan',        // staff loan     -> CurrentMonth.LoanAmount
        2 => 'bank_loan',   // bank loan      -> CurrentMonth.bankloan
        3 => 'other_loan',  // other loan     -> CurrentMonth.otherloan
        4 => 'advance',     // salary advance -> CurrentMonth.Advance
    ];

    public const TYPE_LABEL = [
        1 => 'Staff loan', 2 => 'Bank loan', 3 => 'Other loan', 4 => 'Salary advance',
    ];

    public const ACTIVE = 1, ON_HOLD = 2, SETTLED = 3, CANCELLED = 9;

    public function __construct(private Database $db) {}

    public function forEmployee(int $empId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('loan') . " WHERE EmployeeID = :e ORDER BY StartMonth DESC",
            [':e' => $empId]
        );
    }

    public function find(int $loanId): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('loan') . " WHERE LoanID = :id", [':id' => $loanId]);
    }

    public function activeLoans(): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('loan') . " WHERE StateID = :s", [':s' => self::ACTIVE]);
    }

    /**
     * Installments due in a payroll month, grouped by employee then component
     * key: [empId => ['loan' => 120.000, 'advance' => 50.000]].
     *
     * A loan contributes in a month when the month falls inside its schedule
     * and the outstanding balance is not already recovered. The final
     * installment is trimmed to the remaining balance so rounding can never
     * over-recover.
     */
    public function dueByEmployee(string $payrollMonth): array
    {
        $month = date('Y-m-01', strtotime(substr($payrollMonth, 0, 7) . '-01'));
        $due   = [];
        foreach ($this->activeLoans() as $l) {
            $amount = $this->installmentDue($l, $month);
            if ($amount <= 0) {
                continue;
            }
            $key = self::TYPE_COLUMN[(int) $l['LoanType']] ?? 'other_loan';
            $emp = (int) $l['EmployeeID'];
            $due[$emp][$key] = ($due[$emp][$key] ?? 0) + $amount;
            $due[$emp]['_loans'][] = ['loan_id' => (int) $l['LoanID'], 'amount' => $amount, 'key' => $key];
        }
        return $due;
    }

    /** The amount this loan should recover in $month (0 when outside its schedule). */
    public function installmentDue(array $loan, string $month): float
    {
        $start = date('Y-m-01', strtotime((string) $loan['StartMonth']));
        if ($month < $start) {
            return 0.0;
        }
        $elapsed = $this->monthsBetween($start, $month);
        if ($elapsed >= (int) $loan['TotalInstallments']) {
            return 0.0;
        }
        $outstanding = (float) $loan['PrincipalAmount'] - (float) $loan['RecoveredAmount'];
        if ($outstanding <= 0) {
            return 0.0;
        }
        return money_round(min((float) $loan['InstallmentAmount'], $outstanding));
    }

    /** Total still owed across a employee's active loans. */
    public function outstandingFor(int $empId): float
    {
        $v = $this->db->value(
            "SELECT SUM(PrincipalAmount - RecoveredAmount) FROM " . pt('loan') . "
              WHERE EmployeeID = :e AND StateID = :s",
            [':e' => $empId, ':s' => self::ACTIVE]
        );
        return (float) ($v ?? 0);
    }

    public function create(array $data, string $user): int
    {
        return $this->db->insert(pt('loan'), [
            'EmployeeID'        => (int) $data['employee_id'],
            'LoanType'          => (int) ($data['loan_type'] ?? 1),
            'Reference'         => $data['reference'] ?? null,
            'PrincipalAmount'   => money_round($data['principal'] ?? 0),
            'InstallmentAmount' => money_round($data['installment'] ?? 0),
            'StartMonth'        => date('Y-m-01', strtotime($data['start_month'] . '-01')),
            'TotalInstallments' => (int) ($data['installments'] ?? 1),
            'RecoveredAmount'   => 0,
            'StateID'           => self::ACTIVE,
            'Remarks'           => $data['remarks'] ?? null,
            'CreatedBy'         => $user,
        ]);
    }

    public function setState(int $loanId, int $state): void
    {
        $this->db->update(pt('loan'), ['StateID' => $state], 'LoanID = :id', [':id' => $loanId]);
    }

    /**
     * Record that an installment was actually deducted by a run, and close the
     * loan once fully recovered. Called only when a run is locked, so
     * recalculating a draft never double-counts recovery.
     */
    public function postInstallment(int $loanId, string $payrollMonth, float $amount, int $runId): void
    {
        $month = date('Y-m-01', strtotime(substr($payrollMonth, 0, 7) . '-01'));
        $existing = $this->db->one(
            "SELECT * FROM " . pt('loan_inst') . " WHERE LoanID = :l AND PayrollMonth = :m",
            [':l' => $loanId, ':m' => $month]
        );
        if ($existing && (int) $existing['StateID'] === 2) {
            return;   // already posted
        }
        if ($existing) {
            $this->db->update(pt('loan_inst'), [
                'Amount' => money_round($amount), 'RunID' => $runId,
                'StateID' => 2, 'PostedAt' => date('Y-m-d H:i:s'),
            ], 'InstallmentID = :id', [':id' => $existing['InstallmentID']]);
        } else {
            $this->db->insert(pt('loan_inst'), [
                'LoanID' => $loanId, 'PayrollMonth' => $month,
                'Amount' => money_round($amount), 'RunID' => $runId,
                'StateID' => 2, 'PostedAt' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->db->run(
            "UPDATE " . pt('loan') . " SET RecoveredAmount = RecoveredAmount + :a WHERE LoanID = :l",
            [':a' => money_round($amount), ':l' => $loanId]
        );
        $loan = $this->find($loanId);
        if ($loan && (float) $loan['RecoveredAmount'] >= (float) $loan['PrincipalAmount']) {
            $this->setState($loanId, self::SETTLED);
        }
    }

    public function installments(int $loanId): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('loan_inst') . " WHERE LoanID = :l ORDER BY PayrollMonth",
            [':l' => $loanId]
        );
    }

    private function monthsBetween(string $a, string $b): int
    {
        $x = new \DateTime($a);
        $y = new \DateTime($b);
        $d = $x->diff($y);
        return $d->y * 12 + $d->m;
    }
}
