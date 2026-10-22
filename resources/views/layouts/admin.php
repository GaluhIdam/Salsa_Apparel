<?php
/** @var string $content @var ?string $pageTitle @var ?array $adminUser */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($pageTitle ?? 'Admin') . ' — Salsa Apparel Admin') ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='16' fill='%231f1d1b'/%3E%3Ctext x='16' y='22' font-family='Georgia' font-size='18' text-anchor='middle' fill='white'%3ES%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Instrument+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin">
<a class="skip-link" href="#admin-main">Skip to content</a>
<?php if (!empty($adminUser)): ?>
    <div class="admin-shell">
        <?php component('admin-sidebar'); ?>
        <div class="admin-body">
            <?php component('admin-topbar', ['adminUser' => $adminUser]); ?>
            <main class="admin-content" id="admin-main"><?= $content ?></main>
        </div>
    </div>
<?php else: ?>
    <main class="admin-bare" id="admin-main"><?= $content ?></main>
<?php endif; ?>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</body>
</html>