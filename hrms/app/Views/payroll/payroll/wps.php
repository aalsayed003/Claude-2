<div class="page-head">
    <div>
        <h1>Bank file</h1>
        <p class="subtle"><?= date('F Y', strtotime((string) $run['PayrollMonth'])) ?> ·
            <?= e(strtoupper((string) \App\Core\Config::get('payroll.wps.format', 'csv'))) ?> format</p>
    </div>
    <a class="btn-ghost btn-sm" href="<?= url('payroll/run?id=' . $run['RunID']) ?>">Back to run</a>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Payment lines</span><strong><?= (int) $file['records'] ?></strong></div>
    <div class="tile"><span class="subtle">Total to transfer</span><strong><?= money($file['total']) ?></strong>
        <span class="subtle"><?= e(\App\Core\Config::get('payroll.currency', 'BHD')) ?></span></div>
    <div class="tile"><span class="subtle">Excluded</span>
        <strong><?= count($file['exceptions']) ?></strong>
        <span class="subtle">no IBAN</span></div>
</div>

<?php if (!\App\Core\Config::get('payroll.wps.employer_id')): ?>
    <div class="flash flash-error">
        No employer ID is configured under <code>payroll.wps</code>. The file will be produced with a
        blank employer identifier and the bank will reject it — set it in config first.
    </div>
<?php endif; ?>

<?php if ($file['exceptions']): ?>
<div class="card">
    <h2 class="panel-title">Excluded from the file</h2>
    <p class="subtle">These employees have a net payment but no IBAN on record, so they are not in the transfer.</p>
    <table class="tbl">
        <thead><tr><th>Code</th><th>Employee</th><th class="num">Net</th><th>Problem</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($file['exceptions'] as $x): ?>
            <tr><td><?= e($x['emp_code']) ?></td><td><?= e($x['name']) ?></td>
                <td class="num"><?= money($x['amount']) ?></td>
                <td><?= e($x['problem']) ?></td>
                <td><a class="btn-ghost btn-sm" href="<?= url('payroll/structures?q=' . urlencode((string) $x['emp_code'])) ?>">Fix</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="panel-title">Preview — first lines</h2>
    <pre style="overflow-x:auto;font-size:12px;background:#fafafa;padding:12px;border:1px solid #e5e5e5"><?php
        $preview = array_slice(explode("\r\n", $file['content']), 0, 12);
        echo e(implode("\n", $preview));
        echo count(explode("\r\n", $file['content'])) > 12 ? "\n…" : '';
    ?></pre>
    <div class="actions">
        <a class="btn" href="<?= url('payroll/wps?id=' . $run['RunID'] . '&confirm=1') ?>">Download <?= e($file['filename']) ?></a>
    </div>
    <p class="muted-note">
        Downloading records the export against this run with a hash of the file contents.
        Confirm the layout against the paying bank's current specification before the first live upload.
    </p>
</div>

<?php if ($history): ?>
<div class="card">
    <h2 class="panel-title">Previously exported</h2>
    <table class="tbl">
        <thead><tr><th>File</th><th class="num">Records</th><th class="num">Amount</th><th>By</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td><?= e($h['FileName']) ?></td>
                <td class="num"><?= (int) $h['RecordCount'] ?></td>
                <td class="num"><?= money($h['TotalAmount']) ?></td>
                <td><?= e($h['ExportedBy']) ?></td>
                <td class="subtle"><?= date('d/m/Y H:i', strtotime((string) $h['ExportedAt'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
