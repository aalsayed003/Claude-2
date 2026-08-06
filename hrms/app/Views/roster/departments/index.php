<div class="page-head"><div><h1>Departments &amp; Sections</h1></div></div>
<div class="grid" style="grid-template-columns:1fr 1fr">
    <div class="card">
        <h2 style="margin-top:0">Departments</h2>
        <form method="post" action="<?= url('departments/save') ?>" class="inline" style="margin-bottom:14px">
            <?= csrf_field() ?>
            <div class="field" style="flex:2"><input name="name" placeholder="Department name" required></div>
            <div class="field" style="flex:1"><input name="code" placeholder="Code"></div>
            <button type="submit">Add</button>
        </form>
        <table class="tbl"><thead><tr><th>Name</th><th>Code</th></tr></thead><tbody>
            <?php foreach ($depts as $d): ?><tr><td><?= e($d['name']) ?></td><td><?= e($d['code']) ?></td></tr><?php endforeach; ?>
            <?php if (!$depts): ?><tr><td colspan="2" class="subtle center">None yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <div class="card">
        <h2 style="margin-top:0">Sections</h2>
        <form method="post" action="<?= url('sections/save') ?>" class="inline" style="margin-bottom:14px">
            <?= csrf_field() ?>
            <div class="field" style="flex:1"><select name="department_id" required>
                <option value="">Department…</option>
                <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="field" style="flex:1"><input name="name" placeholder="Section name" required></div>
            <button type="submit">Add</button>
        </form>
        <table class="tbl"><thead><tr><th>Section</th><th>Department</th></tr></thead><tbody>
            <?php foreach ($sections as $s): ?><tr><td><?= e($s['name']) ?></td><td class="subtle"><?= e($s['dept_name']) ?></td></tr><?php endforeach; ?>
            <?php if (!$sections): ?><tr><td colspan="2" class="subtle center">None yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>
