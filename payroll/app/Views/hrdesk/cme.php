<div class="page-head">
    <div><h1>Training (CME) Compliance</h1><p class="subtle">Required vs completed hours for <?= (int) $year ?>.</p></div>
    <form method="get" action="<?= url('hr/cme') ?>" class="inline">
        <div class="field"><label>Year</label>
            <select name="year" onchange="this.form.submit()">
                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === (int) $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select></div>
        <div class="field" style="min-width:200px"><label>Department</label>
            <select name="department_id" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= $deptId == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
    </form>
</div>
<div class="card" style="padding:10px 14px"><div class="actions">
    <a class="btn-ghost btn-sm" href="<?= url('hr/leave') ?>">Leave requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/requests') ?>">HR requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme') ?>">CME compliance</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme/categories') ?>">CME requirement master</a>
</div></div>

<?php if ($pending): ?>
<div class="card">
    <h2 class="panel-title">Activities awaiting verification — <?= count($pending) ?></h2>
    <table class="tbl">
        <thead><tr><th>Employee</th><th>Activity</th><th>Provider</th><th class="num">Hours</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $a): ?>
            <tr>
                <td><?= e($a['emp_code'] . ' · ' . $a['emp_name']) ?></td>
                <td><?= e($a['Title']) ?></td>
                <td class="subtle"><?= e($a['Provider']) ?></td>
                <td class="num"><?= number_format((float) $a['Hours'], 1) ?></td>
                <td class="subtle"><?= $a['ActivityDate'] ? date('d/m/Y', strtotime((string) $a['ActivityDate'])) : '' ?></td>
                <td>
                    <form method="post" action="<?= url('hr/cme/verify') ?>" class="inline" style="gap:6px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="activity_id" value="<?= (int) $a['ActivityID'] ?>">
                        <input type="hidden" name="year" value="<?= (int) $year ?>">
                        <button class="btn-ok btn-sm" name="decision" value="verify">Verify</button>
                        <button class="btn-muted btn-sm" name="decision" value="reject">Reject</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Category</th>
        <th class="num">Required</th><th class="num">Completed</th><th class="num">%</th><th style="min-width:170px">Override</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['emp_code']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td class="subtle"><?= e($r['dept_name']) ?></td>
            <td class="subtle"><?= e($r['category']) ?></td>
            <td class="num"><?= number_format($r['required'], 1) ?>
                <span class="subtle" title="source of this target"><?= $r['req_source'] === 'category' ? '(cat)' : ($r['req_source'] === 'employee' ? '(emp)' : '(def)') ?></span></td>
            <td class="num"><?= number_format($r['recorded'], 1) ?>
                <?php if ($r['verified'] < $r['recorded']): ?><span class="subtle">(<?= number_format($r['verified'], 1) ?> verified)</span><?php endif; ?></td>
            <td class="num"><span class="chip <?= $r['pct'] >= 100 ? 'present' : 'pending' ?>"><?= (int) $r['pct'] ?>%</span></td>
            <td>
                <form method="post" action="<?= url('hr/cme/require') ?>" class="inline" style="gap:6px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="employee_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="year" value="<?= (int) $year ?>">
                    <input type="number" step="0.5" name="hours" value="<?= $r['required'] ?>" style="width:80px">
                    <button class="btn-sm" type="submit">Set</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="center subtle">No employees.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<p class="muted-note">Default requirement is <?= number_format($defaultReq, 0) ?> hours/year; set a per-employee figure above. Completed counts recorded hours; verified hours are confirmed by HR.</p>
