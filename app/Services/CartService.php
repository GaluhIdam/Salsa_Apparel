<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use App\Core\Cookie;
use App\Repositories\CartRepository;

/**
 * Cart guest di database. Browser hanya memegang token acak (cookie HttpOnly);
 * database menyimpan hash SHA-256 token. Harga, stok, dan total selalu dihitung ulang dari database.
 */
final class CartService
{
    public const COOKIE = 'salsa_cart';
    public const MAX_QTY = 20;
    private const COOKIE_TTL = 60 * 60 * 24 * 30;

    private CartRepository $repo;

    /**
     * @param ?string $token  Token guest eksplisit (pengujian CLI); default dibaca dari cookie.
     * @param ?int    $userId Jika diisi, cart milik user tersebut yang dipakai (cookie guest diabaikan).
     */
    public function __construct(private ?string $token = null, private ?int $userId = null)
    {
        $this->repo = new CartRepository();
    }

    /** Service untuk request web: pemilik = user yang login, atau guest (cookie) jika belum login. */
    public static function forRequest(): self
    {
        return new self(null, Auth::id());
    }

    /** Total unit di cart yang masih bisa dibeli. */
    public function count(): int
    {
        $cartId = $this->cartId(false);
        return $cartId === null ? 0 : $this->repo->purchasableUnitsByCartId($cartId, self::MAX_QTY);
    }

    /** @return array{ok:bool,message:string,slug:?string} */
    public function add(int $variantId, int $qty, ?int $productId = null): array
    {
        if ($variantId < 1) {
            return $this->fail('Please select a size and color.');
        }
        if ($qty < 1 || $qty > self::MAX_QTY) {
            return $this->fail('Please choose a quantity between 1 and ' . self::MAX_QTY . '.');
        }
        $variant = $this->repo->purchasableVariant($variantId);
        if ($variant === null) {
            return $this->fail('This item is no longer available.');
        }
        $slug = (string) $variant['slug'];
        if ($productId !== null && (int) $variant['product_id'] !== $productId) {
            return $this->fail('The selected option does not belong to this product.', $slug);
        }

        $stock = (int) $variant['stock'];
        if ($stock <= 0) {
            return $this->fail('This item is out of stock.', $slug);
        }
        $cap = min($stock, self::MAX_QTY);

        $cartId = $this->cartId(false);
        $existing = $cartId === null ? 0 : $this->repo->itemQuantity($cartId, $variantId);
        if ($existing + $qty > $cap) {
            $room = $cap - $existing;
            return $this->fail(
                $room <= 0
                    ? 'You already have the maximum available quantity (' . $cap . ') of this item in your bag.'
                    : 'Only ' . $room . ' more of this item can be added.',
                $slug
            );
        }

        $cartId ??= $this->cartId(true);
        $this->repo->addQuantity($cartId, $variantId, $qty, $cap);
        return ['ok' => true, 'message' => 'Added to your bag.', 'slug' => $slug];
    }

    /** @return array{ok:bool,message:string,slug:?string} */
    public function setQuantity(int $variantId, int $qty): array
    {
        $cartId = $this->cartId(false);
        if ($cartId === null || $this->repo->itemQuantity($cartId, $variantId) < 1) {
            return $this->fail('That item is not in your bag.');
        }
        if ($qty < 1 || $qty > self::MAX_QTY) {
            return $this->fail('Please choose a quantity between 1 and ' . self::MAX_QTY . '. Use Remove to delete an item.');
        }
        $variant = $this->repo->purchasableVariant($variantId);
        if ($variant === null) {
            return $this->fail('This item is no longer available. Please remove it from your bag.');
        }
        $cap = min((int) $variant['stock'], self::MAX_QTY);
        if ($qty > $cap) {
            return $this->fail($cap <= 0 ? 'This item is out of stock.' : 'Only ' . $cap . ' available for this item.');
        }
        $this->repo->setQuantity($cartId, $variantId, $qty);
        return ['ok' => true, 'message' => 'Quantity updated.', 'slug' => null];
    }

    /** @return array{ok:bool,message:string,slug:?string} */
    public function remove(int $variantId): array
    {
        $cartId = $this->cartId(false);
        if ($cartId === null || !$this->repo->remove($cartId, $variantId)) {
            return $this->fail('That item is not in your bag.');
        }
        return ['ok' => true, 'message' => 'Item removed from your bag.', 'slug' => null];
    }

