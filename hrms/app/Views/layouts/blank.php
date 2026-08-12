<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Duty Roster') ?></title>
    <link rel="stylesheet" href="<?= url('assets/app.css') ?>">
    <link rel="manifest" href="<?= url('manifest.webmanifest') ?>">
    <meta name="theme-color" content="#0f3f6b">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ASSH HRMS">
    <link rel="apple-touch-icon" href="<?= url('assets/icons/apple-touch-180.png') ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= url('assets/icons/icon-192.png') ?>">
</head>
<body class="centered">
    <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
    <?= $content ?>
</body>
</html>
