<?php /** @var ?array $flash */ ?>
<?php if (!empty($flash)): ?>
    <p class="flash flash--<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>
