<?php /** @var string $next @var ?array $flash */ ?>
<section class="section section--catalog">
    <div class="container auth">
        <h1 class="section-title">Sign in</h1>
        <p class="auth__lead">Welcome back. Sign in to see your bag and wishlist on any device.</p>

        <?php component('flash-message', ['flash' => $flash]); ?>
        <?php if (field_error('form')): ?>
            <p class="flash flash--error" role="alert"><?= e(field_error('form')) ?></p>
        <?php endif; ?>

        <form class="form-stack" method="post" action="<?= e(url('login')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
            <?php component('form-field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email'), 'autocomplete' => 'email', 'maxlength' => 254]); ?>
            <?php component('form-field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password']); ?>
            <button class="btn btn--accent btn--block" type="submit">Sign in</button>
        </form>
        <p class="auth__switch">New to Salsa Apparel? <a class="text-link" href="<?= e(url('register')) ?>">Create an account</a></p>
    </div>
</section>
