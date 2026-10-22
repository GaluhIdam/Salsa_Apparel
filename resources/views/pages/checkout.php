<?php
/** @var array $cart */
/** @var ?array $flash */
/** @var array $addresses */
/** @var ?array $defaultAddress */
/** @var ?array $user */

$addresses = $addresses ?? [];
$defaultAddress = $defaultAddress ?? null;
$user = $user ?? null;

$selectedAddress = $defaultAddress ?? [];

$contactEmail = $user['email'] ?? '';

$items = $cart['items'] ?? [];
$canCheckout = !empty($items);

foreach ($items as $item) {
    if (empty($item['available'])) {
        $canCheckout = false;
        break;
    }
}
?>

<section class="section section--catalog checkout-page">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <a href="<?= e(url('cart')) ?>">Shopping Bag</a>
            <span aria-current="page">Checkout</span>
        </nav>

        <header class="catalog-head">
            <p class="eyebrow">SALSA APPAREL / YOUR ORDER</p>
            <h1 class="section-title">Checkout</h1>
            <p class="catalog-head__intro">
                Complete your delivery details before placing your order.
            </p>
        </header>

        <?php component('flash-message', ['flash' => $flash]); ?>

        <?php if (!$items): ?>
            <div class="empty-state">
                <h2>Your bag is empty</h2>
                <p>Add your favourite pieces before proceeding to checkout.</p>
                <a class="btn btn--dark" href="<?= e(url('shop')) ?>">
                    Explore collection
                </a>
            </div>
        <?php else: ?>
            <div class="checkout-layout">
                <div class="checkout-form-panel">
                    <form method="post" action="<?= e(url('checkout')) ?>">
                        <?= csrf_field() ?>

                        <fieldset class="checkout-fieldset">
                            <legend>01 — Contact information</legend>

                            <label class="form-field">
                                <span>Email address</span>
                                    <input
                                        type="email"
                                        name="email"
                                        value="<?= e($contactEmail) ?>"
                                        autocomplete="email"
                                        maxlength="254"
                                        required
                                    >
                            </label>
                        </fieldset>

                        <fieldset class="checkout-fieldset">
                            <legend>02 — Shipping address</legend>
                            <?php if (!empty($addresses)): ?>
                                <label class="form-field">
                                    <span>Pilih alamat tersimpan</span>
                                    <select name="saved_address_id" id="savedAddress">
                                        <?php foreach ($addresses as $address): ?>
                                            <?php
                                            $isSelected = (string) ($address['id'] ?? '') ===
                                                (string) ($selectedAddress['id'] ?? '');
                                            ?>
                                            <option
                                                value="<?= (int) $address['id'] ?>"
                                                data-recipient="<?= e($address['recipient_name']) ?>"
                                                data-phone="<?= e($address['phone']) ?>"
                                                data-address="<?= e($address['address_line']) ?>"
                                                data-city="<?= e($address['city']) ?>"
                                                data-province="<?= e($address['province']) ?>"
                                                data-postal-code="<?= e($address['postal_code']) ?>"
                                                <?= $isSelected ? 'selected' : '' ?>
                                            >
                                                <?= e($address['recipient_name']) ?>
                                                — <?= e($address['city']) ?>
                                                <?= !empty($address['is_default']) ? '(Default)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>

                                        <option value="">Isi alamat secara manual</option>
                                    </select>
                                </label>
                            <?php endif; ?>
                            
                            <label class="form-field">
                                <span>Nama Penerima</span>
                                    <input
                                        type="text"
                                        id="recipientName"
                                        name="recipient_name"
                                        value="<?= e($selectedAddress['recipient_name'] ?? $user['full_name'] ?? '') ?>"
                                        autocomplete="name"
                                        maxlength="120"
                                        required
                                    >
                            </label>

                            <label class="form-field">
                                <span>Nomor Handphone</span>
                                    <input
                                        type="tel"
                                        id="recipientPhone"
                                        name="recipient_phone"
                                        value="<?= e($selectedAddress['phone'] ?? $user['phone'] ?? '') ?>"
                                        autocomplete="tel"
                                        maxlength="30"
                                        required
                                    >
                            </label>

                            <label class="form-field">
                                <span>Alamat Rumah</span>
                                    <textarea
                                        name="address"
                                        id="shippingAddress"
                                        autocomplete="street-address"
                                        required
                                    ><?= e($selectedAddress['address_line'] ?? '') ?></textarea>
                            </label>

                            <div class="checkout-fields-row">
                                <label class="form-field">
                                    <span>Kota</span>
                                    <input
                                        type="text"
                                        id="shippingCity"
                                        name="city"
                                        autocomplete="address-level2"
                                        maxlength="100"
                                        required
                                        value="<?= e($selectedAddress['city'] ?? '') ?>"
                                    >
                                </label>

                                <label class="form-field">
                                    <span>Provisi</span>
                                    <input
                                        type="text"
                                        id="shippingProvince"
                                        name="province"
                                        autocomplete="address-level1"
                                        maxlength="100"
                                        required
                                        value="<?= e($selectedAddress['province'] ?? '') ?>"
                                    >
                                </label>
                            </div>

                            <label class="form-field">
                                <span>Kode Pos</span>
                                <input
                                    type="text"
                                    id="shippingPostalCode"
                                    name="postal_code"
                                    autocomplete="postal-code"
                                    maxlength="10"
                                    required
                                    value="<?= e($selectedAddress['postal_code'] ?? '') ?>"
                                >
                            </label>
                        </fieldset>

                        <fieldset class="checkout-fieldset">
                            <legend>03 — Delivery method</legend>

                            <label class="form-field">
                                <span>Kurir</span>
                                <select name="courier" required>
                                    <option value="">Pilih Kurir</option>
                                    <option value="JNE">JNE</option>
                                    <option value="J&T">J&amp;T Express</option>
                                    <option value="SiCepat">SiCepat</option>
                                </select>
                            </label>

                            <label class="form-field">
                                <span>Shipping service</span>
                                <select name="service" required>
                                    <option value="">Choose service</option>
                                    <option value="Regular">Regular</option>
                                    <option value="Express">Express</option>
                                </select>
                            </label>

                            <p class="checkout-note">
                                Biaya pengiriman belum dihitung.
                                Layanan yang dipilih tidak mencerminkan tarif kurir yang sudah pasti.
                            </p>
                        </fieldset>

                        <p class="checkout-note">
                                Pembayaran belum tersedia.
                                Jangan kirimkan formulir ini sampai konfirmasi pesanan dan pemrosesan pembayaran sudah diaktifkan.
                        </p>

                        <button
                            class="btn btn--dark btn--block"
                            type="submit"
                        >
                            Checkout
                        </button>
                    </form>
                </div>

                <aside class="cart-summary checkout-summary">
                    <h2>Order summary</h2>

                    <ul class="checkout-summary__items">
                        <?php foreach ($items as $item): ?>
                            <li class="checkout-summary__item">
                                <div>
                                    <strong><?= e($item['name']) ?></strong>
                                    <p>
                                        <?= e($item['color']) ?> /
                                        <?= e($item['size']) ?>
                                        · Qty <?= (int) $item['quantity'] ?>
                                    </p>

                                    <?php if (empty($item['available'])): ?>
                                        <p class="cart-item__warn">
                                            <?= e($item['reason'] ?? 'This item is unavailable.') ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <span>
                                    <?= !empty($item['available'])
                                        ? e(rupiah($item['line_total']))
                                        : '—' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <dl>
                        <div>
                            <dt>Subtotal</dt>
                            <dd><?= e(rupiah($cart['subtotal'] ?? 0)) ?></dd>
                        </div>
                        <div>
                            <dt>Shipping</dt>
                            <dd>Calculated later</dd>
                        </div>
                    </dl>

                    <p class="cart-summary__total">
                        <span>Estimated total</span>
                        <strong><?= e(rupiah($cart['subtotal'] ?? 0)) ?></strong>
                    </p>

                    <a class="btn btn--block" href="<?= e(url('cart')) ?>">
                        Edit shopping bag
                    </a>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>