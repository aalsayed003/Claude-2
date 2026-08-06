<?php
use App\Payroll\Repositories\SalaryStructureRepository as SS;
$curGross = SS::grossOf($current);
?>
<div class="page-head">
    <div>
        <h1>Salary Increment — <?= e($emp['full_name']) ?></h1>
        <p class="subtle"><?= e($emp['emp_code']) ?> · <?= e($emp['dept_name']) ?>
            <?php if ($current): ?>· current gross <?= money($curGross) ?>
                (from <?= date('M Y', strtotime((string) $current['CurrentMonth'])) ?>)<?php endif; ?>
        </p>
    </div>
    <a class="btn-ghost btn-sm" href="<?= url('payroll/structure?employee_id=' . (int) $emp['id']) ?>">Full structure</a>
</div>

<?php if (!$current): ?>
    <div class="card subtle">No current structure to increment.
        <a href="<?= url('payroll/structure?employee_id=' . (int) $emp['id']) ?>">Enter one first.</a></div>
<?php else: ?>

<form method="post" action="<?= url('payroll/increment/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">

    <div class="card">
        <h2 class="panel-title">Increment</h2>
        <div class="inline">
            <div class="field"><label>Effective from</label>
                <input type="month" name="effective_month" value="<?= e($month) ?>" required></div>
            <div class="field"><label>Method</label>
                <select name="mode" id="mode" onchange="document.getElementById('pctRow').style.display=this.value==='percent'?'':'none';document.getElementById('amtRow').style.display=this.value==='amount'?'':'none';">
                    <option value="percent">Percentage of ticked components</option>
                    <option value="amount">Flat amount on Basic</option>
                    <option value="manual">Type new figures</option>
                </select></div>
            <div class="field" id="pctRow"><label>Percent %</label>
                <input type="number" step="0.01" name="percent" value="5" style="width:90px"></div>
            <div class="field" id="amtRow" style="display:none"><label>Amount on basic</label>
                <input type="number" step="0.001" name="amount" style="width:110px"></div>
        </div>

        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th class="center">Raise</th><th>Component</th><th class="num">Current</th><th class="num">New (if manual)</th></tr></thead>
            <tbody>
            <?php foreach ($components as $key => $c): $v = (float) ($current[$c['structure']] ?? 0); ?>
                <tr>
                    <td class="center"><input type="checkbox" name="apply[<?= e($key) ?>]" value="1"
                        <?= in_array($key, ['basic'], true) ? 'checked' : '' ?>></td>
                    <td><?= e($c['label']) ?></td>
                    <td class="num"><?= $v ? money($v) : '' ?></td>
                    <td class="num"><input type="number" step="0.001" name="c[<?= e($key) ?>]"
                        value="<?= $v ?: '' ?>" style="width:120px;text-align:right"></td>
                </tr>
            <?php endforeach; ?>
            <tr><td colspan="2"><strong>Current gross</strong></td>
                <td class="num"><strong><?= money($curGross) ?></strong></td><td></td></tr>
            </tbody>
        </table>
        </div>
        <p class="muted-note">
            Percentage mode raises only the ticked components. Flat-amount mode adds to Basic only.
            Manual mode uses the figures you type in the last column. Applying creates a new
            effective-dated structure row; earlier months are untouched.
        </p>
        <div class="actions"><button type="submit">Apply increment</button></div>
    </div>
</form>

<?php if ($history): ?>
<div class="card">
    <h2 class="panel-title">Recent structures</h2>
    <table class="tbl">
        <thead><tr><th>Effective</th><th class="num">Basic</th><th class="num">Gross</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td><?= date('M Y', strtotime((string) $h['CurrentMonth'])) ?></td>
                <td class="num"><?= money(SS::basicOf($h)) ?></td>
                <td class="num"><?= money(SS::grossOf($h)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php endif; ?>
