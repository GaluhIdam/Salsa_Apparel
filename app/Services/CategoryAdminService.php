<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Database;
use App\Core\Str;
use App\Repositories\AdminCategoryRepository;
use App\Repositories\AuditLogRepository;
use App\Validators\CategoryValidator;

final class CategoryAdminService
{
    private AdminCategoryRepository $repo;
    private AuditLogRepository $audit;
    private CategoryValidator $validator;
    private ImageUploadService $images;

    public function __construct(?ImageUploadService $images = null)
    {
        $this->repo = new AdminCategoryRepository();
        $this->audit = new AuditLogRepository();
        $this->validator = new CategoryValidator();
        $this->images = $images ?? new ImageUploadService();
    }

    /** @param ?array $file entri $_FILES tunggal atau null @return array{ok:bool,errors:array<string,string>,old:array<string,string>,id:?int} */
    public function create(int $staffId, array $in, ?array $file, ?string $ip = null, bool $requireHttpUpload = true): array
    {
        [$data, $errors] = $this->validator->validate($in);
        $this->checkUnique($data, $errors, null);
        $old = $this->old($data);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'old' => $old, 'id' => null];
        }
        $imagePath = null;
        if ($file !== null) {
            $r = $this->images->store($file, 'categories', $requireHttpUpload);
            if (!$r['ok']) {
                return ['ok' => false, 'errors' => ['image' => (string) $r['error']], 'old' => $old, 'id' => null];
            }
            $imagePath = $r['path'];
        }
        $ip ??= AuthService::clientIp();
        try {
            $id = (int) Database::transaction(function () use ($staffId, $data, $imagePath, $ip): int {
                $id = $this->repo->insert($data, $imagePath);
                $this->audit->log($staffId, 'category.create', 'category', $id, ['name' => $data['name'], 'slug' => $data['slug'], 'is_active' => $data['is_active']], $ip);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->images->delete($imagePath);
            throw $e;
        }
        return ['ok' => true, 'errors' => [], 'old' => [], 'id' => $id];
    }

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>,not_found?:bool} */
    public function update(int $staffId, int $id, array $in, ?array $file, ?string $ip = null, bool $requireHttpUpload = true): array
    {
        [$data, $errors] = $this->validator->validate($in);
        $this->checkUnique($data, $errors, $id);
        $old = $this->old($data);
        $ip ??= AuthService::clientIp();
        $newPath = null;
        $oldPath = null;
        $result = Database::transaction(function () use ($staffId, $id, $data, $errors, $old, $file, $ip, $requireHttpUpload, &$newPath, &$oldPath): array {
            $current = $this->repo->find($id, true);
            if ($current === null) {
                return ['ok' => false, 'errors' => ['form' => 'Category not found.'], 'old' => $old, 'not_found' => true];
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors, 'old' => $old];
            }
            $path = $current['image_path'];
            if ($file !== null) {
                $r = $this->images->store($file, 'categories', $requireHttpUpload);
                if (!$r['ok']) {
                    return ['ok' => false, 'errors' => ['image' => (string) $r['error']], 'old' => $old];
                }
                $newPath = $r['path'];
                $oldPath = $path;
                $path = $newPath;
            }
            $this->repo->update($id, $data, $path);
            $this->audit->log(
                $staffId, 'category.update', 'category', $id,
                ['name' => $data['name'], 'slug' => $data['slug'], 'is_active' => $data['is_active'], 'sort_order' => $data['sort_order']], $ip,
                ['name' => $current['name'], 'slug' => $current['slug'], 'is_active' => $this->truthy($current['is_active']), 'sort_order' => (int) $current['sort_order']]
            );
            return ['ok' => true, 'errors' => [], 'old' => []];
        });
        if ($result['ok'] && $oldPath !== null) {
            $this->images->delete($oldPath);
        } elseif (!$result['ok'] && $newPath !== null) {
            $this->images->delete($newPath);
        }
        return $result;
    }

    /** Kategori hanya dapat dihapus jika tidak memiliki produk (produk RESTRICT di database); selain itu nonaktifkan. */
    public function delete(int $staffId, int $id, ?string $ip = null): array
    {
        $ip ??= AuthService::clientIp();
        $path = null;
        $result = Database::transaction(function () use ($staffId, $id, $ip, &$path): array {
            $current = $this->repo->find($id, true);
            if ($current === null) {
                return ['ok' => false, 'error' => 'Category not found.'];
            }
            if ((int) $current['product_count'] > 0) {
                return ['ok' => false, 'error' => 'This category still has ' . (int) $current['product_count'] . ' product(s). Move or delete them first, or deactivate the category instead.'];
            }
            $path = $current['image_path'];
            $this->repo->delete($id);
            $this->audit->log($staffId, 'category.delete', 'category', $id, null, $ip, ['name' => $current['name'], 'slug' => $current['slug']]);
            return ['ok' => true, 'error' => null];
        });
        if ($result['ok']) {
            $this->images->delete($path);
        }
        return $result;
    }

    private function checkUnique(array &$data, array &$errors, ?int $excludeId): void
    {
        if (!isset($errors['name']) && $this->repo->nameExists($data['name'], $excludeId)) {
            $errors['name'] = 'A category with this name already exists.';
        }
        if (isset($errors['slug'])) {
            return;
        }
        if ($data['slug'] === '') {
            $base = Str::slug((string) $data['name'], 90);
            $base = $base !== '' ? $base : 'category';
            $slug = $base;
            for ($n = 2; $this->repo->slugExists($slug, $excludeId) && $n < 500; $n++) {
                $slug = $base . '-' . $n;
            }
            $data['slug'] = $slug;
        } elseif ($this->repo->slugExists($data['slug'], $excludeId)) {
            $errors['slug'] = 'This slug is already used by another category.';
        }
    }

    /** @return array<string,string> */
    private function old(array $d): array
    {
        return ['name' => (string) $d['name'], 'slug' => (string) $d['slug'], 'description' => (string) $d['description'],
            'sort_order' => (string) $d['sort_order'], 'is_active' => $d['is_active'] ? '1' : ''];
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}
