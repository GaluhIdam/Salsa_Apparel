<?php
declare(strict_types=1);
namespace App\Controllers\Admin;

use App\Core\AdminAuth;
use App\Repositories\AdminProductRepository;
use App\Services\ImageUploadService;
use App\Services\ProductAdminService;

final class ProductController extends AdminController
{
    private const PER_PAGE = 15;

    public function index(): void
    {
        $this->requireStaff('products.manage');
        $repo = new AdminProductRepository();
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['draft', 'active', 'archived'], true) ? $_GET['status'] : '';
        $categoryId = is_string($_GET['category'] ?? null) && preg_match('/^\d{1,9}$/', $_GET['category']) === 1 ? (int) $_GET['category'] : 0;
        $page = is_string($_GET['page'] ?? null) && preg_match('/^\d{1,6}$/', $_GET['page']) === 1 ? max(1, (int) $_GET['page']) : 1;

        $result = $repo->list(['q' => $q, 'status' => $status, 'category_id' => $categoryId], $page, self::PER_PAGE);
        $totalPages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        $page = min($page, $totalPages);
        if ($page !== (is_string($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1)) {
            $result = $repo->list(['q' => $q, 'status' => $status, 'category_id' => $categoryId], $page, self::PER_PAGE);
        }
        $this->render('admin/products/index', [
            'pageTitle' => 'Products',
            'products' => $result['items'], 'total' => $result['total'], 'page' => $page, 'totalPages' => $totalPages,
            'filters' => ['q' => $q, 'status' => $status, 'category' => $categoryId],
            'query' => ['q' => $q, 'status' => $status, 'category' => $categoryId > 0 ? $categoryId : null],
            'categories' => $repo->categoriesForSelect(),
            'flash' => flash_get('admin'),
        ]);
    }

    public function create(): void
    {
        $this->requireStaff('products.manage');
        $this->render('admin/products/form', [
            'pageTitle' => 'New product', 'product' => null, 'images' => [], 'variants' => [],
            'categories' => (new AdminProductRepository())->categoriesForSelect(),
            'canInventory' => AdminAuth::can('inventory.manage'), 'maxImages' => ProductAdminService::MAX_IMAGES,
            'flash' => flash_get('admin'),
        ]);
    }

