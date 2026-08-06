<div class="page-head"><div><h1>My Payslips</h1><p class="subtle">View or print any posted month.</p></div></div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Month</th><th class="num">Earnings</th><th class="num">Deductions</th><th class="num">Net</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($months as $m): $mp = date('Y-m', strtotime((string) $m['month'])); ?>
        <tr>
            <td><strong><?= date('F Y', strtotime((string) $m['month'])) ?></strong></td>
            <td class="num"><?= money($m['TotalEarnings']) ?></td>
            <td class="num"><?= money($m['TotalDeduction']) ?></td>
            <td class="num"><strong><?= money($m['NetPayment']) ?></strong></td>
            <td>
                <a class="btn-ghost btn-sm" href="<?= url('payroll/payslip?month=' . $mp) ?>">View</a>
                <a class="btn-ghost btn-sm" target="_blank" href="<?= url('payroll/payslip/print?month=' . $mp) ?>">Print</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$months): ?><tr><td colspan="5" class="center subtle">No payslips have been posted for you yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
