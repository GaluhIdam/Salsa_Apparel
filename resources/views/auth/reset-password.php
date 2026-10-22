<?php /** @var ?array $flash */ ?>
<section class="section section--catalog">
    <div class="container auth">
        <h1 class="section-title">Choose a new password</h1>
        <?php component('flash-message', ['flash' => $flash]); ?>
        <form class="form-stack" method="post" action="<?= e(url('reset-password')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php component('form-field', ['name' => 'new_password', 'label' => 'New password', 'type' => 'password', 'error' => field_error('new_password'), 'autocomplete' => 'new-password', 'hint' => 'At least 8 characters.']); ?>
            <?php component('form-field', ['name' => 'new_password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'error' => field_error('new_password_confirmation'), 'autocomplete' => 'new-password']); ?>
            <button class="btn btn--accent btn--block" type="submit">Reset password</button>
        </form>
    </div>
</section>
