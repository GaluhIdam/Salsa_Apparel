<?php
/** @var array $user @var ?array $flash */
$since = $user['created_at'] ? date('F Y', strtotime((string) $user['created_at'])) : '';
$section = field_error('_section');
?>
<section class="section section--catalog">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <span aria-current="page">My Account</span>
        </nav>
        <header class="catalog-head">
            <h1 class="section-title">My Account</h1>
            <p class="catalog-head__intro">Hello, <?= e($user['full_name']) ?>.</p>
        </header>

        <?php component('flash-message', ['flash' => $flash]); ?>

        <div class="account-grid">
            <section class="account-card" aria-labelledby="overview-title">
                <h2 id="overview-title">Overview</h2>
                <dl class="account-details">
                    <div><dt>Name</dt><dd><?= e($user['full_name']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($user['email']) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= $user['phone'] ? e($user['phone']) : '—' ?></dd></div>
                    <?php if ($since): ?><div><dt>Member since</dt><dd><?= e($since) ?></dd></div><?php endif; ?>
                </dl>
                <p class="account-links">
                    <a class="text-link" href="<?= e(url('wishlist')) ?>">My wishlist</a>
                    <a class="text-link" href="<?= e(url('cart')) ?>">Shopping bag</a>
                    <a class="text-link" href="<?= e(url('shop')) ?>">Continue shopping</a>
                </p>
                <form method="post" action="<?= e(url('logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit">Sign out</button>
                </form>
            </section>

            <section class="account-card" id="profile" aria-labelledby="profile-title">
                <h2 id="profile-title">Profile</h2>
                <form class="form-stack" method="post" action="<?= e(url('account/profile')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <?php $hasProfileState = $section === 'profile'; ?>
                    <?php component('form-field', ['name' => 'full_name', 'label' => 'Full name', 'value' => $hasProfileState ? old('full_name') : $user['full_name'], 'error' => field_error('full_name'), 'autocomplete' => 'name', 'maxlength' => 120]); ?>
                    <?php component('form-field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'value' => $hasProfileState ? old('phone') : (string) $user['phone'], 'error' => field_error('phone'), 'autocomplete' => 'tel', 'required' => false, 'inputmode' => 'tel', 'maxlength' => 30]); ?>
                    <button class="btn btn--dark" type="submit">Save changes</button>
                </form>
            </section>

            <section class="account-card" id="password" aria-labelledby="password-title">
                <h2 id="password-title">Change password</h2>
                <form class="form-stack" method="post" action="<?= e(url('account/password')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <?php component('form-field', ['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'error' => field_error('current_password'), 'autocomplete' => 'current-password']); ?>
                    <?php component('form-field', ['name' => 'new_password', 'label' => 'New password', 'type' => 'password', 'error' => field_error('new_password'), 'autocomplete' => 'new-password', 'hint' => 'At least 8 characters.']); ?>
                    <?php component('form-field', ['name' => 'new_password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'error' => field_error('new_password_confirmation'), 'autocomplete' => 'new-password']); ?>
                    <button class="btn btn--dark" type="submit">Change password</button>
                </form>
            </section>
        </div>
    </div>
</section>