    public function store(): void
    {
        $user = $this->requireStaff('products.manage');
        $this->requireCsrf('/admin/products');
        $result = (new ProductAdminService())->create((int) $user['id'], $_POST);
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors']);
            $this->redirect('/admin/products/new');
        }
        flash_set('admin', 'success', 'Product created as a draft. Add variants and images, then publish it.');
        $this->redirect('/admin/products/' . $result['id'] . '/edit');
    }

    public function edit(string $id): void
    {
        $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $repo = new AdminProductRepository();
        $product = $pid > 0 ? $repo->find($pid) : null;
        if ($product === null) {
            $this->notFound();
            return;
        }
        $this->render('admin/products/form', [
            'pageTitle' => 'Edit product', 'product' => $product,
            'images' => $repo->images($pid), 'variants' => $repo->variants($pid),
            'categories' => $repo->categoriesForSelect(),
            'canInventory' => AdminAuth::can('inventory.manage'), 'maxImages' => ProductAdminService::MAX_IMAGES,
            'flash' => flash_get('admin'),
        ]);
    }

    public function update(string $id): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $this->requireCsrf('/admin/products/' . $pid . '/edit');
        $result = (new ProductAdminService())->update((int) $user['id'], $pid, $_POST);
        if (!empty($result['not_found'])) {
            $this->notFound();
            return;
        }
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors'] + ['_section' => 'details']);
            flash_set('admin', 'error', 'Please correct the highlighted fields.');
            $this->redirect('/admin/products/' . $pid . '/edit');
        }
        flash_set('admin', 'success', 'Product updated.');
        $this->redirect('/admin/products/' . $pid . '/edit');
    }

    public function archive(string $id): void
    {
        $this->statusAction($id, 'archive', 'Product archived. It is no longer visible in the shop.');
    }

    public function restore(string $id): void
    {
        $this->statusAction($id, 'restore', 'Product restored as a draft. Publish it when ready.');
    }

    public function destroy(string $id): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $this->requireCsrf('/admin/products/' . $pid . '/edit');
        $result = (new ProductAdminService())->delete((int) $user['id'], $pid);
        if (!$result['ok']) {
            flash_set('admin', 'error', (string) $result['error']);
            $this->redirect('/admin/products/' . $pid . '/edit');
        }
        flash_set('admin', 'success', 'Product deleted permanently.');
        $this->redirect('/admin/products');
    }

    // ---- varian & stok ----

    public function addVariant(string $id): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $back = '/admin/products/' . $pid . '/edit#variants';
        $this->requireCsrf($back);
        $result = (new ProductAdminService())->addVariant((int) $user['id'], $pid, $_POST, AdminAuth::can('inventory.manage'));
        $this->flashResult($result['ok'], $result['errors'], 'Variant added.');
        $this->redirect($back);
    }

    public function updateVariant(string $id, string $variantId): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $back = '/admin/products/' . $pid . '/edit#variants';
        $this->requireCsrf($back);
        $result = (new ProductAdminService())->updateVariant((int) $user['id'], $pid, $this->idParam($variantId), $_POST);
        $this->flashResult($result['ok'], $result['errors'], 'Variant updated.');
        $this->redirect($back);
    }

    public function setStock(string $id, string $variantId): void
    {
        $user = $this->requireStaff('inventory.manage');
        $pid = $this->idParam($id);
        $back = '/admin/products/' . $pid . '/edit#variants';
        $this->requireCsrf($back);
        $result = (new ProductAdminService())->setStock((int) $user['id'], $pid, $this->idParam($variantId), $_POST);
        $this->flashResult($result['ok'], $result['errors'], !empty($result['changed']) ? 'Stock updated and recorded in the stock history.' : 'Stock unchanged.');
        $this->redirect($back);
    }

    // ---- gambar ----

    public function uploadImages(string $id): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $back = '/admin/products/' . $pid . '/edit#images';
        $this->requireCsrf($back);
        $files = ImageUploadService::normalize($_FILES['images'] ?? null);
        $alt = is_string($_POST['alt_text'] ?? null) ? $_POST['alt_text'] : '';
        $result = (new ProductAdminService())->addImages((int) $user['id'], $pid, $files, $alt);
        if ($result['ok']) {
            flash_set('admin', 'success', $result['added'] . ' image(s) uploaded.');
        } else {
            flash_set('admin', 'error', implode(' ', $result['errors']));
        }
        $this->redirect($back);
    }

    public function deleteImage(string $id, string $imageId): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $back = '/admin/products/' . $pid . '/edit#images';
        $this->requireCsrf($back);
        $result = (new ProductAdminService())->deleteImage((int) $user['id'], $pid, $this->idParam($imageId));
        flash_set('admin', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Image deleted.' : (string) $result['error']);
        $this->redirect($back);
    }

    public function primaryImage(string $id, string $imageId): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $back = '/admin/products/' . $pid . '/edit#images';
        $this->requireCsrf($back);
        $result = (new ProductAdminService())->makePrimaryImage((int) $user['id'], $pid, $this->idParam($imageId));
        flash_set('admin', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Primary image updated.' : (string) $result['error']);
        $this->redirect($back);
    }

    private function statusAction(string $id, string $method, string $success): void
    {
        $user = $this->requireStaff('products.manage');
        $pid = $this->idParam($id);
        $this->requireCsrf('/admin/products/' . $pid . '/edit');
        $result = (new ProductAdminService())->{$method}((int) $user['id'], $pid);
        flash_set('admin', $result['ok'] ? 'success' : 'error', $result['ok'] ? $success : (string) $result['error']);
        $this->redirect('/admin/products/' . $pid . '/edit');
    }

    /** @param array<string,string> $errors */
    private function flashResult(bool $ok, array $errors, string $success): void
    {
        flash_set('admin', $ok ? 'success' : 'error', $ok ? $success : implode(' ', $errors));
    }
}
