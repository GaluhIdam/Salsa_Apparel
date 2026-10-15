<?php /** @var string $heading @var string $message */ ?>
<section class="section status-page">
    <div class="container">
        <h1><?= e($heading) ?></h1>
        <p><?= e($message) ?></p>
        <a class="btn btn--dark" href="<?= e(url('/')) ?>">Back to home</a>
    </div>
</section>
