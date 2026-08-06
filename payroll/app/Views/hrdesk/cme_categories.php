<div class="page-head">
    <div><h1>CME Requirement Master</h1>
        <p class="subtle">Required training hours by staff category for <?= (int) $year ?>. Each employee takes the
            category figure below, unless a per-employee override is set on the compliance screen.</p></div>
    <form method="get" action="<?= url('hr/cme/categories') ?>" class="inline">
        <div class="field"><label>Year</label>
            <select name="year" onchange="this.form.submit()">
                <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === (int) $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select></div>
    </form>
</div>

<div class="card" style="padding:10px 14px"><div class="actions">
    <a class="btn-ghost btn-sm" href="<?= url('hr/leave') ?>">Leave requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/requests') ?>">HR requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme') ?>">CME compliance</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme/categories') ?>">CME requirement master</a>
</div></div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Category</th><th class="num">Employees</th><th class="num">Required hours (<?= (int) $year ?>)</th><th style="min-width:260px">Set</th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
        <tr>
            <td><strong><?= e($c['name']) ?></strong> <span class="subtle">#<?= (int) $c['id'] ?></span></td>
            <td class="num"><?= (int) $c['headcount'] ?></td>
            <td class="num">
                <?php if ($c['required'] !== null): ?>
                    <strong><?= number_format($c['required'], 1) ?></strong>
                <?php else: ?>
                    <span class="subtle">default <?= number_format($c['default'], 1) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" action="<?= url('hr/cme/categories/save') ?>" class="inline" style="gap:6px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="year" value="<?= (int) $year ?>">
                    <input name="name" value="<?= e($c['name']) ?>" placeholder="label" style="width:140px">
                    <input type="number" step="0.5" min="0" name="hours"
                           value="<?= $c['required'] !== null ? $c['required'] : $c['default'] ?>" style="width:90px">
                    <button class="btn-sm" type="submit">Save</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$categories): ?>
        <tr><td colspan="4" class="center subtle">No categories found. Add a
            <code>payroll.staff_categories</code> map in config, or set employee categories in the master.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<p class="muted-note">
    Resolution order for the required hours: per-employee override → this category requirement →
    the global default (<?= number_format((float) \App\Core\Config::get('payroll.cme.required_hours_per_year', 50), 0) ?> hrs).
    Categories come from <code>payroll.staff_categories</code>, or the <code>CategoryID</code> values found on employees.
</p>
