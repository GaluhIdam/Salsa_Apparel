<?php /** @var array $navCategories */ ?>

<section class="hero" aria-labelledby="hero-title">
    <img class="hero__image" src="<?= e(image_url('assets/images/hero/hero3.png')) ?>"
        alt="" 
        fetchpriority="high"
    >

    <div class="hero__inner">
        <div class="hero__copy">
            <h1 id="hero-title">
                <?= e(setting('hero_headline', 'Good style, good feeling.')) ?>
            </h1>

            <p>
                <?= e(setting('hero_subheadline', 'Temukan outfit favoritmu untuk setiap kesempatan.')) ?>
            </p>

            <a class="btn btn--accent" href="<?= e(url('shop')) ?>">
                <?= e(setting('hero_cta_label', 'Shop Collection')) ?>
            </a>
        </div>

        <nav class="hero__cats" aria-label="Shop by category">
            <?php foreach ($navCategories as $c): ?>
                <a href="<?= e(url('category/' . $c['slug'])) ?>">
                    <span><?= e($c['name']) ?></span>
                    <?= icon('arrow-right', 16) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>
