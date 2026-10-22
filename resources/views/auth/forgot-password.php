<?php /** @var ?array $flash @var bool $devMail */ ?>

<main class="auth-layout">
    <aside class="auth-brand">
        <div class="auth-brand__image"></div>
        <div class="auth-brand__overlay"></div>

        <a class="auth-brand__logo" href="<?= e(url('')) ?>">
            <span class="auth-brand__mark">S</span>
            Salsa Apparel
        </a>

        <div class="auth-brand__content">
            <span class="auth-brand__eyebrow">WE'VE GOT YOU</span>
            <h2>A fresh start.<span>A new password.</span></h2>
            <p>
                Jangan khawatir. Pulihkan akses akun anda dan
                kembali menikmati koleksi favorit Salsa Apparel.
            </p>
            <div class="auth-brand__caption">
                <span class="auth-brand__caption-line"></span>
                Kami disini untuk membantu.
            </div>
        </div>

        <span class="auth-brand__copyright">
            © <?= date('Y') ?> Salsa Apparel
        </span>
    </aside>

    <section class="auth-main">
        <div class="auth-main__inner">
            <a class="auth-back" href="<?= e(url('login')) ?>">
                <span aria-hidden="true">←</span>
                Back to Sign In
            </a>

            <div class="auth-heading">
                <h1>Lupa Password?</h1>
                <p>
                    Masukkan email anda yang terdaftar. Kami akan
                    mengirimkan tautan untuk membuat password baru.
                </p>
            </div>

            <?php component('flash-message', ['flash' => $flash]); ?>

            <?php if ($devMail): ?>
                <p class="flash flash--info" role="status">
                    Development mode: email tidak dikirim.
                    Pesan ditulis ke
                    <code>storage/logs/mail.log</code>.
                </p>
            <?php endif; ?>

            <form class="form-stack auth-form" method="post" action="<?= e(url('forgot-password')) ?>" novalidate>
                <?= csrf_field() ?>

                <?php component('form-field', [
                    'name' => 'email',
                    'label' => 'Email Address',
                    'type' => 'email',
                    'autocomplete' => 'email',
                    'maxlength' => 254
                ]); ?>

                <button class="btn btn--accent btn--block auth-submit" type="submit">
                    Send Reset Link
                </button>
            </form>

            <p class="auth-switch">
                Ingat password anda?
                <a class="text-link auth-link" href="<?= e(url('login')) ?>">
                    Sign In
                </a>
            </p>
        </div>
    </section>
</main>
