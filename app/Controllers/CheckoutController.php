<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Repositories\AddressRepository;
use App\Core\Controller;
use App\Core\Csrf;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\PaymentAttemptService;
use DomainException;
use Throwable;

final class CheckoutController extends Controller
{
    public function show(): void
    {
        $cart = CartService::forRequest()->view();

        if (empty($cart['items'])) {
            flash_set('cart', 'error', 'Keranjang belanja masih kosong.');
            $this->redirect('/cart');
        }

        $userId = Auth::id();
        $addresses = $userId !== null
            ? (new AddressRepository())->listByUser($userId)
            : [];

        $defaultAddress = null;

        foreach ($addresses as $address) {
            if (in_array($address['is_default'], [true, 1, '1', 't', 'true'], true)) {
                $defaultAddress = $address;
                break;
            }
        }

        $defaultAddress ??= $addresses[0] ?? null;

        $this->view('pages/checkout', [
            'pageTitle' => 'Checkout',
            'cart' => $cart,
            'flash' => flash_get('checkout'),
            'addresses' => $addresses,
            'defaultAddress' => $defaultAddress,
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect('/checkout');
            return;
        }

        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('checkout', 'error', 'Sesi kamu kedaluwarsa. Silakan coba lagi.');
            $this->redirect('/checkout');
            return;
        }

        try {
            // Validate gateway configuration before reserving stock/clearing the cart.
            $paymentService = new PaymentAttemptService();
            $result = (new CheckoutService())->createOrder(
                ['email' => $_POST['email'] ?? ''],
                [
                    'saved_address_id' => $_POST['saved_address_id'] ?? '',
                    'recipient_name' => $_POST['recipient_name'] ?? '',
                    'recipient_phone' => $_POST['recipient_phone'] ?? '',
                    'address' => $_POST['address'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'province' => $_POST['province'] ?? '',
                    'postal_code' => $_POST['postal_code'] ?? '',
                    'courier' => $_POST['courier'] ?? '',
                    'service' => $_POST['service'] ?? '',
                ]
            );

            $_SESSION['pending_order'] = [
                'order_id' => (int) $result['order_id'],
                'order_number' => $result['order_number'],
                'access_token' => $result['access_token'],
            ];

            $payment = $paymentService->startPayment(
                (int) $result['order_id'],
                $result['access_token']
            );

            if (!empty($payment['redirect_url'])) {
                header('Location: ' . $payment['redirect_url'], true, 303);
                exit;
            }

            $this->redirect('/order-status');
        } catch (DomainException $e) {
            flash_set(isset($result) ? 'order-status' : 'checkout', 'error', $e->getMessage());
            $this->redirect(isset($result) ? '/order-status' : '/checkout');
            return;
        } catch (Throwable $e) {
            error_log('Checkout error: ' . $e->getMessage());

            flash_set(
                isset($result) ? 'order-status' : 'checkout',
                'error',
                'Pesanan atau pembayaran belum dapat diproses. Periksa status pesanan sebelum mencoba kembali.'
            );

            $this->redirect(isset($result) ? '/order-status' : '/checkout');
            return;
        }
    }

    public function pay(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('order-status', 'error', 'Sesi kamu kedaluwarsa. Silakan coba lagi.');
            $this->redirect('/order-status');
            return;
        }

        $pending = $_SESSION['pending_order'] ?? null;
        try {
            if (!is_array($pending) || !is_int($pending['order_id'] ?? null)
                || !is_string($pending['access_token'] ?? null)) {
                throw new DomainException('Sesi akses pesanan sudah berakhir.');
            }
            // Continue the existing order; never reserve stock or create another order.
            $payment = (new PaymentAttemptService())->startPayment(
                $pending['order_id'], $pending['access_token']
            );
            header('Location: ' . $payment['redirect_url'], true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Resume payment error: ' . $e->getMessage());
            flash_set('order-status', 'error', $e instanceof DomainException
                ? $e->getMessage()
                : 'Pembayaran belum dapat dilanjutkan. Periksa kembali status pesanan atau hubungi admin.');
            $this->redirect('/order-status');
        }
    }
}
