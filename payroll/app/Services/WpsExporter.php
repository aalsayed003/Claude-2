<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Config;
use App\Repositories\PayrollRepository;
use App\Repositories\StatutoryRepository;
use App\Repositories\SalaryHoldRepository;

/**
 * Wage Protection System / bank transfer file.
 *
 * Produces one payment line per employee from the posted register, plus a
 * header carrying the employer's identifiers and the batch totals. Every export
 * is recorded in Pay_WpsExport with a hash of the file, so a month can be shown
 * to have been paid once — re-exporting the same run is allowed, but it is
 * visible.
 *
 * Two shapes are supported:
 *   csv  — a readable column layout for review and for banks that accept CSV
 *   sif  — the fixed-field Salary Information File layout
 *
 * !! Bahraini banks differ in the exact SIF column order and header record.
 *    Confirm the layout against the paying bank's current specification and
 *    adjust sifLine()/sifHeader() before the first live upload; the structure
 *    below follows the common LMRA/CBB field set but is not bank-certified.
 */
class WpsExporter
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

    /**
     * Build the file for a run.
     *
     * @return array{filename:string,content:string,records:int,total:float,exceptions:array}
     */
    public function build(array $run): array
    {
        $month = date('Y-m-01', strtotime((string) $run['PayrollMonth']));
        $rows  = $this->payroll->register($month);
        $stat  = $this->statutory->all();
        $held  = $this->holds->activeForMonth($month);
        $cfg   = (array) Config::get('payroll.wps', []);
        $banks = [];
        foreach ($this->statutory->banks() as $b) {
            $banks[(int) $b['BankID']] = $b;
        }

        $lines = [];
        $exceptions = [];
        $total = 0.0;
        $seq = 0;

        foreach ($rows as $r) {
            $net = (float) ($r['NetPayment'] ?? 0);
            if ($net <= 0) {
                continue;   // nothing to transfer
            }
            $empId = (int) $r['Empid'];
            if (isset($held[$empId])) {
                $exceptions[] = [
                    'emp_code' => $r['emp_code'], 'name' => $r['emp_name'],
                    'amount' => $net, 'problem' => 'salary held — excluded from the file',
                ];
                continue;
            }
            $s = $stat[$empId] ?? null;
            $iban = $s['IBAN'] ?? ($r['Accno'] ?? '');
            $mode = (int) ($s['PaymentMode'] ?? ($r['Mode'] ?? 1));

            if ($mode !== 1) {
                continue;   // paid in cash / by cheque, not in the transfer file
            }
            if (!$iban) {
                $exceptions[] = [
                    'emp_code' => $r['emp_code'], 'name' => $r['emp_name'],
                    'amount' => $net, 'problem' => 'no IBAN — excluded from the file',
                ];
                continue;
            }

            $seq++;
            $total += $net;
            $bank = $banks[(int) ($s['BankID'] ?? 0)] ?? null;

            $record = [
                'seq'         => $seq,
                'emp_code'    => (string) $r['emp_code'],
                'name'        => (string) $r['emp_name'],
                'cpr'         => (string) ($s['CPR'] ?? ''),
                'lmra_id'     => (string) ($s['LmraId'] ?? ''),
                'bank_code'   => (string) ($bank['Code'] ?? ''),
                'bank_name'   => (string) ($bank['Name'] ?? ''),
                'iban'        => (string) $iban,
                'amount'      => money_round($net),
                'basic'       => money_round((float) ($r['Basicpay'] ?? 0)),
                'allowances'  => money_round((float) ($r['TotalEarnings'] ?? 0) - (float) ($r['Basicpay'] ?? 0)),
                'deductions'  => money_round((float) ($r['TotalDeduction'] ?? 0)),
                'days'        => (float) ($r['payabledays'] ?? 0),
            ];
            $lines[] = $record;
        }

        $format  = strtolower((string) ($cfg['format'] ?? 'csv'));
        $content = $format === 'sif'
            ? $this->renderSif($lines, $total, $month, $cfg)
            : $this->renderCsv($lines, $total, $month, $cfg);

        $filename = sprintf('%s_%s_%s.%s',
            $cfg['file_prefix'] ?? 'WPS',
            preg_replace('/\W+/', '', (string) ($cfg['employer_id'] ?? 'EMP')),
            date('Ym', strtotime($month)),
            $format === 'sif' ? 'sif' : 'csv'
        );

        return [
            'filename'   => $filename,
            'content'    => $content,
            'records'    => count($lines),
            'total'      => money_round($total),
            'exceptions' => $exceptions,
        ];
    }

    /** Record that a file was produced. */
    public function record(array $run, array $file, string $user): void
    {
        $this->db->insert(pt('wps_export'), [
            'RunID'        => (int) $run['RunID'],
            'PayrollMonth' => date('Y-m-01', strtotime((string) $run['PayrollMonth'])),
            'FileName'     => $file['filename'],
            'RecordCount'  => $file['records'],
            'TotalAmount'  => $file['total'],
            'FileHash'     => hash('sha256', $file['content']),
            'ExportedBy'   => $user,
        ]);
    }

    public function history(int $runId): array
    {
        try {
            return $this->db->all(
                "SELECT * FROM " . pt('wps_export') . " WHERE RunID = :r ORDER BY ExportedAt DESC",
                [':r' => $runId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -------------------------------------------------------------- renderers --

    private function renderCsv(array $lines, float $total, string $month, array $cfg): string
    {
        $out = [];
        $out[] = $this->csvRow(['Employer ID', $cfg['employer_id'] ?? '', 'Employer', $cfg['employer_name'] ?? '']);
        $out[] = $this->csvRow(['Salary Month', date('Y-m', strtotime($month)), 'Records', count($lines),
                                'Total', money_round($total), 'Currency', Config::get('payroll.currency', 'BHD')]);
        $out[] = '';
        $out[] = $this->csvRow(['Seq', 'Employee Code', 'Employee Name', 'CPR', 'LMRA ID',
                                'Bank Code', 'Bank Name', 'IBAN', 'Basic', 'Allowances',
                                'Deductions', 'Net Amount', 'Paid Days']);
        foreach ($lines as $l) {
            $out[] = $this->csvRow([
                $l['seq'], $l['emp_code'], $l['name'], $l['cpr'], $l['lmra_id'],
                $l['bank_code'], $l['bank_name'], $l['iban'],
                $l['basic'], $l['allowances'], $l['deductions'], $l['amount'], $l['days'],
            ]);
        }
        return implode("\r\n", $out) . "\r\n";
    }

    /**
     * Fixed-field SIF. Field order follows the common LMRA/CBB set:
     * record type, employer id, employee CPR, IBAN, amount, month, days.
     */
    private function renderSif(array $lines, float $total, string $month, array $cfg): string
    {
        $out = [];
        $out[] = implode(',', [
            'EDR',
            $cfg['employer_id'] ?? '',
            $cfg['employer_bank'] ?? '',
            $cfg['employer_iban'] ?? '',
            date('Ym', strtotime($month)),
            count($lines),
            number_format($total, 3, '.', ''),
            Config::get('payroll.currency', 'BHD'),
            date('Ymd'),
        ]);
        foreach ($lines as $l) {
            $out[] = implode(',', [
                'SIF',
                $l['cpr'] ?: $l['emp_code'],
                $l['bank_code'],
                $l['iban'],
                number_format((float) $l['amount'], 3, '.', ''),
                date('Ym', strtotime($month)),
                $l['days'],
            ]);
        }
        return implode("\r\n", $out) . "\r\n";
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(function ($c) {
            $c = (string) $c;
            return preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }, $cells));
    }
}
