<?php /** @var array $addresses @var int $max @var ?array $flash */ ?>
<section class="section section--catalog">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <a href="<?= e(url('account')) ?>">My Account</a>
            <span aria-current="page">Address Book</span>
        </nav>
        <header class="catalog-head section-head">
            <h1 class="section-title">Address Book</h1>
            <?php if (count($addresses) < $max): ?>
                <a class="btn btn--dark btn--sm" href="<?= e(url('account/addresses/new')) ?>">Tambahkan alamat</a>
            <?php endif; ?>
        </header>

        <?php component('flash-message', ['flash' => $flash]); ?>

        <?php if (!$addresses): ?>
            <div class="empty-state">
                <h2>Tidak ada alamat yang terdaftar</h2>
                <p>Simpan alamat anda untuk check out lebih cepat.</p>
                <a class="btn btn--dark" href="<?= e(url('account/addresses/new')) ?>">Tambahkan alamat</a>
            </div>
        <?php else: ?>
            <ul class="address-grid">
                <?php foreach ($addresses as $a): ?>
                    <li class="address-card<?= $a['is_default'] ? ' is-default' : '' ?>">
                        <?php if ($a['is_default']): ?><span class="address-card__badge">Default</span><?php endif; ?>
                        <p class="address-card__name"><?= e($a['recipient_name']) ?></p>
                        <p><?= e($a['address_line']) ?></p>
                        <p><?= e($a['city']) ?>, <?= e($a['province']) ?> <?= e($a['postal_code']) ?></p>
                        <p class="address-card__phone"><?= e($a['phone']) ?></p>
                        <div class="address-card__actions">
                            <a class="text-link" href="<?= e(url('account/addresses/' . $a['id'] . '/edit')) ?>">Edit</a>
                            <?php if (!$a['is_default']): ?>
                                <form method="post" action="<?= e(url('account/addresses/' . $a['id'] . '/default')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="text-button" type="submit">Set as default</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('account/addresses/' . $a['id'] . '/delete')) ?>" data-confirm="Delete this address?">
                                <?= csrf_field() ?>
                                <button class="text-button" type="submit" aria-label="Delete address for <?= e($a['recipient_name']) ?>">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
