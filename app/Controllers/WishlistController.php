<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Services\CartService;
use App\Services\WishlistService;

final class WishlistController extends Controller
{
    public function show(): void
    {
        $this->view('pages/wishlist', [
            'pageTitle' => 'Wishlist',
            'items' => WishlistService::forRequest()->items(),
            'flash' => flash_get('wishlist'),
        ]);
    }

    public function add(): void
    {
        $this->toggle(true);
    }

    public function remove(): void
    {
        $this->toggle(false);
    }

    public function move(): void
    {
        $this->requireCsrf();
        $result = WishlistService::forRequest()->moveToCart($this->intParam($_POST['product_id'] ?? null), CartService::forRequest());

        if ($result['ok']) {
            flash_set('cart', 'success', $result['message']);
            $this->redirect('/cart');
        }
        if ($result['options'] && $result['slug'] !== null) {
            flash_set('cart', 'info', $result['message']);
            $this->redirect('/product/' . $result['slug']);
        }
        flash_set('wishlist', 'error', $result['message']);
        $this->redirect('/wishlist');
    }

    private function toggle(bool $add): void
    {
        $this->requireCsrf();
        $service = WishlistService::forRequest();
        $productId = $this->intParam($_POST['product_id'] ?? null);
        $result = $add ? $service->add($productId) : $service->remove($productId);

        if ($this->wantsJson()) {
            $this->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'saved' => $service->isSaved($productId),
                'count' => $service->count(),
            ], $result['ok'] ? 200 : 422);
        }
        if (!$result['ok']) {
            flash_set('wishlist', 'error', $result['message']);
            $this->redirect('/wishlist');
        }
        $this->redirect($this->returnPath($_POST['return'] ?? null));
    }

    private function requireCsrf(): void
    {
        if (Csrf::verify($_POST['_csrf'] ?? null)) {
            return;
        }
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => 'Your session expired. Please try again.'], 403);
        }
        flash_set('wishlist', 'error', 'Your session expired. Please try again.');
        $this->redirect('/wishlist');
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function json(array $data, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
        exit;
    }

    /** Hanya path relatif internal; selain itu kembali ke /wishlist (mencegah open redirect). */
    private function returnPath(mixed $v): string
    {
        return safe_redirect_path($v, '/wishlist');
    }

    private function intParam(mixed $v): int
    {
        return is_string($v) && preg_match('/^-?\d{1,9}$/', $v) === 1 ? (int) $v : 0;
    }
}
