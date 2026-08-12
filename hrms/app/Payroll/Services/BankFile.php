<?php
namespace App\Payroll\Services;

use App\Core\Database;
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Repositories\SalaryHoldRepository;

/**
 * ASSH "Bank of Payment" register and per-bank transfer files.
 *
 * From the posted register it builds one payment line per employee, identifies
 * the bank from the IBAN (chars 5-8 of a Bahrain IBAN), truncates the net to
 * fils (ROUNDDOWN), and runs the QA checks the manual sheet does by hand:
 *   - IBAN present, correct length, and matching the HR master
 *   - bank resolvable from the IBAN
 *   - name within the bank field limit
 *   - computed net reconciles with earnings − deductions
 * Valid lines are grouped into a transfer file per bank (BBK, KHCB, …); anything
 * that fails a hard check is listed as an exception and kept out of the files.
 */
class BankFile
{
    private PayrollRepository $payroll;
    private StatutoryRepository $statutory;
    private SalaryHoldRepository $holds;

    public function __construct(private Database $db)
    {
        $this->payroll   = new PayrollRepository($db);
        $this->statutory = new StatutoryRepository($db);
        $this->holds     = new SalaryHoldRepository($db);
    }

    /** The 4-char bank identifier embedded in a Bahrain IBAN. */
    public function ibanBankCode(string $iban): string
    {
        $cfg   = (array) Config::get('payroll.bank', []);
        $start = (int) ($cfg['iban_code_start'] ?? 5) - 1;
        $len   = (int) ($cfg['iban_code_len'] ?? 4);
        return strtoupper(substr(preg_replace('/\s+/', '', $iban), $start, $len));
    }

    /**
     * Full register with a QA verdict per row.
     * @return array{month:string,rows:array,valid:int,exceptions:array,totals:array}
     */
    public function register(array $run): array
    {
        $month = date('Y-m-01', strtotime((string) $run['PayrollMonth']));
        $cfg   = (array) Config::get('payroll.bank', []);
        $ibanLen = (int) ($cfg['iban_length'] ?? 22);
        $nameMax = (int) ($cfg['name_max'] ?? 30);
        $codes   = (array) ($cfg['codes'] ?? []);
        $down    = ($cfg['net_round'] ?? 'down') === 'down';

        $rows = $this->payroll->register($month);
        $stat = $this->statutory->all();
        $held = $this->holds->activeForMonth($month);

        $out = []; $exceptions = []; $valid = 0; $seq = 0;
        $tGross = $tDed = $tNet = 0.0;

        foreach ($rows as $r) {
            $empId = (int) $r['Empid'];
            $s     = $stat[$empId] ?? [];
            $name  = trim((string) ($r['emp_name'] ?? ''));
            $net   = (float) ($r['NetPayment'] ?? 0);
            $gross = (float) ($r['TotalEarnings'] ?? 0);
            $ded   = (float) ($r['TotalDeduction'] ?? 0);
            $iban  = trim((string) ($s['IBAN'] ?? ($r['Accno'] ?? '')));
            $mode  = (int) ($s['PaymentMode'] ?? ($r['Mode'] ?? 1));
            $code  = $iban !== '' ? $this->ibanBankCode($iban) : '';
            $bank  = $codes[$code] ?? null;
            $payNet = $down ? money_floor($net) : money_round($net);

            // QA verdicts
            $qa = [
                'iban_present'  => $iban !== '',
                'iban_len_ok'   => strlen($iban) === $ibanLen,
                'iban_matches'  => $iban !== '' && $iban === trim((string) ($s['IBAN'] ?? $iban)),
                'bank_known'    => $bank !== null,
                'name_ok'       => $name !== '' && mb_strlen($name) <= $nameMax,
                'net_positive'  => $net > 0,
                'reconciles'    => abs(money_round($net) - money_round($gross - $ded)) <= 0.0005,
                'transfer_mode' => $mode === 1,
                'not_held'      => !isset($held[$empId]),
            ];
            // hard checks that keep a line OUT of the transfer file
            $hard = ['iban_present', 'iban_len_ok', 'bank_known', 'net_positive', 'transfer_mode', 'not_held'];
            $problems = [];
            foreach ($hard as $k) {
                if (!$qa[$k]) $problems[] = $this->reason($k);
            }

            $row = [
                'seq'        => 0,
                'emp_id'     => $empId,
                'emp_code'   => (string) ($r['emp_code'] ?? ''),
                'name'       => $name,
                'iban'       => $iban,
                'iban_code'  => $code,
                'bank_name'  => $bank['name'] ?? '',
                'file_group' => $bank['file'] ?? '',
                'gross'      => money_round($gross),
                'deductions' => money_round($ded),
                'net'        => $payNet,
                'name_len'   => mb_strlen($name),
                'qa'         => $qa,
                'problems'   => $problems,
                'valid'      => $problems === [],
            ];

            if ($problems === []) {
                $row['seq'] = ++$seq;
                $valid++;
                $tGross += $gross; $tDed += $ded; $tNet += $payNet;
            } else {
                $exceptions[] = ['emp_code' => $row['emp_code'], 'name' => $name,
                                 'net' => $payNet, 'problem' => implode('; ', $problems)];
            }
            $out[] = $row;
        }

        return [
            'month'      => $month,
            'rows'       => $out,
            'valid'      => $valid,
            'exceptions' => $exceptions,
            'totals'     => ['gross' => money_round($tGross), 'deductions' => money_round($tDed), 'net' => money_round($tNet)],
        ];
    }

    /**
     * Valid lines grouped into a transfer file per bank.
     * @return array<string,array{group:string,bank_name:string,rows:array,total:float,count:int,filename:string,content:string}>
     */
    public function transferFiles(array $run): array
    {
        $reg    = $this->register($run);
        $month  = $reg['month'];
        $groups = [];
        foreach ($reg['rows'] as $r) {
            if (!$r['valid']) continue;
            $g = $r['file_group'] ?: $r['iban_code'];
            $groups[$g]['group']     = $g;
            $groups[$g]['bank_name'] = $r['bank_name'];
            $groups[$g]['rows'][]    = $r;
        }
        foreach ($groups as $g => &$grp) {
            $lines = [];
            $total = 0.0; $i = 0;
            $lines[] = $this->csvRow(['SL no.', 'IBAN No.', 'Employee Name', 'Net Pay']);
            foreach ($grp['rows'] as $r) {
                $lines[] = $this->csvRow([++$i, $r['iban'], $r['name'], number_format($r['net'], (int) Config::get('payroll.decimals', 3), '.', '')]);
                $total += $r['net'];
            }
            $grp['count']    = $i;
            $grp['total']    = money_round($total);
            $grp['filename'] = sprintf('%s_Transfer_%s.csv', $g, date('Ym', strtotime($month)));
            $grp['content']  = implode("\r\n", $lines) . "\r\n";
        }
        unset($grp);
        ksort($groups);
        return $groups;
    }

    private function reason(string $key): string
    {
        return [
            'iban_present'  => 'no IBAN / bank account',
            'iban_len_ok'   => 'IBAN wrong length',
            'bank_known'    => 'bank not recognised from IBAN',
            'net_positive'  => 'net is zero or negative',
            'transfer_mode' => 'not paid by bank transfer',
            'not_held'      => 'salary held',
        ][$key] ?? $key;
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(function ($c) {
            $c = (string) $c;
            return preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }, $cells));
    }
}
