<?php /** @var ?array $flash */ ?>
<section class="section section--catalog">
    <div class="container auth">
        <h1 class="section-title">Create account</h1>
        <p class="auth__lead">Save your bag and wishlist, and check out faster next time.</p>

        <?php component('flash-message', ['flash' => $flash]); ?>

        <form class="form-stack" method="post" action="<?= e(url('register')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php component('form-field', ['name' => 'full_name', 'label' => 'Full name', 'value' => old('full_name'), 'error' => field_error('full_name'), 'autocomplete' => 'name', 'maxlength' => 120]); ?>
            <?php component('form-field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email'), 'error' => field_error('email'), 'autocomplete' => 'email', 'maxlength' => 254]); ?>
            <?php component('form-field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'value' => old('phone'), 'error' => field_error('phone'), 'autocomplete' => 'tel', 'required' => false, 'inputmode' => 'tel', 'maxlength' => 30]); ?>
            <?php component('form-field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'error' => field_error('password'), 'autocomplete' => 'new-password', 'hint' => 'At least 8 characters.']); ?>
            <?php component('form-field', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'error' => field_error('password_confirmation'), 'autocomplete' => 'new-password']); ?>
            <button class="btn btn--accent btn--block" type="submit">Create account</button>
        </form>
        <p class="auth__switch">Already have an account? <a class="text-link" href="<?= e(url('login')) ?>">Sign in</a></p>
    </div>
</section>
