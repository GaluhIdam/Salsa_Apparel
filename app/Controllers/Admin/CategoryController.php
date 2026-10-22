<?php
declare(strict_types=1);
namespace App\Controllers\Admin;

use App\Repositories\AdminCategoryRepository;
use App\Services\CategoryAdminService;
use App\Services\ImageUploadService;

final class CategoryController extends AdminController
{
    public function index(): void
    {
        $this->requireStaff('categories.manage');
        $this->render('admin/categories/index', [
            'pageTitle' => 'Categories',
            'categories' => (new AdminCategoryRepository())->list(),
            'flash' => flash_get('admin'),
        ]);
    }

    public function create(): void
    {
        $this->requireStaff('categories.manage');
        $this->render('admin/categories/form', ['pageTitle' => 'New category', 'category' => null, 'flash' => flash_get('admin')]);
    }

    public function store(): void
    {
        $user = $this->requireStaff('categories.manage');
        $this->requireCsrf('/admin/categories');
        $result = (new CategoryAdminService())->create((int) $user['id'], $_POST, $this->file());
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors']);
            $this->redirect('/admin/categories/new');
        }
        flash_set('admin', 'success', 'Category created.');
        $this->redirect('/admin/categories');
    }

    public function edit(string $id): void
    {
        $this->requireStaff('categories.manage');
        $cid = $this->idParam($id);
        $category = $cid > 0 ? (new AdminCategoryRepository())->find($cid) : null;
        if ($category === null) {
            $this->notFound();
            return;
        }
        $this->render('admin/categories/form', ['pageTitle' => 'Edit category', 'category' => $category, 'flash' => flash_get('admin')]);
    }

    public function update(string $id): void
    {
        $user = $this->requireStaff('categories.manage');
        $cid = $this->idParam($id);
        $this->requireCsrf('/admin/categories');
        $result = (new CategoryAdminService())->update((int) $user['id'], $cid, $_POST, $this->file());
        if (!empty($result['not_found'])) {
            $this->notFound();
            return;
        }
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors'] + ['_section' => 'category']);
            $this->redirect('/admin/categories/' . $cid . '/edit');
        }
        flash_set('admin', 'success', 'Category updated.');
        $this->redirect('/admin/categories');
    }

    public function destroy(string $id): void
    {
        $user = $this->requireStaff('categories.manage');
        $cid = $this->idParam($id);
        $this->requireCsrf('/admin/categories');
        $result = (new CategoryAdminService())->delete((int) $user['id'], $cid);
        flash_set('admin', $result['ok'] ? 'success' : 'error', $result['ok'] ? 'Category deleted.' : (string) $result['error']);
        $this->redirect('/admin/categories');
    }

    /** Satu file gambar opsional dari field "image". */
    private function file(): ?array
    {
        $list = ImageUploadService::normalize($_FILES['image'] ?? null);
        return $list[0] ?? null;
    }
}
