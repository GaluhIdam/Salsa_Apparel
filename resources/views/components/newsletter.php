<section class="section section--surface" id="newsletter" aria-labelledby="newsletter-title">
    <div class="container newsletter">
        <h2 id="newsletter-title">Stay in the Loop.</h2>
        <p>Be the first to discover new arrivals, collections, and updates from Salsa Apparel.</p>
        <?php component('flash-message', ['flash' => flash_get('newsletter')]); ?>
        <form class="newsletter__form" method="post" action="<?= e(url('newsletter')) ?>" novalidate data-newsletter-form>
            <?= csrf_field() ?>
            <label class="sr-only" for="newsletter-email">Email address</label>
            <input id="newsletter-email" type="email" name="email" placeholder="Your email address" autocomplete="email" required maxlength="254">
            <button class="btn btn--accent" type="submit">Subscribe</button>
        </form>
        <p class="newsletter__error" data-newsletter-error role="alert" hidden></p>
    </div>
</section>
