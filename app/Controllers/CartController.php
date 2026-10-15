<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Services\CartService;

final class CartController extends Controller
{
    public function show(): void
    {
        $cart = CartService::forRequest()->view();
        $this->view('pages/cart', [
            'pageTitle' => 'Shopping Bag',
            'cart' => $cart,
            'flash' => flash_get('cart'),
        ]);
    }

    public function add(): void
    {
        $slug = $this->slugParam($_POST['product_slug'] ?? null);
        $back = $slug !== null ? '/product/' . $slug : '/cart';
        $this->requireCsrf($back);

        $productId = $this->intParam($_POST['product_id'] ?? null);
        $result = CartService::forRequest()->add(
            $this->intParam($_POST['variant_id'] ?? null),
            $this->intParam($_POST['quantity'] ?? null),
            $productId > 0 ? $productId : null
        );

        flash_set('cart', $result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok']) {
            $this->redirect('/cart');
        }
        $this->redirect($result['slug'] !== null ? '/product/' . $result['slug'] : $back);
    }

    public function update(): void
    {
        $this->requireCsrf('/cart');
        $result = CartService::forRequest()->setQuantity(
            $this->intParam($_POST['variant_id'] ?? null),
            $this->intParam($_POST['quantity'] ?? null)
        );
        flash_set('cart', $result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('/cart');
    }

    public function remove(): void
    {
        $this->requireCsrf('/cart');
        $result = CartService::forRequest()->remove($this->intParam($_POST['variant_id'] ?? null));
        flash_set('cart', $result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('/cart');
    }

    private function requireCsrf(string $back): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('cart', 'error', 'Your session expired. Please try again.');
            $this->redirect($back);
        }
    }

    /** Integer positif/negatif sederhana; nilai tidak valid menjadi 0 (ditolak oleh service). */
    private function intParam(mixed $v): int
    {
        return is_string($v) && preg_match('/^-?\d{1,9}$/', $v) === 1 ? (int) $v : 0;
    }

    private function slugParam(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[a-z0-9-]{1,180}$/', $v) === 1 ? $v : null;
    }
}
