<?php
namespace App\Roster\Services;

use App\Core\Config;

/**
 * Resolves an employee's approval category (nurse / doctor / default) from
 * their department, via the config map legacy.dept_category. Shared by
 * roster-submission approval (ApprovalFlow), attendance-correction approval
 * (CorrectionFlow) and schedule-change approval (ScheduleChangeFlow) so the
 * same department always routes to the same approver chain everywhere.
 */
class RequestCategory
{
    public static function forDept(int $deptId): string
    {
        $map = Config::get('legacy.dept_category', []);
        return $map[$deptId] ?? 'default';
    }
}
