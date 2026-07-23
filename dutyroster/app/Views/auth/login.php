<div class="login-card">
    <div class="brand-mark">DR</div>
    <h1>Duty Roster</h1>
    <p class="subtle" style="text-align:center;margin-top:-2px"><?= e(\App\Core\Config::get('app.org')) ?></p>
    <form method="post" action="<?= url('login') ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label>Username</label>
            <input name="username" autofocus required>
        </div>
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" style="width:100%">Sign in</button>
    </form>
    <p class="muted-note">On-premise attendance &amp; duty-roster system.</p>
</div>
