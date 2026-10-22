
<?php
/**
 * @var string $content
 * @var ?string $pageTitle
 * @var bool $hasHero
 * @var array $navCategories
 */

$title = $pageTitle
    ? $pageTitle . ' — Salsa Apparel'
    : 'Salsa Apparel — Everyday Essentials';

// Halaman yang menggunakan layout autentikasi tanpa header/footer.
$isAuthPage = in_array(
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    [
        url('login'),
        url('register'),
        url('forgot-password'),
        url('reset-password'),
    ],
    true
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="Salsa Apparel — premium everyday fashion essentials, thoughtfully styled for every occasion.">

    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='16' fill='%238c1117'/%3E%3Ctext x='16' y='22' font-family='Georgia' font-size='18' text-anchor='middle' fill='white'%3ES%3C/text%3E%3C/svg%3E">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Instrument+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>

<body class="<?= $hasHero ? 'has-hero' : '' ?><?= $isAuthPage ? ' auth-page' : '' ?>">

<a class="skip-link" href="#main">Skip to content</a>

<?php if (!$isAuthPage): ?>
    <div class="site-top">
        <?php component('announcement-bar'); ?>
        <?php component('header', ['navCategories' => $navCategories]); ?>
    </div>
<?php endif; ?>

<main id="main"><?= $content ?></main>

<?php if (!$isAuthPage): ?>
    <?php component('footer', ['navCategories' => $navCategories]); ?>
<?php endif; ?>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>

</body>
</html>
