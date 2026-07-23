<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Duty Roster') ?></title>
    <link rel="stylesheet" href="<?= url('assets/app.css') ?>">
</head>
<body class="centered">
    <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
    <?= $content ?>
</body>
</html>
