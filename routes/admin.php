<?php
declare(strict_types=1);

use App\Controllers\Admin\AdminAuthController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ProductController;
use App\Core\Router;

/** Rute admin (Tahap A). Diperlukan dari routes/web.php. Setiap controller memeriksa login + permission. */
return static function (Router $r): void {
    $auth = new AdminAuthController();
    $home = new DashboardController();
    $products = new ProductController();
    $categories = new CategoryController();

    $r->get('/admin', fn() => $home->index());
    $r->get('/admin/login', fn() => $auth->showLogin());
    $r->post('/admin/login', fn() => $auth->login());
    $r->post('/admin/logout', fn() => $auth->logout());

    $r->get('/admin/products', fn() => $products->index());
    $r->get('/admin/products/new', fn() => $products->create());
    $r->post('/admin/products', fn() => $products->store());
    $r->get('/admin/products/{id}/edit', fn(array $p) => $products->edit($p['id']));
    $r->post('/admin/products/{id}', fn(array $p) => $products->update($p['id']));
    $r->post('/admin/products/{id}/archive', fn(array $p) => $products->archive($p['id']));
    $r->post('/admin/products/{id}/restore', fn(array $p) => $products->restore($p['id']));
    $r->post('/admin/products/{id}/delete', fn(array $p) => $products->destroy($p['id']));
    $r->post('/admin/products/{id}/variants', fn(array $p) => $products->addVariant($p['id']));
    $r->post('/admin/products/{id}/variants/{vid}', fn(array $p) => $products->updateVariant($p['id'], $p['vid']));
    $r->post('/admin/products/{id}/variants/{vid}/stock', fn(array $p) => $products->setStock($p['id'], $p['vid']));
    $r->post('/admin/products/{id}/images', fn(array $p) => $products->uploadImages($p['id']));
    $r->post('/admin/products/{id}/images/{iid}/delete', fn(array $p) => $products->deleteImage($p['id'], $p['iid']));
    $r->post('/admin/products/{id}/images/{iid}/primary', fn(array $p) => $products->primaryImage($p['id'], $p['iid']));

    $r->get('/admin/categories', fn() => $categories->index());
    $r->get('/admin/categories/new', fn() => $categories->create());
    $r->post('/admin/categories', fn() => $categories->store());
    $r->get('/admin/categories/{id}/edit', fn(array $p) => $categories->edit($p['id']));
    $r->post('/admin/categories/{id}', fn(array $p) => $categories->update($p['id']));
    $r->post('/admin/categories/{id}/delete', fn(array $p) => $categories->destroy($p['id']));
};