    /**
     * Isi cart untuk halaman /cart. Memeriksa ulang status, harga, dan stok:
     * kuantitas yang melebihi stok dikurangi, item tidak aktif/habis ditandai dan tidak dihitung.
     *
     * @return array{items:array,units:int,subtotal:float,notices:string[]}
     */
    public function view(): array
    {
        $empty = ['items' => [], 'units' => 0, 'subtotal' => 0.0, 'notices' => []];
        $cartId = $this->cartId(false);
        if ($cartId === null) {
            return $empty;
        }

        $items = [];
        $notices = [];
        $units = 0;
        $subtotalCents = 0;

        foreach ($this->repo->items($cartId) as $row) {
            $stock = (int) $row['stock'];
            $qty = (int) $row['quantity'];
            $active = $this->truthy($row['variant_active']) && $row['product_status'] === 'active' && $this->truthy($row['category_active']);
            $cap = min($stock, self::MAX_QTY);
            $reason = null;

            if (!$active) {
                $reason = 'This item is no longer available.';
            } elseif ($stock <= 0) {
                $reason = 'This item is out of stock.';
            } elseif ($qty > $cap) {
                $this->repo->setQuantity($cartId, (int) $row['variant_id'], $cap);
                $notices[] = 'The quantity of ' . $row['name'] . ' was reduced to ' . $cap . ' due to limited stock.';
                $qty = $cap;
            }

            $unitCents = (int) round((float) ($row['sale_price'] ?? $row['price']) * 100);
            $available = $reason === null;
            if ($available) {
                $units += $qty;
                $subtotalCents += $unitCents * $qty;
            }

            $items[] = [
                'variant_id' => (int) $row['variant_id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'image_path' => $row['image_path'],
                'image_alt' => $row['image_alt'],
                'size' => $row['size'],
                'color' => $row['color'],
                'sku' => $row['sku'],
                'quantity' => $qty,
                'unit_price' => $unitCents / 100,
                'regular_price' => (float) $row['price'],
                'line_total' => $unitCents * $qty / 100,
                'max_quantity' => max($cap, 0),
                'available' => $available,
                'reason' => $reason,
            ];
        }

        return ['items' => $items, 'units' => $units, 'subtotal' => $subtotalCents / 100, 'notices' => $notices];
    }

    /**
     * Gabungkan cart guest (token cookie) ke cart user, lalu bersihkan identitas guest bila berhasil.
     * Jika terjadi kegagalan, exception dilempar dan cart guest tidak berubah.
     *
     * @return array{merged:int,dropped:int}
     */
    public function mergeGuestIntoUser(int $userId): array
    {
        $token = $this->token();
        $guestId = $token === null ? null : $this->repo->findIdByTokenHash(hash('sha256', $token));
        if ($guestId === null) {
            return ['merged' => 0, 'dropped' => 0];
        }
        $result = $this->repo->mergeGuestCart($guestId, $userId, self::MAX_QTY);
        $this->forgetGuestToken();
        return $result;
    }

    public function forgetGuestToken(): void
    {
        $this->token = null;
        Cookie::clear(self::COOKIE);
    }

    private function token(): ?string
    {
        $t = $this->token ?? ($_COOKIE[self::COOKIE] ?? null);
        return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) === 1 ? $t : null;
    }

    private function cartId(bool $create): ?int
    {
        if ($this->userId !== null) {
            return $create ? $this->repo->ensureUserCart($this->userId) : $this->repo->findIdByUserId($this->userId);
        }
        $token = $this->token();
        if ($token !== null) {
            $id = $this->repo->findIdByTokenHash(hash('sha256', $token));
            if ($id !== null) {
                return $id;
            }
        }
        if (!$create) {
            return null;
        }
        $token ??= bin2hex(random_bytes(32));
        $id = $this->repo->create(hash('sha256', $token));
        $this->remember($token);
        return $id;
    }

    private function remember(string $token): void
    {
        $this->token = $token;
        $_COOKIE[self::COOKIE] = $token;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(self::COOKIE, $token, [
                'expires' => time() + self::COOKIE_TTL,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }

    /** @return array{ok:bool,message:string,slug:?string} */
    private function fail(string $message, ?string $slug = null): array
    {
        return ['ok' => false, 'message' => $message, 'slug' => $slug];
    }
}
