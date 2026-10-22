<?php
declare(strict_types=1);
namespace App\Validators;

/** Validasi server-side form admin produk, varian, dan stok. Mengembalikan [data bersih, error]. */
final class ProductValidator
{
    public const MAX_STOCK = 100000;

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function product(array $in): array
    {
        $errors = [];
        $name = $this->line($in['name'] ?? null);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $errors['name'] = 'Product name must be 2–160 characters.';
        }

        $slug = is_string($in['slug'] ?? null) ? strtolower(trim($in['slug'])) : '';
        if ($slug !== '' && (strlen($slug) > 180 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1)) {
            $errors['slug'] = 'Slug may only contain lowercase letters, numbers, and single hyphens (max 180).';
        }

        $short = $this->line($in['short_description'] ?? null);
        if (mb_strlen($short) > 255) {
            $errors['short_description'] = 'Short description must be at most 255 characters.';
        }
        $description = is_string($in['description'] ?? null) ? trim(str_replace("\r\n", "\n", $in['description'])) : '';
        $description = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description);
        if (mb_strlen($description) > 5000) {
            $errors['description'] = 'Description must be at most 5000 characters.';
        }

        $categoryId = $this->intOrZero($in['category_id'] ?? null);
        if ($categoryId < 1) {
            $errors['category_id'] = 'Please choose a category.';
        }

        $price = $this->money($in['price'] ?? null);
        if ($price === null || (float) $price <= 0) {
            $errors['price'] = 'Enter a price greater than 0 (digits only, optional decimals with a dot).';
        }
        $salePriceRaw = is_string($in['sale_price'] ?? null) ? trim($in['sale_price']) : '';
        $salePrice = null;
        if ($salePriceRaw !== '') {
            $salePrice = $this->money($salePriceRaw);
            if ($salePrice === null || (float) $salePrice <= 0) {
                $errors['sale_price'] = 'Enter a valid sale price (digits only).';
                $salePrice = null;
            } elseif ($price !== null && (float) $salePrice >= (float) $price) {
                $errors['sale_price'] = 'Sale price must be lower than the regular price.';
            }
        }

        $status = is_string($in['status'] ?? null) ? $in['status'] : 'draft';
        if (!in_array($status, ['draft', 'active'], true)) {
            $errors['status'] = 'Status must be Draft or Active (use Archive to archive).';
            $status = 'draft';
        }

        return [[
            'name' => $name, 'slug' => $slug, 'short_description' => $short, 'description' => $description,
            'category_id' => $categoryId, 'price' => $price, 'sale_price' => $salePrice, 'status' => $status,
            'is_new_arrival' => !empty($in['is_new_arrival']), 'is_best_seller' => !empty($in['is_best_seller']),
            'is_featured' => !empty($in['is_featured']),
        ], $errors];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function variant(array $in, bool $withStock): array
    {
        $errors = [];
        $size = $this->line($in['size'] ?? null);
        $color = $this->line($in['color'] ?? null);
        if ($size === '' || mb_strlen($size) > 20) {
            $errors['size'] = 'Size is required (max 20 characters).';
        }
        if ($color === '' || mb_strlen($color) > 40) {
            $errors['color'] = 'Color is required (max 40 characters).';
        }
        $sku = is_string($in['sku'] ?? null) ? strtoupper(trim($in['sku'])) : '';
        if ($sku !== '' && preg_match('/^[A-Z0-9._-]{3,60}$/', $sku) !== 1) {
            $errors['sku'] = 'SKU must be 3–60 characters: letters, numbers, dot, underscore, or hyphen.';
        }
        $stock = 0;
        if ($withStock) {
            $stock = $this->stock($in['stock'] ?? '0', $errors, 'stock');
        }
        return [[
            'size' => $size, 'color' => $color, 'sku' => $sku, 'stock' => $stock, 'is_active' => !empty($in['is_active']),
        ], $errors];
    }

    /** @return array{0:int,1:string,2:array<string,string>} [stok baru, catatan, error] */
    public function stockAdjustment(array $in): array
    {
        $errors = [];
        $stock = $this->stock($in['new_stock'] ?? null, $errors, 'new_stock');
        $note = $this->line($in['note'] ?? null);
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'Note must be at most 255 characters.';
        }
        return [$stock, $note, $errors];
    }

    private function stock(mixed $v, array &$errors, string $key): int
    {
        if (!is_string($v) || preg_match('/^\d{1,6}$/', trim($v)) !== 1 || (int) trim($v) > self::MAX_STOCK) {
            $errors[$key] = 'Stock must be a whole number between 0 and ' . self::MAX_STOCK . '.';
            return 0;
        }
        return (int) trim($v);
    }

    /** "149000" atau "149000.50" -> "149000.00"/"149000.50"; selain itu null. */
    private function money(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v) !== 1) {
            return null;
        }
        return number_format((float) $v, 2, '.', '');
    }

    private function intOrZero(mixed $v): int
    {
        return is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : 0;
    }

    private function line(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }
}
