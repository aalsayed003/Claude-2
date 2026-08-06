<?php
namespace App\Payroll\Services;

use App\Core\Config;

/**
 * The end-of-service indemnity (gratuity) formula, in one place.
 *
 * Both the leaver settlement (SettlementCalculator) and the balance-sheet
 * provision (IndemnityProvisionRepository) accrue on exactly these rules, so a
 * staff member's provision always reconciles to what they would actually be
 * paid on the same day.
 *
 * Classic Bahrain Labour Law basis, every number configurable under
 * payroll.indemnity:
 *   - 15 days' wage for each of the first 3 years of service, and
 *   - 30 days' wage for each year after that,
 *   - part years pro-rated,
 *   - on a chosen wage basis (gross or basic), divided by the month's days.
 *
 * !! For staff whose service spans Bahrain's 2024 SIO end-of-service reform,
 *    part of the post-reform accrual may already be funded by monthly SIO
 *    contributions. This gives the gross entitlement and does not net that off.
 */
class IndemnityCalculator
{
    /** Indemnity days earned for a length of service in years. */
    public static function days(float $years, ?array $cfg = null): float
    {
        $cfg = $cfg ?? (array) Config::get('payroll.indemnity', []);
        $tierYears = (float) ($cfg['first_tier_years'] ?? 3);
        $firstRate = (float) ($cfg['days_first_tier'] ?? 15);
        $laterRate = (float) ($cfg['days_after_tier'] ?? 30);
        $prorate   = !isset($cfg['prorate_part_years']) || $cfg['prorate_part_years'];

        $counted = $prorate ? $years : floor($years);
        $first   = min($counted, $tierYears);
        $rest    = max(0.0, $counted - $tierYears);

        return $first * $firstRate + $rest * $laterRate;
    }

    /** Length of service in years (inclusive of both end days). */
    public static function serviceYears(string $from, string $to): float
    {
        $a = new \DateTime($from);
        $b = new \DateTime($to);
        return ((int) $a->diff($b)->days + 1) / 365.25;
    }

    /** "5 years 2 months 3 days" style label. */
    public static function serviceText(string $from, string $to): string
    {
        $d = (new \DateTime($from))->diff(new \DateTime($to));
        return sprintf('%dy %dm %dd', $d->y, $d->m, $d->d);
    }

    /**
     * Accrued indemnity for a wage and a length of service.
     * @return array{days:float, day_rate:float, amount:float}
     */
    public static function accrue(float $monthlyWage, float $years, ?array $cfg = null): array
    {
        $cfg     = $cfg ?? (array) Config::get('payroll.indemnity', []);
        $days    = self::days($years, $cfg);
        $divisor = (float) Config::get('payroll.fixed_month_days', 30);
        $dayRate = $divisor > 0 ? $monthlyWage / $divisor : 0.0;

        return [
            'days'     => round($days, 2),
            'day_rate' => money_round($dayRate),
            'amount'   => money_round($days * $dayRate),
        ];
    }
}
