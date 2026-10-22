<?php /** @var ?array $flash */ ?>

<main class="auth-layout">
    <aside class="auth-brand">
        <div class="auth-brand__image"></div>
        <div class="auth-brand__overlay"></div>

        <a class="auth-brand__logo" href="<?= e(url('')) ?>">
            <span class="auth-brand__mark">S</span>
            Salsa Apparel
        </a>

        <div class="auth-brand__content">
            <span class="auth-brand__eyebrow">FIND YOUR SIGNATURE</span>
            <h2>Your style.<span>Your story.</span></h2>
            <p>
                Mulai perjalanan fashion anda dengan koleksi
                pilihan untuk menemani setiap aktivitas.
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
        <div class="auth-main__inner auth-main__inner--register">
            <div class="auth-heading">
                <h1>Create Account</h1>
                <p>Buat akun dan temukan gaya favoritmu.</p>
            </div>

            <?php component('flash-message', ['flash' => $flash]); ?>

            <form class="form-stack auth-form"
                  method="post"
                  action="<?= e(url('register')) ?>"
                  novalidate>
                <?= csrf_field() ?>

                <?php component('form-field', [
                    'name' => 'full_name',
                    'label' => 'Full Name',
                    'value' => old('full_name'),
                    'error' => field_error('full_name'),
                    'autocomplete' => 'name',
                    'maxlength' => 120
                ]); ?>

                <?php component('form-field', [
                    'name' => 'email',
                    'label' => 'Email Address',
                    'type' => 'email',
                    'value' => old('email'),
                    'error' => field_error('email'),
                    'autocomplete' => 'email',
                    'maxlength' => 254
                ]); ?>

                <?php component('form-field', [
                    'name' => 'phone',
                    'label' => 'Phone Number',
                    'type' => 'tel',
                    'value' => old('phone'),
                    'error' => field_error('phone'),
                    'autocomplete' => 'tel',
                    'required' => false,
                    'inputmode' => 'tel',
                    'maxlength' => 30
                ]); ?>

                <?php component('form-field', [
                    'name' => 'password',
                    'label' => 'Password',
                    'type' => 'password',
                    'error' => field_error('password'),
                    'autocomplete' => 'new-password',
                    'hint' => 'At least 8 characters.'
                ]); ?>

                <?php component('form-field', [
                    'name' => 'password_confirmation',
                    'label' => 'Confirm Password',
                    'type' => 'password',
                    'error' => field_error('password_confirmation'),
                    'autocomplete' => 'new-password'
                ]); ?>

                <button class="btn btn--accent btn--block auth-submit"
                        type="submit">
                    Create Account
                </button>
            </form>

            <p class="auth-switch">
                Sudah punya akun?
                <a class="text-link auth-link" href="<?= e(url('login')) ?>">
                    Sign In
                </a>
            </p>
        </div>
    </section>
</main>
