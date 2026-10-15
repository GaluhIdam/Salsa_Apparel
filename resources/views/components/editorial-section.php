<section class="section section--surface" aria-labelledby="editorial-title">
    <div class="container editorial">
        <img class="editorial__image" src="<?= e(image_url(setting('editorial_image', 'assets/images/placeholders/editorial.svg'))) ?>" alt="" loading="lazy">
        <div class="editorial__copy">
            <h2 id="editorial-title"><?= e(setting('editorial_heading', 'Everyday Pieces. Elevated Style.')) ?></h2>
            <p><?= e(setting('editorial_subheading', 'Discover versatile essentials designed to move with you, wherever the day takes you.')) ?></p>
            <a class="btn btn--dark" href="<?= e(url('shop')) ?>"><?= e(setting('editorial_cta_label', 'Explore the Collection')) ?></a>
        </div>
    </div>
</section>
