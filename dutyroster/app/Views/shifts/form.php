<div class="page-head"><div><h1><?= e($title) ?></h1></div>
    <a class="btn btn-muted" href="<?= url('shifts') ?>">← Back</a></div>
<div class="card" style="max-width:640px">
<form method="post" action="<?= url('shifts/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($shift['id'] ?? '') ?>">
    <div class="inline">
        <div class="field" style="flex:1"><label>Shift Code *</label>
            <input name="code" value="<?= e($shift['code'] ?? '') ?>" required placeholder="e.g. ST54"></div>
        <div class="field" style="flex:2"><label>Name</label>
            <input name="name" value="<?= e($shift['name'] ?? '') ?>" placeholder="Description (optional)"></div>
    </div>
    <h2>Timings <small class="subtle">— leave the second pair blank for a single shift</small></h2>
    <div class="inline">
        <div class="field"><label>First In</label><input type="time" name="first_in" value="<?= e(substr((string)($shift['first_in']??''),0,5)) ?>"></div>
        <div class="field"><label>First Out</label><input type="time" name="first_out" value="<?= e(substr((string)($shift['first_out']??''),0,5)) ?>"></div>
        <div class="field"><label>Second In</label><input type="time" name="second_in" value="<?= e(substr((string)($shift['second_in']??''),0,5)) ?>"></div>
        <div class="field"><label>Second Out</label><input type="time" name="second_out" value="<?= e(substr((string)($shift['second_out']??''),0,5)) ?>"></div>
    </div>
    <div class="inline" style="margin:12px 0">
        <label class="checkbox"><input type="checkbox" name="is_day_off" value="1" <?= !empty($shift['is_day_off'])?'checked':'' ?>> This is a DAY OFF</label>
        <label class="checkbox"><input type="checkbox" name="is_holiday" value="1" <?= !empty($shift['is_holiday'])?'checked':'' ?>> This is a PUBLIC HOLIDAY</label>
    </div>
    <p class="subtle">Total hours and night-shift detection are computed automatically on save.</p>
    <button type="submit">Save Shift</button>
</form>
</div>
