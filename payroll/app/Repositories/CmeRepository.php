<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Continuing Medical Education / training hours (Pay_CmeActivity +
 * Pay_CmeRequirement).
 *
 * Each employee has a required number of hours for a year (a per-employee
 * override, else the configured default) and logs activities toward it. An
 * activity is Recorded, then Verified by HR. Progress = verified (or recorded)
 * hours ÷ required.
 */
class CmeRepository
{
    public const RECORDED = 1, VERIFIED = 2, REJECTED = 9;
    public const STATE_LABELS = [self::RECORDED => 'Recorded', self::VERIFIED => 'Verified', self::REJECTED => 'Rejected'];

    public function __construct(private Database $db) {}

    /**
     * Required hours for an employee/year, resolved in order:
     *   1. per-employee override (Pay_CmeRequirement)
     *   2. their staff category's requirement (Pay_CmeCategoryRequirement)
     *   3. the configured global default
     */
    public function requiredHours(int $empId, int $year): float
    {
        $v = $this->db->value(
            "SELECT RequiredHours FROM " . pt('cme_req') . " WHERE EmployeeID = :e AND Year = :y",
            [':e' => $empId, ':y' => $year]);
        if ($v !== null) {
            return (float) $v;
        }
        $catId = $this->db->value("SELECT CategoryID FROM " . lt('employee') . " WHERE ID = :e", [':e' => $empId]);
        if ($catId !== null) {
            $cv = $this->db->value(
                "SELECT RequiredHours FROM " . pt('cme_cat_req') . " WHERE CategoryID = :c AND Year = :y",
                [':c' => (int) $catId, ':y' => $year]);
            if ($cv !== null) {
                return (float) $cv;
            }
        }
        return (float) Config::get('payroll.cme.required_hours_per_year', 50);
    }

    // ---------------------------------------------- category requirement master

    /** Category requirements for a year, keyed by CategoryID. */
    public function categoryRequirements(int $year): array
    {
        $map = [];
        try {
            foreach ($this->db->all(
                "SELECT * FROM " . pt('cme_cat_req') . " WHERE Year = :y", [':y' => $year]) as $r) {
                $map[(int) $r['CategoryID']] = $r;
            }
        } catch (\Throwable $e) {}
        return $map;
    }

    public function setCategoryRequired(int $catId, ?string $name, int $year, float $hours, string $user): void
    {
        $exists = (int) $this->db->value(
            "SELECT COUNT(*) FROM " . pt('cme_cat_req') . " WHERE CategoryID = :c AND Year = :y",
            [':c' => $catId, ':y' => $year]);
        if ($exists) {
            $this->db->update(pt('cme_cat_req'),
                ['RequiredHours' => $hours, 'CategoryName' => $name, 'SetBy' => $user, 'SetAt' => date('Y-m-d H:i:s')],
                'CategoryID = :c AND Year = :y', [':c' => $catId, ':y' => $year]);
        } else {
            $this->db->insert(pt('cme_cat_req'),
                ['CategoryID' => $catId, 'CategoryName' => $name, 'Year' => $year, 'RequiredHours' => $hours, 'SetBy' => $user]);
        }
    }

    /**
     * Staff categories for the master screen: the configured id=>name map, or
     * whatever CategoryID values actually appear on employees. Each row is
     * enriched with the requirement + headcount for the year.
     */
    public function categories(int $year): array
    {
        $configured = (array) Config::get('payroll.staff_categories', []);
        $reqs  = $this->categoryRequirements($year);
        $default = (float) Config::get('payroll.cme.required_hours_per_year', 50);

        $counts = [];
        try {
            foreach ($this->db->all(
                "SELECT CategoryID AS id, COUNT(*) AS n FROM " . lt('employee') . "
                  WHERE Deleted = 0 AND CategoryID IS NOT NULL GROUP BY CategoryID") as $r) {
                $counts[(int) $r['id']] = (int) $r['n'];
            }
        } catch (\Throwable $e) {}

        $ids = $configured ? array_keys($configured) : array_keys($counts);
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $req = $reqs[$id] ?? null;
            $out[] = [
                'id'       => $id,
                'name'     => $configured[$id] ?? ($req['CategoryName'] ?? ('Category ' . $id)),
                'required' => $req !== null ? (float) $req['RequiredHours'] : null,
                'default'  => $default,
                'headcount'=> $counts[$id] ?? 0,
            ];
        }
        return $out;
    }

    public function setRequired(int $empId, int $year, float $hours, string $user): void
    {
        $exists = (int) $this->db->value(
            "SELECT COUNT(*) FROM " . pt('cme_req') . " WHERE EmployeeID = :e AND Year = :y",
            [':e' => $empId, ':y' => $year]);
        if ($exists) {
            $this->db->update(pt('cme_req'), ['RequiredHours' => $hours, 'SetBy' => $user, 'SetAt' => date('Y-m-d H:i:s')],
                'EmployeeID = :e AND Year = :y', [':e' => $empId, ':y' => $year]);
        } else {
            $this->db->insert(pt('cme_req'), ['EmployeeID' => $empId, 'Year' => $year, 'RequiredHours' => $hours, 'SetBy' => $user]);
        }
    }

