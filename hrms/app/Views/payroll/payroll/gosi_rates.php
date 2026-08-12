<?php
$cats = ['bahraini' => 'Bahraini', 'retiree' => 'Bahraini retiree', 'expat' => 'Expat'];
$fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
?>
<div class="page-head"><div><h1>GOSI / SIO Rates</h1>
    <p class="subtle">Effective-dated. Adding a rate applies it to payroll months on or after its date —
        months already paid keep the rate that was in force then.</p></div></div>

<div class="card">
    <h2 class="panel-title">Add / change a rate</h2>
    <form method="post" action="<?= url('payroll/gosi-rates/save') ?>">
        <?= csrf_field() ?>
        <div class="gr-form">
            <div class="field"><label>Category</label>
                <select name="category" required>
                    <?php foreach ($cats as $k => $lbl): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field"><label>Effective from</label><input type="date" name="effective_from" required></div>
            <div class="field"><label>Social insurance % (employee)</label><input name="social_emp_pct" type="number" step="0.001" value="0"></div>
            <div class="field"><label>Unemployment % (employee)</label><input name="unemp_emp_pct" type="number" step="0.001" value="0"></div>
            <div class="field"><label>Social insurance % (employer)</label><input name="social_er_pct" type="number" step="0.001" value="0"></div>
            <div class="field"><label>Unemployment % (employer)</label><input name="unemp_er_pct" type="number" step="0.001" value="0"></div>
            <div class="field"><label>Min wage</label><input name="min_wage" type="number" step="0.001" value="0"></div>
            <div class="field"><label>Max wage (cap)</label><input name="max_wage" type="number" step="0.001" value="4000"></div>
            <div class="field grow"><label>Notes</label><input name="notes" placeholder="e.g. SIO circular ref"></div>
        </div>
        <button type="submit" class="btn-primary">Save rate</button>
    </form>
    <p class="muted-note">Reference (employee): Bahraini 7% SI + 1% unemployment · Bahraini retiree 1% SI · Expat 1% SI.</p>
</div>

<?php foreach ($cats as $key => $label):
    $rows = array_values(array_filter($rates, fn($r) => ($r['Category'] ?? ($r['IsBahraini'] ? 'bahraini' : 'expat')) === $key)); ?>
<div class="card">
    <h2 class="panel-title"><?= e($label) ?></h2>
    <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Effective from</th>
            <th class="num">SI % emp</th><th class="num">Unemp % emp</th>
            <th class="num">SI % er</th><th class="num">Unemp % er</th>
            <th class="num">Total emp</th><th class="num">Min</th><th class="num">Max</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r):
            $totalEmp = $r['EmployeePct'] ?? ((float)($r['SocialEmpPct'] ?? 0) + (float)($r['UnempEmpPct'] ?? 0)); ?>
            <tr<?= $i === 0 ? ' style="font-weight:600;background:#f2f8fe"' : '' ?>>
                <td><?= e(date('d M Y', strtotime((string) $r['EffectiveFrom']))) ?><?= $i === 0 ? ' <span class="chip present">current</span>' : '' ?></td>
                <td class="num"><?= $fmt($r['SocialEmpPct'] ?? 0) ?></td>
                <td class="num"><?= $fmt($r['UnempEmpPct'] ?? 0) ?></td>
                <td class="num"><?= $fmt($r['SocialErPct'] ?? 0) ?></td>
                <td class="num"><?= $fmt($r['UnempErPct'] ?? 0) ?></td>
                <td class="num"><?= $fmt($totalEmp) ?></td>
                <td class="num"><?= $r['MinWage'] !== null ? $fmt($r['MinWage']) : '—' ?></td>
                <td class="num"><?= $r['MaxWage'] !== null ? $fmt($r['MaxWage']) : '—' ?></td>
                <td class="subtle"><?= e($r['Notes'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="center subtle">No rates for this category yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endforeach; ?>

<style>
  .gr-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:10px}
  .gr-form .field{display:flex;flex-direction:column;gap:4px}
  .gr-form .field.grow{grid-column:1/-1}
  .gr-form label{font-size:12px;color:#6b7787}
  .gr-form input,.gr-form select{padding:7px 9px;border:1px solid #cdd6e2;border-radius:8px;font-size:14px}
  .btn-primary{padding:9px 18px;background:#137fc4;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer}
</style>
