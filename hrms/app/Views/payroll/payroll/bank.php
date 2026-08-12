<?php
$reg = $reg ?? ['rows' => [], 'valid' => 0, 'exceptions' => [], 'totals' => ['net' => 0]];
$dp  = (int) \App\Core\Config::get('payroll.decimals', 3);
?>
<div class="page-head">
    <div><h1>Bank of Payment</h1>
        <p class="subtle"><?= e(date('F Y', strtotime((string) $run['PayrollMonth']))) ?> ·
            <strong><?= (int) $reg['valid'] ?></strong> payable ·
            net <strong><?= money($reg['totals']['net']) ?></strong> ·
            <?= count($reg['exceptions']) ?> exception(s)</p></div>
    <a class="btn-ghost btn-sm" href="<?= url('payroll/wps?id=' . (int) $run['RunID']) ?>">WPS / SIF file →</a>
</div>

<!-- transfer files per bank -->
<div class="card">
    <h2 class="panel-title">Transfer files by bank</h2>
    <?php if ($files): ?>
    <div class="bankfiles">
        <?php foreach ($files as $g => $f): ?>
        <div class="bf">
            <div class="bf-h"><strong><?= e($g) ?></strong><span class="subtle"><?= e($f['bank_name']) ?></span></div>
            <div class="bf-n"><?= (int) $f['count'] ?> staff · <?= money($f['total']) ?></div>
            <a class="btn btn-sm" href="<?= url('payroll/bank/file?id=' . (int) $run['RunID'] . '&group=' . rawurlencode((string) $g)) ?>">Download CSV</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?><p class="subtle">No valid payment lines — resolve the exceptions below.</p><?php endif; ?>
</div>

<?php if ($reg['exceptions']): ?>
<div class="card">
    <h2 class="panel-title" style="color:#c0392b">Exceptions — held out of the files</h2>
    <div class="tbl-wrap"><table class="tbl tbl--cards">
        <thead><tr><th>Code</th><th>Name</th><th class="num">Net</th><th>Problem</th></tr></thead>
        <tbody>
        <?php foreach ($reg['exceptions'] as $x): ?>
            <tr><td data-label="Code"><?= e($x['emp_code']) ?></td>
                <td data-label="Name"><?= e($x['name']) ?></td>
                <td data-label="Net" class="num"><?= money($x['net']) ?></td>
                <td data-label="Problem" style="color:#c0392b"><?= e($x['problem']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<!-- full QA register -->
<div class="card">
    <h2 class="panel-title">Register &amp; validation</h2>
    <div class="tbl-wrap"><table class="tbl tbl--cards">
        <thead><tr><th>#</th><th>Name</th><th>IBAN</th><th>Bank</th><th class="num">Net</th>
            <th>IBAN&nbsp;len</th><th>Bank&nbsp;ok</th><th>Name&nbsp;len</th><th>Net=E−D</th><th>Status</th></tr></thead>
        <tbody>
        <?php $tick = fn($b) => $b ? '<span style="color:#2e7d32">✓</span>' : '<span style="color:#c0392b">✗</span>'; ?>
        <?php foreach ($reg['rows'] as $r): $qa = $r['qa']; ?>
            <tr>
                <td data-label="#"><?= $r['seq'] ?: '—' ?></td>
                <td data-label="Name"><?= e($r['name']) ?></td>
                <td data-label="IBAN" style="font-family:monospace;font-size:12px"><?= e($r['iban'] ?: '—') ?></td>
                <td data-label="Bank"><?= e($r['file_group'] ?: ($r['iban_code'] ?: '—')) ?></td>
                <td data-label="Net" class="num"><?= number_format($r['net'], $dp) ?></td>
                <td data-label="IBAN len"><?= $tick($qa['iban_len_ok']) ?> <?= (int) strlen($r['iban']) ?></td>
                <td data-label="Bank ok"><?= $tick($qa['bank_known']) ?></td>
                <td data-label="Name len"><?= $tick($qa['name_ok']) ?> <?= (int) $r['name_len'] ?></td>
                <td data-label="Net=E−D"><?= $tick($qa['reconciles']) ?></td>
                <td data-label="Status"><?= $r['valid']
                    ? '<span class="chip present">OK</span>'
                    : '<span class="chip rejected">HOLD</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$reg['rows']): ?><tr><td colspan="10" class="center subtle">Nothing posted for this month.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<style>
  .bankfiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
  .bf{border:1px solid #e4e9f1;border-radius:10px;padding:12px 14px;background:#fbfcfe}
  .bf-h{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
  .bf-h strong{font-size:16px;color:#0f3f6b}
  .bf-n{font-size:13px;color:#33414f;margin:6px 0 10px}
</style>
