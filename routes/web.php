<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CatalogController;
use App\Controllers\HomeController;
use App\Controllers\NewsletterController;
use App\Controllers\PageController;
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

    $r->get('/', fn() => $home->index());
    $r->post('/newsletter', fn() => $newsletter->subscribe());

    // Phase 4 — katalog
    $r->get('/shop', fn() => $catalog->shop());
    $r->get('/category/{slug}', fn(array $p) => $catalog->category($p['slug']));
    $r->get('/product/{slug}', fn(array $p) => $catalog->product($p['slug']));
    $r->get('/search', fn() => $catalog->search());

    // Phase 5 — cart (guest, tersimpan di database)
    $r->get('/cart', fn() => $cart->show());
    $r->post('/cart/add', fn() => $cart->add());
    $r->post('/cart/update', fn() => $cart->update());
    $r->post('/cart/remove', fn() => $cart->remove());

    // Phase 6 — wishlist guest (tersimpan di database)
    $r->get('/wishlist', fn() => $wishlist->show());
    $r->post('/wishlist/add', fn() => $wishlist->add());
    $r->post('/wishlist/remove', fn() => $wishlist->remove());
    $r->post('/wishlist/move', fn() => $wishlist->move());

    // Phase 7 — autentikasi dan akun pelanggan
    $r->get('/login', fn() => $auth->showLogin());
    $r->post('/login', fn() => $auth->login());
    $r->get('/register', fn() => $auth->showRegister());
    $r->post('/register', fn() => $auth->register());
    $r->post('/logout', fn() => $auth->logout());
    $r->get('/account', fn() => $account->index());
    $r->post('/account/profile', fn() => $account->updateProfile());
    $r->post('/account/password', fn() => $account->changePassword());

    // Dibangun pada fase berikutnya; sementara menampilkan halaman "coming soon".
    $r->get('/page/{slug}', fn(array $p) => $pages->staticPage($p['slug']));
};
