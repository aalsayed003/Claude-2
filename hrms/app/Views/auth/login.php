<div class="login-card">
    <img src="<?= url('assets/assh-logo.jpg') ?>" alt="Al Salam Specialist Hospital" class="login-logo">
    <h1>HRMS</h1>
    <p class="subtle" style="text-align:center;margin-top:-4px">Duty Roster &amp; Payroll</p>
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
    <p class="muted-note">On-premise HR &amp; payroll system · <?= e(\App\Core\Config::get('app.org')) ?></p>
</div>
<style>
  .login-logo{display:block;max-width:240px;width:72%;margin:2px auto 16px;height:auto}
  .login-card h1{text-align:center;margin:0;font-size:22px;letter-spacing:.02em;color:#0f3f6b}
</style>
