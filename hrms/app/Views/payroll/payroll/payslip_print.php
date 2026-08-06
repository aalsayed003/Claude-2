<?php
use App\Core\Config;

$lines = function (string $type) use ($components, $row): array {
    $out = [];
    foreach ($components as $c) {
        if (($c['type'] ?? 'earning') !== $type) { continue; }
        $v = (float) ($row[$c['register']] ?? 0);
        if ($v != 0.0) { $out[$c['label']] = $v; }
    }
    return $out;
};
$earn = $lines('earning');
$ded  = $lines('deduction');
$rows = max(count($earn), count($ded));
$eK = array_keys($earn); $dK = array_keys($ded);
?>
<style>
    body { font-family: system-ui, "Segoe UI", Arial, sans-serif; color: #111; margin: 32px; }
    .slip { max-width: 760px; margin: 0 auto; }
    .head { display: flex; justify-content: space-between; align-items: flex-start;
            border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 18px; }
    .head h1 { font-size: 18px; margin: 0 0 2px; }
    .head .org { font-size: 13px; color: #555; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 13px; }
    .meta td { padding: 3px 0; }
    .meta .k { color: #555; width: 130px; }
    table.amt { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.amt th { text-align: left; background: #f2f2f2; padding: 6px 8px; border: 1px solid #ccc; }
    table.amt td { padding: 5px 8px; border: 1px solid #ddd; }
    table.amt td.n { text-align: right; font-variant-numeric: tabular-nums; }
    .tot td { font-weight: 700; background: #fafafa; }
    .net { margin-top: 18px; border: 2px solid #111; padding: 10px 14px;
           display: flex; justify-content: space-between; font-size: 15px; font-weight: 700; }
    .foot { margin-top: 40px; font-size: 11px; color: #666; display: flex; justify-content: space-between; }
    .sign { margin-top: 48px; font-size: 12px; display: flex; justify-content: space-between; }
    .sign div { border-top: 1px solid #999; padding-top: 4px; width: 200px; text-align: center; }
    @media print { body { margin: 0; } .noprint { display: none; } }
</style>

<div class="slip">
    <div class="head">
        <div>
            <h1>Payslip — <?= date('F Y', strtotime($month)) ?></h1>
            <div class="org"><?= e(Config::get('app.org')) ?></div>
        </div>
        <div class="org">Generated <?= date('d/m/Y') ?></div>
    </div>

    <table class="meta">
        <tr><td class="k">Employee</td><td><strong><?= e($row['emp_name'] ?? $emp['full_name']) ?></strong></td>
            <td class="k">Employee code</td><td><?= e($emp['emp_code']) ?></td></tr>
        <tr><td class="k">Department</td><td><?= e($emp['dept_name']) ?></td>
            <td class="k">Payable days</td><td><?= (float) $row['payabledays'] ?></td></tr>
        <tr><td class="k">Days attended</td><td><?= (float) $row['NoofDaysattended'] ?></td>
            <td class="k">Absent / leave</td><td><?= (float) $row['absentdays'] ?> / <?= (float) $row['LEAVE'] ?></td></tr>
    </table>

    <table class="amt">
        <thead><tr><th>Earnings</th><th style="text-align:right">Amount</th><th>Deductions</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
        <?php for ($i = 0; $i < $rows; $i++): ?>
            <tr>
                <td><?= isset($eK[$i]) ? e($eK[$i]) : '' ?></td>
                <td class="n"><?= isset($eK[$i]) ? money($earn[$eK[$i]]) : '' ?></td>
                <td><?= isset($dK[$i]) ? e($dK[$i]) : '' ?></td>
                <td class="n"><?= isset($dK[$i]) ? money($ded[$dK[$i]]) : '' ?></td>
            </tr>
        <?php endfor; ?>
            <tr class="tot">
                <td>Total earnings</td><td class="n"><?= money($row['TotalEarnings']) ?></td>
                <td>Total deductions</td><td class="n"><?= money($row['TotalDeduction']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="net">
        <span>Net payment</span>
        <span><?= e(Config::get('payroll.currency', 'BHD')) ?> <?= money($row['NetPayment']) ?></span>
    </div>

    <div class="sign">
        <div>Employee signature</div>
        <div>Finance</div>
    </div>

    <div class="foot">
        <span>This payslip is computer generated from the posted payroll register.</span>
        <span><?= e(Config::get('app.name')) ?></span>
    </div>

    <p class="noprint" style="margin-top:24px">
        <button onclick="window.print()">Print</button>
    </p>
</div>
