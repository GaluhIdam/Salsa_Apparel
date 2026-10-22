<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\Auth;
use App\Repositories\AddressRepository;
use App\Repositories\CheckoutRepository;
use DomainException;
use RuntimeException;
use Throwable;

final class CheckoutService
{
    public function __construct(
        private readonly CheckoutRepository $repository = new CheckoutRepository()
    ) {
    }

    /**
     * Membuat order dari cart yang tersimpan di database.
     * Harga dan stok selalu diambil ulang dari database.
     */
    public function createOrder(array $customer, array $shipping): array
    {
        $customer = $this->validateCustomer($customer);
        $shipping = $this->resolveShippingAddress($shipping);
        $shipping = $this->validateShipping($shipping);

        $pdo = Database::connection();

        return Database::transaction(function () use (
            $pdo,
            $customer,
            $shipping
        ): array {
            $cartId = $this->resolveCartId($pdo);

            if ($cartId === null) {
                throw new DomainException(
                    'Keranjang belanja tidak ditemukan.'
                );
            }

            $items = $this->repository->lockCart($pdo, $cartId);

            if ($items === []) {
                throw new DomainException(
                    'Keranjang belanja masih kosong.'
                );
            }

            $subtotal = 0;
            $orderItems = [];

            foreach ($items as $item) {
                if (
                    !$this->isDatabaseTrue($item['variant_active'])
                    || $item['product_status'] !== 'active'
                    || !$this->isDatabaseTrue($item['category_active'])
                ) {
                    throw new DomainException(
                        'Ada produk yang sudah tidak tersedia. Periksa keranjang.'
                    );
                }

                $quantity = (int) $item['quantity'];
                $stock = (int) $item['stock'];

                if ($quantity < 1 || $quantity > $stock) {
                    throw new DomainException(
                        'Stok produk ' . $item['name'] . ' tidak mencukupi.'
                    );
                }

                $price = $item['sale_price'] !== null
                    ? (int) $item['sale_price']
                    : (int) $item['price'];

                if ($price < 0) {
                    throw new RuntimeException(
                        'Harga produk tidak valid.'
                    );
                }

                $lineTotal = $price * $quantity;
                $subtotal += $lineTotal;

                $orderItems[] = [
                    'variant_id' => (int) $item['variant_id'],
                    'product_name' => $item['name'],
                    'sku' => $item['sku'],
                    'size' => $item['size'],
                    'color' => $item['color'],
                    'unit_price' => $price,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                ];
            }

            // Ongkir sementara nol sampai integrasi kurir diterapkan.
            $shippingCost = 0;
            $grandTotal = $subtotal + $shippingCost;

            $orderNumber = 'SAL-' . date('Ymd') . '-'
                . strtoupper(bin2hex(random_bytes(5)));

            $accessToken = bin2hex(random_bytes(32));

            $orderId = $this->repository->insertOrder($pdo, [
                'order_number' => $orderNumber,
                'user_id' => Auth::id(),
                'access_token_hash' => hash('sha256', $accessToken),
                'customer_email' => $customer['email'],
                'recipient_name' => $shipping['recipient_name'],
                'recipient_phone' => $shipping['recipient_phone'],
                'ship_address' => $shipping['address'],
                'ship_city' => $shipping['city'],
                'ship_province' => $shipping['province'],
                'ship_postal_code' => $shipping['postal_code'],
                'shipping_courier' => $shipping['courier'],
                'shipping_service' => $shipping['service'],
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'grand_total' => $grandTotal,
            ]);

            foreach ($orderItems as $item) {
                $this->repository->insertItem($pdo, $orderId, $item);

                $this->repository->reserveStock(
                    $pdo,
                    $item['variant_id'],
                    $item['quantity'],
                    $orderId
                );
            }

            $this->repository->recordInitialStatus($pdo, $orderId);
            $this->repository->clearCart($pdo, $cartId);

            return [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'access_token' => $accessToken,
            ];
        });
    }

    private function resolveShippingAddress(array $shipping): array
    {
        $addressId = trim((string) ($shipping['saved_address_id'] ?? ''));

        // Tidak memilih alamat tersimpan: gunakan input manual.
        if ($addressId === '') {
            return $shipping;
        }

        if (!ctype_digit($addressId) || (int) $addressId < 1) {
            throw new DomainException('Alamat pengiriman tidak valid.');
        }

        $userId = Auth::id();

        if ($userId === null) {
            throw new DomainException(
                'Silakan login untuk menggunakan alamat tersimpan.'
            );
        }

        $address = (new AddressRepository())->findForUser(
            (int) $addressId,
            $userId
        );

        if ($address === null) {
            throw new DomainException(
                'Alamat tidak ditemukan atau bukan milik akun Anda.'
            );
        }

        // Gunakan data dari database, bukan data alamat kiriman browser.
        $shipping['recipient_name'] = $address['recipient_name'];
        $shipping['recipient_phone'] = $address['phone'];
        $shipping['address'] = $address['address_line'];
        $shipping['city'] = $address['city'];
        $shipping['province'] = $address['province'];
        $shipping['postal_code'] = $address['postal_code'];

        return $shipping;
    }

    private function resolveCartId(\PDO $pdo): ?int
    {
        $userId = Auth::id();

        if ($userId !== null) {
            return $this->repository->findCartIdByUser($pdo, $userId);
        }

        $guestToken = $_COOKIE['salsa_cart'] ?? '';

        if (!is_string($guestToken) || !preg_match('/^[a-f0-9]{64}$/', $guestToken)) {
            return null;
        }

        return $this->repository->findCartIdByGuestHash(
            $pdo,
            hash('sha256', $guestToken)
        );
    }

    private function validateCustomer(array $data): array
    {
        $email = trim((string) ($data['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Alamat email tidak valid.');
        }

        return ['email' => $email];
    }

    private function validateShipping(array $data): array
    {
        $fields = [
            'recipient_name' => ['Nama penerima', 120],
            'recipient_phone' => ['Nomor telepon', 30],
            'address' => ['Alamat', 1000],
            'city' => ['Kota', 100],
            'province' => ['Provinsi', 100],
            'postal_code' => ['Kode pos', 10],
            'courier' => ['Kurir', 40],
            'service' => ['Layanan pengiriman', 60],
        ];

        $result = [];

        foreach ($fields as $key => [$label, $maxLength]) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value === '') {
                throw new DomainException($label . ' wajib diisi.');
            }

            if (mb_strlen($value) > $maxLength) {
                throw new DomainException(
                    $label . ' maksimal ' . $maxLength . ' karakter.'
                );
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function isDatabaseTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}