<?php /** @var array $adminUser */ ?>
<header class="admin-topbar">
    <p class="admin-topbar__user"><strong><?= e($adminUser['full_name']) ?></strong> <span><?= e($adminUser['role_label']) ?></span></p>
    <form method="post" action="<?= e(url('admin/logout')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--sm" type="submit">Sign out</button>
    </form>
</header>