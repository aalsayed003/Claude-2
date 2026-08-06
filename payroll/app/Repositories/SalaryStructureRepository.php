<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * The salary structure — legacy `CurrentDetails`, one row per employee per
 * month. Rows are effective-dated: the structure that applies to a payroll
 * month is the most recent row whose CurrentMonth is on or before it, so a
 * raise is recorded by inserting a new row rather than editing history.
 *
 * Which columns exist is driven entirely by payroll.components in config, so
 * adding an allowance is a config change plus (if it is new) a column.
 */
class SalaryStructureRepository
{
    public function __construct(private Database $db) {}

    /** Components that have a stored structure column, in config order. */
    public static function components(): array
    {
        $out = [];
        foreach ((array) Config::get('payroll.components', []) as $key => $c) {
            if (!empty($c['structure'])) {
                $out[$key] = $c;
            }
        }
        return $out;
    }

    /** The structure in force for one employee in a payroll month. */
    public function effectiveFor(int $empId, string $payrollMonth): ?array
    {
        $t = $this->q(pt('structure'));
        return $this->db->one(
            $this->db->limit(
                "SELECT * FROM {$t}
                  WHERE Empid = :e AND {$this->q('CurrentMonth')} <= :m
                    AND (Deleted = 0 OR Deleted IS NULL)
                  ORDER BY {$this->q('CurrentMonth')} DESC", 1),
            [':e' => $empId, ':m' => $this->monthStart($payrollMonth)]
        );
    }

    /**
     * Effective structures for every employee in one query, keyed by Empid.
     * Avoids a per-employee round trip during a payroll run.
     */
    public function effectiveForAll(string $payrollMonth): array
    {
        $t = $this->q(pt('structure'));
        $rows = $this->db->all(
            "SELECT * FROM (
                SELECT s.*, ROW_NUMBER() OVER (PARTITION BY s.Empid
                       ORDER BY s.{$this->q('CurrentMonth')} DESC) AS rn
                  FROM {$t} s
                 WHERE s.{$this->q('CurrentMonth')} <= :m
                   AND (s.Deleted = 0 OR s.Deleted IS NULL)
             ) x WHERE x.rn = 1",
            [':m' => $this->monthStart($payrollMonth)]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['Empid']] = $r;
        }
        return $map;
    }

    /** Every structure row for an employee, newest first. */
    public function history(int $empId, int $limit = 60): array
    {
        $t = $this->q(pt('structure'));
        return $this->db->all(
            $this->db->limit(
                "SELECT * FROM {$t} WHERE Empid = :e AND (Deleted = 0 OR Deleted IS NULL)
                  ORDER BY {$this->q('CurrentMonth')} DESC", $limit),
            [':e' => $empId]
        );
    }

    /**
     * Insert or replace the structure row effective from $payrollMonth.
     * $values is keyed by component key (basic, hra, …).
     */
    public function save(int $empId, string $payrollMonth, array $values, int $operatorId): void
    {
        $t     = $this->q(pt('structure'));
        $month = $this->monthStart($payrollMonth);

        $data = [];
        $gross = 0.0;
        foreach (self::components() as $key => $c) {
            $amount = money_round($values[$key] ?? 0);
            $data[$c['structure']] = $amount;
            if (($c['type'] ?? 'earning') === 'earning') {
                $gross += $amount;
            }
        }
        $data['GrossSalary'] = money_round($gross);
        $data['Deleted']     = 0;
        $data['OperatorID']  = $operatorId;

        $exists = (int) $this->db->value(
            "SELECT COUNT(*) FROM {$t} WHERE Empid = :e AND {$this->q('CurrentMonth')} = :m",
            [':e' => $empId, ':m' => $month]
        );

        $params = [];
        foreach ($data as $c => $v) {
            $params[':p_' . $this->param($c)] = $v;
        }
        $params[':e'] = $empId;
        $params[':m'] = $month;

        if ($exists > 0) {
            $set = [];
            foreach (array_keys($data) as $c) {
                $set[] = $this->q($c) . ' = :p_' . $this->param($c);
            }
            $this->db->run(
                "UPDATE {$t} SET " . implode(', ', $set) .
                " WHERE Empid = :e AND {$this->q('CurrentMonth')} = :m",
                $params
            );
            return;
        }

        $cols  = array_merge(['Empid', 'CurrentMonth'], array_keys($data));
        $place = array_merge([':e', ':m'], array_map(fn($c) => ':p_' . $this->param($c), array_keys($data)));
        $this->db->run(
            "INSERT INTO {$t} (" . implode(', ', array_map([$this, 'q'], $cols)) . ")
             VALUES (" . implode(', ', $place) . ")",
            $params
        );
    }

    /** Gross of a structure row, from the earning components in config. */
    public static function grossOf(?array $row): float
    {
        if (!$row) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (self::components() as $c) {
            if (($c['type'] ?? 'earning') === 'earning') {
                $sum += (float) ($row[$c['structure']] ?? 0);
            }
        }
        return $sum;
    }

    /** Basic pay of a structure row. */
    public static function basicOf(?array $row): float
    {
        $comp = Config::get('payroll.components.basic.structure', 'BasicSalary');
        return (float) ($row[$comp] ?? 0);
    }

    private function monthStart(string $m): string
    {
        return date('Y-m-01', strtotime(substr($m, 0, 7) . '-01'));
    }

    private function param(string $col): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $col);
    }

    private function q(string $ident): string
    {
        return $this->db->driver() === 'mysql' ? "`{$ident}`" : "[{$ident}]";
    }
}
