<?php
namespace App\Services;

use App\Core\Config;
use App\Repositories\StatutoryRepository;

/**
 * Social insurance (GOSI / SIO).
 *
 * Only the employee share is deducted from net pay; the employer share is
 * calculated and reported for the SIO return but never withheld — unless
 * payroll.gosi.post_employer_share is switched on, which some sites use to
 * carry the employer cost into a costing report.
 *
 * Rates come from Pay_GosiRate and are effective-dated, because Bahrain's 2024
 * reform steps the employer rate up each year. Correct the rates in that table
 * rather than here.
 */
class GosiCalculator
{
    public function __construct(private StatutoryRepository $statutory) {}

    /**
     * @param float  $contributoryWage sum of the components flagged gosi=true
     * @param array|null $stat         the employee's Pay_EmployeeStatutory row
     * @param string $onDate           payroll month start, for rate selection
     */
    public function compute(float $contributoryWage, ?array $stat, string $onDate): array
    {
        $zero = ['employee' => 0.0, 'employer' => 0.0, 'wage' => 0.0,
                 'employee_pct' => 0.0, 'employer_pct' => 0.0, 'source' => 'disabled'];

        if (!Config::get('payroll.gosi.enabled', true)) {
            return $zero;
        }
        if ($stat && (int) ($stat['ExcludeGosi'] ?? 0) === 1) {
            return array_merge($zero, ['source' => 'excluded']);
        }

        $isBahraini = (bool) (int) ($stat['IsBahraini'] ?? 0);
        $rate = $this->statutory->gosiRate($isBahraini, $onDate);

        $wage = $contributoryWage;
        if ($rate['min_wage'] !== null && $wage > 0 && $wage < $rate['min_wage']) {
            $wage = $rate['min_wage'];
        }
        if ($rate['max_wage'] !== null && $wage > $rate['max_wage']) {
            $wage = $rate['max_wage'];
        }

        return [
            'employee'     => money_round($wage * $rate['employee_pct'] / 100),
            'employer'     => money_round($wage * $rate['employer_pct'] / 100),
            'wage'         => money_round($wage),
            'employee_pct' => $rate['employee_pct'],
            'employer_pct' => $rate['employer_pct'],
            'is_bahraini'  => $isBahraini,
            'source'       => $rate['source'],
        ];
    }
}
