
<?php
/**
 * Variables:
 * $title, $description, $formContent
 */
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — Salsa Apparel</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>">
</head>
<body class="auth-page">

    <main class="auth-layout">
        <aside class="auth-brand">
            <div class="auth-brand__image"></div>
            <div class="auth-brand__overlay"></div>

            <a href="<?= e(url('')) ?>" class="auth-brand__logo">
                <span class="auth-brand__mark">S</span>
                <span>Salsa Apparel</span>
            </a>

            <div class="auth-brand__content">
                <span class="auth-brand__eyebrow">YOUR EVERYDAY STYLE</span>

                <h2>
                    Dress the way
                    <span>you feel.</span>
                </h2>

                <p>
                    Temukan gaya yang mencerminkan diri lu.
                    Pilihan fashion untuk setiap cerita dan setiap hari.
                </p>

                <div class="auth-brand__caption">
                    <span class="auth-brand__caption-line"></span>
                    <span>Everyday Looks, Made Better.</span>
                </div>
            </div>

            <span class="auth-brand__copyright">
                © <?= date('Y') ?> Salsa Apparel
            </span>
        </aside>

        <section class="auth-main">
            <div class="auth-main__inner">
                <?= $formContent ?>
            </div>
        </section>
    </main>

</body>
</html>
