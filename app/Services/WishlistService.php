<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use App\Core\Cookie;
use App\Repositories\WishlistRepository;

/**
 * Wishlist guest di database. Browser hanya memegang token acak (cookie HttpOnly);
 * database menyimpan hash SHA-256 token. Semua operasi dibatasi pada wishlist pemilik token.
 */
final class WishlistService
{
    public const COOKIE = 'salsa_wish';
    public const MAX_ITEMS = 100;
    public const COOKIE_TTL_DAYS = 90;
    /** Cookie hanya diperbarui saat ada perubahan, jadi retensi di database harus > masa cookie. */
    public const PURGE_AFTER_DAYS = 120;

    private WishlistRepository $repo;

    /**
     * @param ?string $token  Token guest eksplisit (pengujian CLI); default dibaca dari cookie.
     * @param ?int    $userId Jika diisi, wishlist milik user tersebut yang dipakai (cookie guest diabaikan).
     */
    public function __construct(private ?string $token = null, private ?int $userId = null)
    {
        $this->repo = new WishlistRepository();
    }

    /** Service untuk request web: pemilik = user yang login, atau guest (cookie) jika belum login. */
    public static function forRequest(): self
    {
        return new self(null, Auth::id());
    }

    /** Jumlah produk unik (aktif) yang tersimpan. */
    public function count(): int
    {
        return count($this->savedIds());
    }

    /** @return int[] */
    public function savedIds(): array
    {
        $id = $this->wishlistId(false);
        return $id === null ? [] : $this->repo->activeProductIdsByWishlistId($id);
    }

    public function isSaved(int $productId): bool
    {
        $id = $this->wishlistId(false);
        return $id !== null && $productId > 0 && $this->repo->hasItem($id, $productId);
    }

    /** @return array{ok:bool,message:string,slug:?string,options:bool} */
    public function add(int $productId): array
    {
        if ($productId < 1 || !$this->repo->isSavableProduct($productId)) {
            return $this->fail('This product is no longer available.');
        }
        $id = $this->wishlistId(false);
        if ($id !== null && $this->repo->hasItem($id, $productId)) {
            return $this->ok('This product is already in your wishlist.');
        }
        if ($id !== null && $this->repo->itemCount($id) >= self::MAX_ITEMS) {
            return $this->fail('Your wishlist is full (' . self::MAX_ITEMS . ' products). Remove one to add another.');
        }
        $id ??= $this->wishlistId(true);
        $this->repo->addItem($id, $productId);
        $this->repo->touch($id);
        $this->remember($this->token());
        return $this->ok('Saved to your wishlist.');
    }

    /** @return array{ok:bool,message:string,slug:?string,options:bool} */
    public function remove(int $productId): array
    {
        $id = $this->wishlistId(false);
        if ($id === null || $productId < 1 || !$this->repo->removeItem($id, $productId)) {
            return $this->fail('That product is not in your wishlist.');
        }
        $this->repo->touch($id);
        $this->remember($this->token());
        return $this->ok('Removed from your wishlist.');
    }

    /**
     * Isi wishlist untuk halaman /wishlist, dengan status ketersediaan dan opsi pindah ke cart.
     * "movable" hanya jika produk punya tepat satu varian aktif dan stoknya ada
     * (varian tidak pernah dipilih sembarang untuk pengguna).
     */
    public function items(): array
    {
        $id = $this->wishlistId(false);
        if ($id === null) {
            return [];
        }
        $items = [];
        foreach ($this->repo->items($id) as $row) {
            $active = $row['product_status'] === 'active' && $this->truthy($row['category_active']);
            $stock = (int) $row['total_stock'];
            $variants = (int) $row['variant_count'];
            $row['id'] = (int) $row['id'];
            $row['available'] = $active;
            $row['movable'] = $active && $variants === 1 && $stock > 0;
            $row['sold_out'] = $active && $stock <= 0;
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Pindahkan produk (hanya jika ada di wishlist ini) ke cart dengan penggabungan perilaku Phase 5.
     * Produk dengan banyak varian ditolak dengan options=true agar pengguna memilih di halaman detail.
     *
     * @return array{ok:bool,message:string,slug:?string,options:bool}
     */
    public function moveToCart(int $productId, CartService $cart): array
    {
        if (!$this->isSaved($productId)) {
            return $this->fail('That product is not in your wishlist.');
        }
        $variants = $this->repo->activeVariants($productId);
        if ($variants === []) {
            return $this->fail('This product is no longer available.');
        }
        $slug = (string) $variants[0]['slug'];
        if (count($variants) !== 1) {
            return ['ok' => false, 'message' => 'Please choose a size and color first.', 'slug' => $slug, 'options' => true];
        }
        $result = $cart->add((int) $variants[0]['id'], 1, $productId);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'slug' => $slug, 'options' => false];
        }
        $this->remove($productId);
        return ['ok' => true, 'message' => 'Moved to your bag.', 'slug' => $slug, 'options' => false];
    }

    /**
     * Gabungkan wishlist guest (token cookie) ke wishlist user, lalu bersihkan identitas guest bila berhasil.
     * Jika terjadi kegagalan, exception dilempar dan wishlist guest tidak berubah.
     */
    public function mergeIntoUser(int $userId): int
    {
        $token = $this->token();
        $guestId = $token === null ? null : $this->repo->findIdByTokenHash(hash('sha256', $token));
        if ($guestId === null) {
            return 0;
        }
        $merged = $this->repo->mergeGuestIntoUser($guestId, $userId, self::MAX_ITEMS);
        $this->forgetGuestToken();
        return $merged;
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

    private function wishlistId(bool $create): ?int
    {
        if ($this->userId !== null) {
            return $create ? $this->repo->ensureUserWishlist($this->userId) : $this->repo->findIdByUserId($this->userId);
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
        $this->token = $token;
        $_COOKIE[self::COOKIE] = $token;
        return $id;
    }

    /** Kirim/perbarui cookie (HttpOnly, SameSite=Lax, Secure jika HTTPS). */
    private function remember(?string $token): void
    {
        if ($token === null || $this->userId !== null || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::COOKIE_TTL_DAYS * 86400,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }

    /** @return array{ok:bool,message:string,slug:?string,options:bool} */
    private function ok(string $message): array
    {
        return ['ok' => true, 'message' => $message, 'slug' => null, 'options' => false];
    }

    /** @return array{ok:bool,message:string,slug:?string,options:bool} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'slug' => null, 'options' => false];
    }
}
