<?php /** @var string $next @var ?array $flash */ ?>
<div class="admin-login">
    <h1>Admin sign in</h1>
    <p class="admin-login__lead">Staff access only.</p>
    <?php component('flash-message', ['flash' => $flash]); ?>
    <?php if (field_error('form')): ?><p class="flash flash--error" role="alert"><?= e(field_error('form')) ?></p><?php endif; ?>
    <form class="form-stack" method="post" action="<?= e(url('admin/login')) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
        <?php component('form-field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email'), 'autocomplete' => 'username', 'maxlength' => 254]); ?>
        <?php component('form-field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password']); ?>
        <button class="btn btn--dark btn--block" type="submit">Sign in</button>
    </form>
</div>
