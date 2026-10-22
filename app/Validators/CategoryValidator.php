<?php
declare(strict_types=1);
namespace App\Validators;

final class CategoryValidator
{
    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function validate(array $in): array
    {
        $errors = [];
        $name = is_string($in['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $in['name'])) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            $errors['name'] = 'Category name must be 2–80 characters.';
        }
        $slug = is_string($in['slug'] ?? null) ? strtolower(trim($in['slug'])) : '';
        if ($slug !== '' && (strlen($slug) > 100 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1)) {
            $errors['slug'] = 'Slug may only contain lowercase letters, numbers, and single hyphens (max 100).';
        }
        $description = is_string($in['description'] ?? null) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $in['description'])) : '';
        if (mb_strlen($description) > 500) {
            $errors['description'] = 'Description must be at most 500 characters.';
        }
        $order = is_string($in['sort_order'] ?? null) ? trim($in['sort_order']) : '0';
        if ($order === '') {
            $order = '0';
        }
        if (preg_match('/^\d{1,4}$/', $order) !== 1) {
            $errors['sort_order'] = 'Sort order must be a whole number between 0 and 9999.';
            $order = '0';
        }
        return [[
            'name' => $name, 'slug' => $slug, 'description' => $description,
            'sort_order' => (int) $order, 'is_active' => !empty($in['is_active']),
        ], $errors];
    }
}