    public function activities(int $empId, int $year): array
    {
        return $this->db->all(
            "SELECT * FROM " . pt('cme_activity') . " WHERE EmployeeID = :e AND Year = :y ORDER BY ActivityDate DESC, ActivityID DESC",
            [':e' => $empId, ':y' => $year]);
    }

    public function addActivity(array $d): int
    {
        return $this->db->insert(pt('cme_activity'), [
            'EmployeeID'   => (int) $d['employee_id'],
            'Year'         => (int) $d['year'],
            'Title'        => $d['title'],
            'Provider'     => $d['provider'] ?? null,
            'Hours'        => (float) $d['hours'],
            'ActivityDate' => $d['activity_date'] ?: null,
            'StateID'      => self::RECORDED,
        ]);
    }

    /** Completed hours = verified; if none verified yet, count recorded so the bar isn't empty. */
    public function completedHours(int $empId, int $year): array
    {
        $verified = (float) ($this->db->value(
            "SELECT SUM(Hours) FROM " . pt('cme_activity') . " WHERE EmployeeID = :e AND Year = :y AND StateID = :s",
            [':e' => $empId, ':y' => $year, ':s' => self::VERIFIED]) ?? 0);
        $recorded = (float) ($this->db->value(
            "SELECT SUM(Hours) FROM " . pt('cme_activity') . " WHERE EmployeeID = :e AND Year = :y AND StateID <> :r",
            [':e' => $empId, ':y' => $year, ':r' => self::REJECTED]) ?? 0);
        return ['verified' => $verified, 'recorded' => $recorded];
    }

    /** Recorded-but-unverified activities across staff, for the HR desk. */
    public function pendingActivities(int $year): array
    {
        $emp = lt('employee');
        return $this->db->all(
            "SELECT a.*, e.EmpCode AS emp_code, e.Name AS emp_name
               FROM " . pt('cme_activity') . " a
               JOIN {$emp} e ON e.ID = a.EmployeeID
              WHERE a.Year = :y AND a.StateID = :s ORDER BY a.CreatedAt",
            [':y' => $year, ':s' => self::RECORDED]);
    }

    public function setState(int $activityId, int $state): void
    {
        $this->db->update(pt('cme_activity'), ['StateID' => $state], 'ActivityID = :id', [':id' => $activityId]);
    }

    public function findActivity(int $id): ?array
    {
        return $this->db->one("SELECT * FROM " . pt('cme_activity') . " WHERE ActivityID = :id", [':id' => $id]);
    }

    /** Compliance overview for a year: every employee's required vs completed. */
    public function overview(int $year, ?int $departmentId = null): array
    {
        $emp = lt('employee'); $dep = lt('department');
        $params = [':y' => $year];
        $where = '';
        if ($departmentId) { $where = ' AND e.DepartmentId = :d'; $params[':d'] = $departmentId; }

        $default = (float) Config::get('payroll.cme.required_hours_per_year', 50);
        $rows = $this->db->all(
            "SELECT e.ID AS id, e.EmpCode AS emp_code, e.Name AS full_name, d.Name AS dept_name,
                    e.CategoryID AS cat_id,
                    req.RequiredHours AS emp_req,
                    catreq.RequiredHours AS cat_req,
                    (SELECT SUM(a.Hours) FROM " . pt('cme_activity') . " a
                      WHERE a.EmployeeID = e.ID AND a.Year = :y AND a.StateID = 2) AS verified,
                    (SELECT SUM(a.Hours) FROM " . pt('cme_activity') . " a
                      WHERE a.EmployeeID = e.ID AND a.Year = :y AND a.StateID <> 9) AS recorded
               FROM {$emp} e
               LEFT JOIN {$dep} d ON d.Id = e.DepartmentId
               LEFT JOIN " . pt('cme_req') . " req ON req.EmployeeID = e.ID AND req.Year = :y
               LEFT JOIN " . pt('cme_cat_req') . " catreq ON catreq.CategoryID = e.CategoryID AND catreq.Year = :y
              WHERE e.Deleted = 0 {$where}
              ORDER BY d.Name, e.Name",
            $params);

        $cats = (array) Config::get('payroll.staff_categories', []);
        foreach ($rows as &$r) {
            // per-employee override, else category requirement, else default
            $r['required'] = $r['emp_req'] !== null ? (float) $r['emp_req']
                           : ($r['cat_req'] !== null ? (float) $r['cat_req'] : $default);
            $r['req_source'] = $r['emp_req'] !== null ? 'employee'
                             : ($r['cat_req'] !== null ? 'category' : 'default');
            $r['category'] = $cats[(int) $r['cat_id']] ?? ($r['cat_id'] !== null ? 'Cat ' . (int) $r['cat_id'] : '');
            $r['verified'] = (float) ($r['verified'] ?? 0);
            $r['recorded'] = (float) ($r['recorded'] ?? 0);
            $r['pct']      = $r['required'] > 0 ? min(100, round($r['recorded'] / $r['required'] * 100)) : 0;
        }
        unset($r);
        return $rows;
    }
}
