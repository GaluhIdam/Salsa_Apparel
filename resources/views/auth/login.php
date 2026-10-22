<?php /** @var string $next @var ?array $flash */ ?>

<main class="auth-layout">
    <aside class="auth-brand">
        <div class="auth-brand__image"></div>
        <div class="auth-brand__overlay"></div>

        <a class="auth-brand__logo" href="<?= e(url('')) ?>">
            <span class="auth-brand__mark">S</span>
            Salsa Apparel
        </a>

        <div class="auth-brand__content">
            <span class="auth-brand__eyebrow">YOUR EVERYDAY STYLE</span>
            <h2>Good style.<span>Good feeling.</span></h2>
            <p>
                Selamat datang kembali. Temukan fashion yang
                mencerminkan gaya dan kepribadianmu.
            </p>
            <div class="auth-brand__caption">
                <span class="auth-brand__caption-line"></span>
                Everyday Looks, Made Better.
            </div>
        </div>

        <span class="auth-brand__copyright">
            © <?= date('Y') ?> Salsa Apparel
        </span>
    </aside>

    <section class="auth-main">
        <div class="auth-main__inner">
            <div class="auth-heading">
                <h1>Welcome Back</h1>
                <p>Masuk ke akun lu dan lanjutkan belanja.</p>
            </div>

            <?php component('flash-message', ['flash' => $flash]); ?>

            <?php if (field_error('form')): ?>
                <p class="flash flash--error" role="alert">
                    <?= e(field_error('form')) ?>
                </p>
            <?php endif; ?>

            <form class="form-stack auth-form"
                    method="post"
                    action="<?= e(url('login')) ?>"
                    novalidate>
                <?= csrf_field() ?>

                <?php if ($next !== ''): ?>
                    <input type="hidden" name="next"
                        value="<?= e($next) ?>">
                <?php endif; ?>

                <?php component('form-field', [
                    'name' => 'email',
                    'label' => 'Email Address',
                    'type' => 'email',
                    'value' => old('email'),
                    'autocomplete' => 'email',
                    'maxlength' => 254
                ]); ?>

                <?php component('form-field', [
                    'name' => 'password',
                    'label' => 'Password',
                    'type' => 'password',
                    'autocomplete' => 'current-password'
                ]); ?>

                <div class="auth-form__row">
                    <span></span>
                    <a class="text-link auth-link" href="<?= e(url('forgot-password')) ?>">
                        Lupa password?
                    </a>
                </div>

                <button class="btn btn--accent btn--block auth-submit"
                        type="submit">
                    Sign In
                </button>
            </form>

            <div class="auth-divider">NEW TO SALSA APPAREL?</div>

            <p class="auth-switch">
                Belum punya akun?
                <a class="text-link auth-link" href="<?= e(url('register')) ?>">
                    Buat akun
                </a>
            </p>
        </div>
    </section>
</main>
