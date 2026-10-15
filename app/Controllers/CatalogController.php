<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;

final class CatalogController extends Controller
{
    private const PER_PAGE = 12;
    private const MAX_PRICE = 999999999;

    public function shop(): void
    {
        $this->listing('/shop', 'Shop', 'Everyday essentials, newest first.', null, 'shop');
    }

    public function category(string $slug): void
    {
        $category = $this->validSlug($slug) ? (new CategoryRepository())->findBySlug($slug) : null;
        if ($category === null) {
            (new PageController())->notFound();
            return;
        }
        $this->listing('/category/' . $category['slug'], $category['name'], (string) ($category['description'] ?? ''), $category, 'category');
    }

    public function search(): void
    {
        $this->listing('/search', 'Search', '', null, 'search');
    }

    public function product(string $slug): void
    {
        $repo = new ProductRepository();
        $product = $this->validSlug($slug) ? $repo->findDetailBySlug($slug) : null;
        if ($product === null) {
            (new PageController())->notFound();
            return;
        }

        $variants = $product['variants'];
        $colors = array_values(array_unique(array_column($variants, 'color')));
        $sizes = array_values(array_unique(array_column($variants, 'size')));
        usort($sizes, static fn(string $a, string $b): int => self::sizeRank($a) <=> self::sizeRank($b));

        $related = $repo->search(
            ['category' => $product['category_slug'], 'exclude_id' => $product['id'], 'sort' => 'newest'],
            1,
            4
        )['items'];

        $this->view('pages/product', [
            'pageTitle' => $product['name'],
            'product' => $product,
            'colors' => $colors,
            'sizes' => $sizes,
            'totalStock' => array_sum(array_column($variants, 'stock')),
            'related' => $related,
        ]);
    }

    private function listing(string $path, string $heading, string $intro, ?array $category, string $mode): void
    {
        $nav = (new CategoryRepository())->allWithCounts();
        $filters = $this->filters($_GET, $nav, $category);
        $searchedEmpty = $mode === 'search' && $filters['q'] === '';

        $result = ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        if (!$searchedEmpty) {
            $result = (new ProductRepository())->search($filters, $this->page($_GET), self::PER_PAGE);
        }

        if ($mode === 'search' && $filters['q'] !== '') {
            $heading = 'Results for “' . $filters['q'] . '”';
        }

        $defaultSort = $filters['q'] !== '' ? 'relevance' : 'newest';
        $query = [
            'q' => $filters['q'],
            'category' => $category === null ? $filters['category'] : null,
            'min_price' => $filters['min_price'],
            'max_price' => $filters['max_price'],
            'sort' => $filters['sort'] !== $defaultSort ? $filters['sort'] : null,
        ];

        $sorts = ['newest' => 'Newest', 'price_asc' => 'Price: Low to High', 'price_desc' => 'Price: High to Low'];
        if ($filters['q'] !== '') {
            $sorts = ['relevance' => 'Most relevant'] + $sorts;
        }

        $this->view('pages/catalog', [
            'navCategories' => $nav,
            'pageTitle' => $mode === 'search' && $filters['q'] !== '' ? 'Search: ' . $filters['q'] : $heading,
            'heading' => $heading,
            'intro' => $intro,
            'mode' => $mode,
            'basePath' => $path,
            'filters' => $filters,
            'query' => $query,
            'sorts' => $sorts,
            'lockedCategory' => $category !== null,
            'searchedEmpty' => $searchedEmpty,
            'products' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['total_pages'],
            'perPage' => self::PER_PAGE,
        ]);
    }

    /** Validasi seluruh parameter dari URL; nilai tidak valid diabaikan. */
    private function filters(array $get, array $nav, ?array $lockedCategory): array
    {
        $q = $this->text($get['q'] ?? null, 100);

        $category = null;
        if ($lockedCategory !== null) {
            $category = $lockedCategory['slug'];
        } else {
            $c = $get['category'] ?? null;
            if (is_string($c) && in_array($c, array_column($nav, 'slug'), true)) {
                $category = $c;
            }
        }

        $min = $this->price($get['min_price'] ?? null);
        $max = $this->price($get['max_price'] ?? null);
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $allowed = ['newest', 'price_asc', 'price_desc'];
        if ($q !== '') {
            $allowed[] = 'relevance';
        }
        $sort = $get['sort'] ?? null;
        $sort = is_string($sort) && in_array($sort, $allowed, true) ? $sort : ($q !== '' ? 'relevance' : 'newest');

        return ['q' => $q, 'category' => $category, 'min_price' => $min, 'max_price' => $max, 'sort' => $sort];
    }

    private function text(mixed $v, int $max): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    private function price(mixed $v): ?int
    {
        if (!is_string($v) || $v === '' || strlen($v) > 9 || !ctype_digit($v)) {
            return null;
        }
        return min((int) $v, self::MAX_PRICE);
    }

    private function page(array $get): int
    {
        $p = $get['page'] ?? null;
        return is_string($p) && ctype_digit($p) && strlen($p) <= 6 ? max(1, (int) $p) : 1;
    }

    private function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9-]{1,180}$/', $slug);
    }

    /** @return array{0:int,1:float,2:string} */
    private static function sizeRank(string $size): array
    {
        $order = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
        $i = array_search(strtoupper($size), $order, true);
        if ($i !== false) {
            return [0, (float) $i, ''];
        }
        if (is_numeric($size)) {
            return [1, (float) $size, ''];
        }
        return [2, 0.0, $size];
    }
}
