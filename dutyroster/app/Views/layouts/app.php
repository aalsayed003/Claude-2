<?php use App\Core\Auth; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Duty Roster') ?> · <?= e(\App\Core\Config::get('app.org')) ?></title>
    <link rel="stylesheet" href="<?= url('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= url('dashboard') ?>">
        <span class="brand-mark">DR</span>
        <span><?= e(\App\Core\Config::get('app.name')) ?><small><?= e(\App\Core\Config::get('app.org')) ?></small></span>
    </a>
    <nav class="topnav">
        <a href="<?= url('dashboard') ?>">Dashboard</a>
        <?php if (Auth::atLeast('dept_head')): ?>
            <a href="<?= url('roster') ?>">Roster</a>
            <a href="<?= url('shifts') ?>">Shifts</a>
            <a href="<?= url('approvals') ?>">Approvals</a>
        <?php endif; ?>
        <a href="<?= url('attendance') ?>">Attendance</a>
        <?php if (Auth::isAdmin()): ?>
            <a href="<?= url('employees') ?>">Employees</a>
        <?php endif; ?>
    </nav>
    <div class="userbox">
        <span><?= e(Auth::user()['full_name'] ?? '') ?> <em><?= e(Auth::role()) ?></em></span>
        <a class="btn-ghost" href="<?= url('logout') ?>">Logout</a>
    </div>
</header>

<main class="container">
    <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <?= $content ?>
</main>

<footer class="foot">
    <?= e(\App\Core\Config::get('app.name')) ?> — on-premise rebuild ·
    <?= date('Y') ?> <?= e(\App\Core\Config::get('app.org')) ?>
</footer>
</body>
</html>
