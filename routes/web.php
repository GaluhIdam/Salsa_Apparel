<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AddressController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CatalogController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\MidtransWebhookController;
use App\Controllers\NewsletterController;
use App\Controllers\OrderStatusController;
use App\Controllers\PageController;
use App\Controllers\PasswordResetController;
use App\Controllers\WishlistController;
use App\Core\Router;

return static function (Router $r): void {
    $home = new HomeController();
    $catalog = new CatalogController();
    $cart = new CartController();
    $auth = new AuthController();
    $account = new AccountController();
    $wishlist = new WishlistController();
    $pages = new PageController();
    $newsletter = new NewsletterController();
    $checkout = new CheckoutController();
    $midtransWebhook = new MidtransWebhookController();
    $orderStatus = new OrderStatusController();
    $r->get('/', fn() => $home->index());
    $r->post('/newsletter', fn() => $newsletter->subscribe());

    // katalog
    $r->get('/shop', fn() => $catalog->shop());
    $r->get('/category/{slug}', fn(array $p) => $catalog->category($p['slug']));
    $r->get('/product/{slug}', fn(array $p) => $catalog->product($p['slug']));
    $r->get('/search', fn() => $catalog->search());

    // cart (guest, tersimpan di database)
    $r->get('/cart', fn() => $cart->show());
    $r->post('/cart/add', fn() => $cart->add());
    $r->post('/cart/update', fn() => $cart->update());
    $r->post('/cart/remove', fn() => $cart->remove());

    // wishlist guest (tersimpan di database)
    $r->get('/wishlist', fn() => $wishlist->show());
    $r->post('/wishlist/add', fn() => $wishlist->add());
    $r->post('/wishlist/remove', fn() => $wishlist->remove());
    $r->post('/wishlist/move', fn() => $wishlist->move());

    // autentikasi dan akun pelanggan
    $r->get('/login', fn() => $auth->showLogin());
    $r->post('/login', fn() => $auth->login());
    $r->get('/register', fn() => $auth->showRegister());
    $r->post('/register', fn() => $auth->register());
    $r->post('/logout', fn() => $auth->logout());
    $r->get('/account', fn() => $account->index());
    $r->post('/account/profile', fn() => $account->updateProfile());
    $r->post('/account/password', fn() => $account->changePassword());
    // Password reset
    $passwordReset = new PasswordResetController();

    $r->get('/forgot-password', fn() => $passwordReset->showForgot());
    $r->post('/forgot-password', fn() => $passwordReset->sendForgot());

    $r->get('/reset-password', fn() => $passwordReset->showReset());
    $r->post('/reset-password', fn() => $passwordReset->reset());

    // Address book pelanggan
    $addresses = new AddressController();

    $r->get('/account/addresses', fn() => $addresses->index());
    $r->get('/account/addresses/new', fn() => $addresses->create());
    $r->post('/account/addresses', fn() => $addresses->store());
    $r->get('/account/addresses/{id}/edit', fn(array $p) => $addresses->edit($p['id']));
    $r->post('/account/addresses/{id}', fn(array $p) => $addresses->update($p['id']));
    $r->post('/account/addresses/{id}/default', fn(array $p) => $addresses->setDefault($p['id']));
    $r->post('/account/addresses/{id}/delete', fn(array $p) => $addresses->delete($p['id']));

    // Dibangun pada fase berikutnya; sementara menampilkan halaman "coming soon".
    $r->get('/page/{slug}', fn(array $p) => $pages->staticPage($p['slug']));
    $r->get('/support', fn() => $pages->staticPage('support'));

    // Checkout
    $r->get('/checkout', fn() => $checkout->show());
    $r->post('/checkout', fn() => $checkout->store());


    $r->get('/order-status', fn() => $orderStatus->show());
    //Midtrans
    $r->post(
        '/webhooks/midtrans',
        fn() => $midtransWebhook->handle()
    );
};
