<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<title>404 · Duty Roster</title>
<link rel="stylesheet" href="<?= url('assets/app.css') ?>"></head>
<body class="centered">
<div class="login-card" style="text-align:center">
    <h1 style="color:#c0392b">404</h1>
    <p class="subtle">No route for <code><?= e($path ?? '') ?></code>.</p>
    <a class="btn" href="<?= url('dashboard') ?>">Go to Dashboard</a>
</div>
</body></html>
