<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Database;
use App\Core\Str;
use App\Repositories\AdminProductRepository;
use App\Repositories\AuditLogRepository;
use App\Validators\ProductValidator;

/**
 * Pengelolaan produk oleh admin. Izin (RBAC) diperiksa di controller; service memastikan integritas data:
 * transaksi, penguncian baris, riwayat stok (inventory_movements), dan audit log.
 * Semua method mengembalikan array ['ok'=>bool, ...]; pesan error aman ditampilkan ke admin.
 */
final class ProductAdminService
{
    public const MAX_IMAGES = 4;
    private const TRACKED = ['name', 'slug', 'category_id', 'price', 'sale_price', 'status', 'short_description', 'is_new_arrival', 'is_best_seller', 'is_featured'];

    private AdminProductRepository $repo;
    private AuditLogRepository $audit;
    private ProductValidator $validator;
    private ImageUploadService $images;

    public function __construct(?ImageUploadService $images = null)
    {
        $this->repo = new AdminProductRepository();
        $this->audit = new AuditLogRepository();
        $this->validator = new ProductValidator();
        $this->images = $images ?? new ImageUploadService();
    }

    // ---------------- produk ----------------

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>,id:?int} */
    public function create(int $staffId, array $in, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->product($in);
        $data['status'] = 'draft'; // produk baru selalu draft; publikasi setelah ada varian
        unset($errors['status']);
        $this->resolveSlug($data, $errors, null);
        $this->checkCategory($data, $errors);
        $old = $this->old($data);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'old' => $old, 'id' => null];
        }
        $ip ??= AuthService::clientIp();
        $id = (int) Database::transaction(function () use ($staffId, $data, $ip): int {
            $id = $this->repo->insert($data);
            $this->audit->log($staffId, 'product.create', 'product', $id, $this->snapshot($data), $ip);
            return $id;
        });
        return ['ok' => true, 'errors' => [], 'old' => [], 'id' => $id];
    }

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>,not_found?:bool} */
    public function update(int $staffId, int $id, array $in, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->product($in);
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($staffId, $id, $data, $errors, $ip): array {
            if ($this->repo->lockProduct($id) === null) {
                return ['ok' => false, 'errors' => ['form' => 'Product not found.'], 'old' => [], 'not_found' => true];
            }
            $current = $this->repo->find($id);
            if ($current['status'] === 'archived') {
                $data['status'] = 'archived'; // status produk terarsip hanya berubah lewat "Restore"
                unset($errors['status']);
            }
            $this->resolveSlug($data, $errors, $id);
            $this->checkCategory($data, $errors);
            if ($data['status'] === 'active' && $this->repo->activeVariantCount($id) < 1) {
                $errors['status'] = 'Add at least one active variant before publishing.';
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors, 'old' => $this->old($data)];
            }
            $before = $this->snapshot($current);
            $after = $this->snapshot($data);
            $this->repo->update($id, $data);
            $changed = array_keys(array_filter($after, static fn($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
            $this->audit->log(
                $staffId, 'product.update', 'product', $id,
                array_intersect_key($after, array_flip($changed)), $ip, array_intersect_key($before, array_flip($changed))
            );
            return ['ok' => true, 'errors' => [], 'old' => []];
        });
    }

    /** @return array{ok:bool,error:?string} */
    public function archive(int $staffId, int $id, ?string $ip = null): array
    {
        return $this->changeStatus($staffId, $id, 'archived', 'product.archive', $ip, static fn(string $s): ?string => $s === 'archived' ? 'Product is already archived.' : null);
    }

    /** @return array{ok:bool,error:?string} */
    public function restore(int $staffId, int $id, ?string $ip = null): array
    {
        return $this->changeStatus($staffId, $id, 'draft', 'product.restore', $ip, static fn(string $s): ?string => $s !== 'archived' ? 'Only archived products can be restored.' : null);
    }

    /**
     * Hapus permanen hanya jika produk belum pernah dipesan dan tidak punya riwayat stok selain pencatatan awal.
     * Selain itu gunakan arsip (data pesanan lama tetap utuh).
     *
     * @return array{ok:bool,error:?string}
     */
    public function delete(int $staffId, int $id, ?string $ip = null): array
    {
        $ip ??= AuthService::clientIp();
        $paths = [];
        $result = Database::transaction(function () use ($staffId, $id, $ip, &$paths): array {
            $locked = $this->repo->lockProduct($id);
            if ($locked === null) {
                return ['ok' => false, 'error' => 'Product not found.'];
            }
            if ($this->repo->hasOrderHistory($id)) {
                return ['ok' => false, 'error' => 'This product appears in past orders and cannot be deleted. Archive it instead.'];
            }
            if ($this->repo->hasStockHistory($id)) {
                return ['ok' => false, 'error' => 'This product has stock adjustment history and cannot be deleted. Archive it instead.'];
            }
            $paths = $this->repo->imagePaths($id);
            $this->repo->deleteInitialMovements($id);
            $this->repo->delete($id);
            $this->audit->log($staffId, 'product.delete', 'product', $id, null, $ip, ['name' => $locked['name'], 'slug' => $locked['slug']]);
            return ['ok' => true, 'error' => null];
        });
        if ($result['ok']) {
            foreach ($paths as $p) {
                $this->images->delete($p);
            }
        }
        return $result;
    }

    // ---------------- varian & stok ----------------

    /** @return array{ok:bool,errors:array<string,string>,id:?int} */
    public function addVariant(int $staffId, int $productId, array $in, bool $canSetStock, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->variant($in, true);
        if ($data['stock'] > 0 && !$canSetStock) {
            $errors['stock'] = 'You do not have permission to set stock.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'id' => null];
        }
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($staffId, $productId, $data, $ip): array {
            $product = $this->repo->lockProduct($productId);
            if ($product === null) {
                return ['ok' => false, 'errors' => ['form' => 'Product not found.'], 'id' => null];
            }
            $errors = [];
            if ($this->repo->sizeColorExists($productId, $data['size'], $data['color'])) {
                $errors['size'] = 'This size and color combination already exists for the product.';
            }
            $sku = $data['sku'];
            if ($sku === '') {
                $sku = $this->autoSku((string) $product['name'], $data['size'], $data['color']);
            } elseif ($this->repo->skuExists($sku)) {
                $errors['sku'] = 'This SKU is already in use.';
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors, 'id' => null];
            }
            $id = $this->repo->insertVariant($productId, $data, $sku);
            if ($data['stock'] > 0) {
                $this->repo->insertMovement($id, $data['stock'], 'initial', 'Initial stock', $staffId);
            }
            $this->audit->log($staffId, 'variant.create', 'variant', $id, ['product_id' => $productId, 'sku' => $sku, 'size' => $data['size'], 'color' => $data['color'], 'stock' => $data['stock']], $ip);
            return ['ok' => true, 'errors' => [], 'id' => $id];
        });
    }

    /** @return array{ok:bool,errors:array<string,string>} */
    public function updateVariant(int $staffId, int $productId, int $variantId, array $in, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->variant($in, false);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($staffId, $productId, $variantId, $data, $ip): array {
            $product = $this->repo->lockProduct($productId);
            $current = $product === null ? null : $this->repo->findVariant($variantId, $productId, true);
            if ($current === null) {
                return ['ok' => false, 'errors' => ['form' => 'Variant not found.']];
            }
            $errors = [];
            $sku = $data['sku'] === '' ? (string) $current['sku'] : $data['sku'];
            if ($this->repo->skuExists($sku, $variantId)) {
                $errors['sku'] = 'This SKU is already in use.';
            }
            if ($this->repo->sizeColorExists($productId, $data['size'], $data['color'], $variantId)) {
                $errors['size'] = 'This size and color combination already exists for the product.';
            }
            $wasActive = $this->truthy($current['is_active']);
            if ($product['status'] === 'active' && $wasActive && !$data['is_active'] && $this->repo->activeVariantCount($productId) <= 1) {
                $errors['is_active'] = 'Cannot deactivate the last active variant of a published product.';
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }
            $this->repo->updateVariant($variantId, $data, $sku);
            $this->audit->log(
                $staffId, 'variant.update', 'variant', $variantId,
                ['sku' => $sku, 'size' => $data['size'], 'color' => $data['color'], 'is_active' => $data['is_active']], $ip,
                ['sku' => $current['sku'], 'size' => $current['size'], 'color' => $current['color'], 'is_active' => $wasActive]
            );
            return ['ok' => true, 'errors' => []];
        });
    }

    /** Atur stok ke nilai tertentu; selisih dicatat di inventory_movements ('adjustment'). */
    public function setStock(int $staffId, int $productId, int $variantId, array $in, ?string $ip = null): array
    {
        [$newStock, $note, $errors] = $this->validator->stockAdjustment($in);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'changed' => false];
        }
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($staffId, $productId, $variantId, $newStock, $note, $ip): array {
            $variant = $this->repo->findVariant($variantId, $productId, true); // baris dikunci: penyesuaian bersamaan tidak saling menimpa
            if ($variant === null) {
                return ['ok' => false, 'errors' => ['form' => 'Variant not found.'], 'changed' => false];
            }
            $delta = $newStock - (int) $variant['stock'];
            if ($delta === 0) {
                return ['ok' => true, 'errors' => [], 'changed' => false];
            }
            $this->repo->setVariantStock($variantId, $newStock);
            $this->repo->insertMovement($variantId, $delta, 'adjustment', $note === '' ? 'Manual adjustment' : $note, $staffId);
            $this->audit->log($staffId, 'inventory.adjust', 'variant', $variantId, ['stock' => $newStock], $ip, ['stock' => (int) $variant['stock']]);
            return ['ok' => true, 'errors' => [], 'changed' => true];
        });
    }

    // ---------------- gambar ----------------

    /**
     * Unggah gambar (maksimal 4 per produk). Seluruh batch ditolak jika melebihi sisa slot atau ada file tidak valid.
     *
     * @param array<int,array> $files hasil ImageUploadService::normalize()
     * @return array{ok:bool,errors:string[],added:int}
     */
    public function addImages(int $staffId, int $productId, array $files, string $alt, ?string $ip = null, bool $requireHttpUpload = true): array
    {
        $alt = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $alt));
        if ($files === []) {
            return ['ok' => false, 'errors' => ['Choose at least one image to upload.'], 'added' => 0];
        }
        if (mb_strlen($alt) > 160) {
            return ['ok' => false, 'errors' => ['Alt text must be at most 160 characters.'], 'added' => 0];
        }
        $ip ??= AuthService::clientIp();
        $stored = [];
        $result = Database::transaction(function () use ($staffId, $productId, $files, $alt, $ip, $requireHttpUpload, &$stored): array {
            $product = $this->repo->lockProduct($productId);
            if ($product === null) {
                return ['ok' => false, 'errors' => ['Product not found.'], 'added' => 0];
            }
            $room = self::MAX_IMAGES - $this->repo->imageCount($productId);
            if (count($files) > $room) {
                return ['ok' => false, 'errors' => [$room <= 0
                    ? 'This product already has the maximum of ' . self::MAX_IMAGES . ' images. Delete one first.'
                    : 'You can add only ' . $room . ' more image(s) (maximum ' . self::MAX_IMAGES . ' per product).'], 'added' => 0];
            }
            $errors = [];
            foreach ($files as $file) {
                $r = $this->images->store($file, 'products', $requireHttpUpload);
                if ($r['ok']) {
                    $stored[] = $r['path'];
                } else {
                    $errors[] = (string) $r['error'];
                }
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors, 'added' => 0];
            }
            $sort = $this->repo->nextImageSort($productId);
            foreach ($stored as $path) {
                $this->repo->insertImage($productId, $path, $alt !== '' ? $alt : (string) $product['name'], $sort++);
            }
            $this->audit->log($staffId, 'product.images_add', 'product', $productId, ['count' => count($stored)], $ip);
            return ['ok' => true, 'errors' => [], 'added' => count($stored)];
        });
        if (!$result['ok']) { // jangan tinggalkan file yatim jika batch gagal
            foreach ($stored as $p) {
                $this->images->delete($p);
            }
        }
        return $result;
    }

    /** @return array{ok:bool,error:?string} */
    public function deleteImage(int $staffId, int $productId, int $imageId, ?string $ip = null): array
    {
        $ip ??= AuthService::clientIp();
        $path = null;
        $result = Database::transaction(function () use ($staffId, $productId, $imageId, $ip, &$path): array {
            if ($this->repo->lockProduct($productId) === null || ($img = $this->repo->findImage($imageId, $productId)) === null) {
                return ['ok' => false, 'error' => 'Image not found.'];
            }
            $path = (string) $img['path'];
            $this->repo->deleteImage($imageId);
            $this->audit->log($staffId, 'product.image_delete', 'product', $productId, null, $ip, ['image_id' => $imageId]);
            return ['ok' => true, 'error' => null];
        });
        if ($result['ok']) {
            $this->images->delete($path);
        }
        return $result;
    }

    /** @return array{ok:bool,error:?string} */
    public function makePrimaryImage(int $staffId, int $productId, int $imageId, ?string $ip = null): array
    {
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($staffId, $productId, $imageId, $ip): array {
            if ($this->repo->lockProduct($productId) === null || $this->repo->findImage($imageId, $productId) === null) {
                return ['ok' => false, 'error' => 'Image not found.'];
            }
            $this->repo->makePrimary($imageId, $productId);
            $this->audit->log($staffId, 'product.image_primary', 'product', $productId, ['image_id' => $imageId], $ip);
            return ['ok' => true, 'error' => null];
        });
    }

    // ---------------- util ----------------

    private function changeStatus(int $staffId, int $id, string $to, string $action, ?string $ip, callable $guard): array
    {
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($staffId, $id, $to, $action, $ip, $guard): array {
            $locked = $this->repo->lockProduct($id);
            if ($locked === null) {
                return ['ok' => false, 'error' => 'Product not found.'];
            }
            if (($msg = $guard((string) $locked['status'])) !== null) {
                return ['ok' => false, 'error' => $msg];
            }
            $this->repo->setStatus($id, $to);
            $this->audit->log($staffId, $action, 'product', $id, ['status' => $to], $ip, ['status' => $locked['status']]);
            return ['ok' => true, 'error' => null];
        });
    }

    private function resolveSlug(array &$data, array &$errors, ?int $excludeId): void
    {
        if (isset($errors['slug'])) {
            return;
        }
        if ($data['slug'] === '') {
            $base = Str::slug((string) $data['name']);
            $base = $base !== '' ? $base : 'product';
            $slug = $base;
            for ($n = 2; $this->repo->slugExists($slug, $excludeId) && $n < 500; $n++) {
                $slug = substr($base, 0, 170) . '-' . $n;
            }
            $data['slug'] = $slug;
        } elseif ($this->repo->slugExists($data['slug'], $excludeId)) {
            $errors['slug'] = 'This slug is already used by another product.';
        }
    }

    private function checkCategory(array $data, array &$errors): void
    {
        if (!isset($errors['category_id']) && !$this->repo->categoryExists((int) $data['category_id'])) {
            $errors['category_id'] = 'The selected category does not exist.';
        }
    }

    private function autoSku(string $productName, string $size, string $color): string
    {
        $part = static fn(string $s, int $n): string => strtoupper(substr(Str::slug($s), 0, $n));
        $base = trim($part($productName, 20) . '-' . $part($size, 8) . '-' . $part($color, 8), '-');
        $base = preg_match('/^[A-Z0-9-]{3,}$/', $base) === 1 ? $base : 'SKU-' . strtoupper(bin2hex(random_bytes(3)));
        $sku = $base;
        for ($n = 2; $this->repo->skuExists($sku) && $n < 500; $n++) {
            $sku = $base . '-' . $n;
        }
        return $sku;
    }

    /** Nilai yang dilacak untuk audit; dinormalisasi agar perbandingan lama/baru akurat. */
    private function snapshot(array $row): array
    {
        $money = static fn($v): ?string => $v === null || $v === '' ? null : number_format((float) $v, 2, '.', '');
        return [
            'name' => (string) $row['name'], 'slug' => (string) $row['slug'], 'category_id' => (int) $row['category_id'],
            'price' => $money($row['price']), 'sale_price' => $money($row['sale_price'] ?? null), 'status' => (string) $row['status'],
            'short_description' => (string) ($row['short_description'] ?? ''),
            'is_new_arrival' => $this->truthy($row['is_new_arrival']), 'is_best_seller' => $this->truthy($row['is_best_seller']),
            'is_featured' => $this->truthy($row['is_featured']),
        ];
    }

    /** @return array<string,string> */
    private function old(array $d): array
    {
        return [
            'name' => (string) $d['name'], 'slug' => (string) $d['slug'], 'short_description' => (string) $d['short_description'],
            'description' => (string) $d['description'], 'category_id' => (string) $d['category_id'],
            'price' => $d['price'] === null ? '' : (string) $d['price'], 'sale_price' => $d['sale_price'] === null ? '' : (string) $d['sale_price'],
            'status' => (string) $d['status'], 'is_new_arrival' => $d['is_new_arrival'] ? '1' : '', 'is_best_seller' => $d['is_best_seller'] ? '1' : '',
            'is_featured' => $d['is_featured'] ? '1' : '',
        ];
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}