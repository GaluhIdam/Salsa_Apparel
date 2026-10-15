<?php
declare(strict_types=1);
// Smoke test lapisan database katalog (Phase 4). Jalankan: php scripts/catalog-check.php
// Mengasumsikan seed bawaan (5 produk demo aktif) dan belum diubah.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/ProductRepository.php';
require __DIR__ . '/../app/Repositories/CategoryRepository.php';

use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;

App\Core\Env::load(__DIR__ . '/../.env');
$repo = new ProductRepository();
$fails = 0;
$check = static function (string $label, bool $ok) use (&$fails): void {
    echo ($ok ? '[PASS] ' : '[FAIL] '), $label, PHP_EOL;
    if (!$ok) { $fails++; }
};

$all = $repo->search(['sort' => 'newest'], 1, 12);
$check('shop: 5 produk aktif', $all['total'] === 5);
$check('kategori hoodies: 1 produk', $repo->search(['category' => 'hoodies'], 1, 12)['total'] === 1);
$check('kategori tidak ada: 0 produk', $repo->search(['category' => 'tidak-ada'], 1, 12)['total'] === 0);
$check('search "hoodie": >= 1', $repo->search(['q' => 'hoodie'], 1, 12)['total'] >= 1);
$check('search warna "off white": >= 1', $repo->search(['q' => 'off white'], 1, 12)['total'] >= 1);
$check('search "zzzz": 0', $repo->search(['q' => 'zzzz'], 1, 12)['total'] === 0);
$check('search "%" tidak jadi wildcard: 0', $repo->search(['q' => '%'], 1, 12)['total'] === 0);
$check("search injeksi \"' OR 1=1 --\": 0", $repo->search(['q' => "' OR 1=1 --"], 1, 12)['total'] === 0);
// Seed: 149.000 (T-Shirt), 179.000 (Tote), 229.000, 299.000, 449.000 — tanpa harga diskon.
$check('min_price 200000: 3 produk (T-Shirt dan Tote dikecualikan)', $repo->search(['min_price' => 200000], 1, 12)['total'] === 3);
$check('min_price 200000 + max_price 199999 membagi seluruh produk tanpa tumpang tindih',
    $repo->search(['min_price' => 200000], 1, 12)['total'] + $repo->search(['max_price' => 199999], 1, 12)['total'] === $all['total']);
$check('max_price 200000: 2 produk', $repo->search(['max_price' => 200000], 1, 12)['total'] === 2);
$asc = $repo->search(['sort' => 'price_asc'], 1, 12)['items'];
$desc = $repo->search(['sort' => 'price_desc'], 1, 12)['items'];
$check('sort price_asc: termurah dulu', (float) $asc[0]['price'] === 149000.0);
$check('sort price_desc: termahal dulu', (float) $desc[0]['price'] === 449000.0);
$p = $repo->search(['sort' => 'newest'], 1, 2);
$check('pagination 2/halaman: 3 halaman', $p['total_pages'] === 3 && count($p['items']) === 2);
$check('halaman melebihi batas di-clamp', $repo->search(['sort' => 'newest'], 99, 2)['page'] === 3);
$d = $repo->findDetailBySlug('essential-t-shirt');
$check('detail essential-t-shirt: 4 varian', $d !== null && count($d['variants']) === 4);
$check('detail slug tidak ada: null', $repo->findDetailBySlug('tidak-ada') === null);
$check('kategori valid ditemukan', (new CategoryRepository())->findBySlug('men') !== null);

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
