<?php
/** @var ?array $address @var string $action @var string $heading @var ?array $flash */
$hasState = old('recipient_name') !== '' || field_error('recipient_name') !== null || field_error('form') !== null || field_error('postal_code') !== null;
$v = static fn(string $key): string => $hasState ? old($key) : ($address !== null ? (string) $address[$key] : '');
$isDefault = $hasState ? old('is_default') === '1' : ($address !== null && $address['is_default']);
$editingDefault = $address !== null && $address['is_default'];
?>
<section class="section section--catalog">
    <div class="container auth">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('account')) ?>">My Account</a>
            <a href="<?= e(url('account/addresses')) ?>">Address Book</a>
            <span aria-current="page"><?= e($heading) ?></span>
        </nav>
        <h1 class="section-title"><?= e($heading) ?></h1>

        <?php component('flash-message', ['flash' => $flash]); ?>
        <?php if (field_error('form')): ?><p class="flash flash--error" role="alert"><?= e(field_error('form')) ?></p><?php endif; ?>

        <form class="form-stack" method="post" action="<?= e(url($action)) ?>" novalidate>
            <?= csrf_field() ?>
            <?php component('form-field', ['name' => 'recipient_name', 'label' => 'Nama Penerima', 'value' => $v('recipient_name'), 'error' => field_error('recipient_name'), 'autocomplete' => 'name', 'maxlength' => 120]); ?>
            <?php component('form-field', ['name' => 'phone', 'label' => 'Nomor Handphone', 'type' => 'tel', 'value' => $v('phone'), 'error' => field_error('phone'), 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 30]); ?>
            <?php component('form-field', ['name' => 'address_line', 'label' => 'Alamat Jalan', 'value' => $v('address_line'), 'error' => field_error('address_line'), 'autocomplete' => 'street-address', 'maxlength' => 255]); ?>
            <?php component('form-field', ['name' => 'city', 'label' => 'Kota', 'value' => $v('city'), 'error' => field_error('city'), 'autocomplete' => 'address-level2', 'maxlength' => 100]); ?>
            <?php component('form-field', ['name' => 'province', 'label' => 'Provisi', 'value' => $v('province'), 'error' => field_error('province'), 'autocomplete' => 'address-level1', 'maxlength' => 100]); ?>
            <?php component('form-field', ['name' => 'postal_code', 'label' => 'Kode Pos', 'value' => $v('postal_code'), 'error' => field_error('postal_code'), 'autocomplete' => 'postal-code', 'inputmode' => 'numeric', 'maxlength' => 5]); ?>
            <label class="check">
                <input type="checkbox" name="is_default" value="1" <?= $isDefault ? 'checked' : '' ?> <?= $editingDefault ? 'disabled' : '' ?>>
                <span><?= $editingDefault ? 'This is your default address' : 'Gunakan sebagai alamat utama' ?></span>
            </label>
            <button class="btn btn--accent btn--block" type="submit">Save address</button>
        </form>
    </div>
</section>
