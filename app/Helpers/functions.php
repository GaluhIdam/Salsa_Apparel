<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\SettingRepository;
use App\Services\CartService;
use App\Services\WishlistService;

function config(string $name): array
{
    static $cache = [];
    return $cache[$name] ??= require BASE_PATH . '/config/' . $name . '.php';
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rupiah(float|int|string $amount): string
{
    return 'Rp' . number_format((float) $amount, 0, ',', '.');
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return '/assets/' . ltrim($path, '/');
}

/** URL gambar dari path di database; fallback ke placeholder netral. */
function image_url(?string $path): string
{
    if ($path === null || $path === '') {
        return asset('images/placeholders/default.svg');
    }
    if (str_starts_with($path, 'assets/') || str_starts_with($path, 'uploads/')) {
        return '/' . $path;
    }
    return '/uploads/' . ltrim($path, '/');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function flash_set(string $key, string $type, string $message): void
{
    $_SESSION['flash'][$key] = ['type' => $type, 'message' => $message];
}

function flash_get(string $key): ?array
{
    $flash = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $flash;
}

function setting(string $key, string $default = ''): string
{
    static $all = null;
    $all ??= (new SettingRepository())->all();
    $value = $all[$key] ?? '';
    return $value !== '' ? $value : $default;
}

/** Total unit di cart (bukan jumlah jenis produk). 0 tanpa query jika belum ada cart. */
function cart_count(): int
{
    static $count = null;
    return $count ??= CartService::forRequest()->count();
}

function component(string $name, array $vars = []): void
{
    View::partial('components/' . $name, $vars);
}

function icon(string $name, int $size = 20): string
{
    static $paths = [
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'heart' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'bag' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'minus' => '<path d="M5 12h14"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'instagram' => '<rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/>',
        'tiktok' => '<path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** URL dengan query string; parameter kosong dibuang. */
function url_with_query(string $path, array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    return url($path) . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

/** ID produk yang tersimpan di wishlist guest (satu query per request; tanpa query jika belum ada cookie). */
function wishlist_ids(): array
{
    static $ids = null;
    return $ids ??= array_flip(WishlistService::forRequest()->savedIds());
}

function wishlist_has(int $productId): bool
{
    return isset(wishlist_ids()[$productId]);
}

function wishlist_count(): int
{
    return count(wishlist_ids());
}

/** Pelanggan yang sedang login (atau null). */
function auth_user(): ?array
{
    return Auth::user();
}

/** Hanya path relatif internal yang aman; selain itu $default (mencegah open redirect/CRLF). */
function safe_redirect_path(mixed $value, string $default): string
{
    if (!is_string($value) || strlen($value) > 300 || preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%+,:@~]*$#', $value) !== 1) {
        return $default;
    }
    return $value;
}

/** Simpan input lama (tanpa password) dan error validasi untuk satu redirect berikutnya. */
function form_state_set(array $old, array $errors): void
{
    unset($old['password'], $old['password_confirmation'], $old['current_password'], $old['new_password'], $old['new_password_confirmation']);
    $_SESSION['_old'] = $old;
    $_SESSION['_errors'] = $errors;
}

function form_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = ['old' => $_SESSION['_old'] ?? [], 'errors' => $_SESSION['_errors'] ?? []];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }
    return $state;
}

function old(string $key, string $default = ''): string
{
    $v = form_state()['old'][$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function field_error(string $key): ?string
{
    $v = form_state()['errors'][$key] ?? null;
    return is_string($v) ? $v : null;
}
