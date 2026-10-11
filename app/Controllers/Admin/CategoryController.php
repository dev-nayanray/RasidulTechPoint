<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CatalogRepository;
use App\Services\AuditService;
use App\Support\Config;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Category CRUD with name, unique slug, description, parent hierarchy,
 * sort order, active status, SEO fields (seo_title, seo_description),
 * and image upload.
 *
 * Hierarchy validation:
 *  - A category cannot be its own parent.
 *  - A parent cannot create a circular reference (A→B→A).
 *
 * Delete safety:
 *  - A category with product_models cannot be deleted unless a
 *    reassignment target is provided. The models are moved to the
 *    target category in a single transaction.
 *  - Children are reparented to the deleted category's parent.
 */
final class CategoryController
{
    public function index(): Response
    {
        $repo = new CatalogRepository();
        return Response::html(View::render('admin/categories/index', [
            'pageTitle' => 'Categories',
            'categories' => $repo->categories(),
        ]));
    }

    public function store(Request $request): Response
    {
        $errors = $this->validate($request, null);

        if ($errors !== []) {
            Session::flashInput($request->post());
            flash_error(implode(' ', $errors));
            return Response::redirect(url('/admin/categories'));
        }

        $data = $request->post();
        $repo = new CatalogRepository();

        $imagePath = $this->handleImageUpload($request, $errors);
        if ($errors !== []) {
            flash_error(implode(' ', $errors));
            return Response::redirect(url('/admin/categories'));
        }

        $id = $repo->createCategory([
            'name' => $data['name'],
            'slug' => str_slug($data['slug']),
            'parent_id' => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            'description' => $data['description'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'image_path' => $imagePath,
        ]);

        AuditService::record('category.created', 'category', $id, [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'parent_id' => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ], $request);

        flash_success('Category created.');
        return Response::redirect(url('/admin/categories'));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new CatalogRepository();
        $category = $repo->findCategory((int) $id);
        if ($category === null) {
            throw new NotFoundException('Category not found.');
        }

        $errors = $this->validate($request, (int) $id, $repo);

        if ($errors !== []) {
            flash_error(implode(' ', $errors));
            return Response::redirect(url('/admin/categories'));
        }

        $data = $request->post();
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;

        // Circular hierarchy check.
        if ($parentId !== null && $repo->wouldCreateCycle((int) $id, $parentId)) {
            flash_error('Cannot set that parent — it would create a circular reference.');
            return Response::redirect(url('/admin/categories'));
        }

        $imagePath = $this->handleImageUpload($request, $errors);
        if ($errors !== []) {
            flash_error(implode(' ', $errors));
            return Response::redirect(url('/admin/categories'));
        }

        // If no new image uploaded, keep the existing one.
        $updateData = [
            'name' => $data['name'],
            'slug' => str_slug($data['slug']),
            'parent_id' => $parentId,
            'description' => $data['description'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'image_path' => $imagePath ?? $category['image_path'],
            'is_active' => !empty($data['is_active']),
        ];

        $repo->updateCategoryFull((int) $id, $updateData);
        AuditService::record('category.updated', 'category', (int) $id, [
            'name' => $data['name'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'image_changed' => $imagePath !== null,
        ], $request);

        flash_success('Category updated.');
        return Response::redirect(url('/admin/categories'));
    }

    public function destroy(string $id, Request $request): Response
    {
        $repo = new CatalogRepository();
        $category = $repo->findCategory((int) $id);
        if ($category === null) {
            throw new NotFoundException('Category not found.');
        }

        $reassignTo = (int) $request->input('reassign_to', 0);
        $repo->deleteCategory((int) $id, $reassignTo > 0 ? $reassignTo : null);

        AuditService::record('category.deleted', 'category', (int) $id, [
            'name' => $category['name'],
            'reassigned_to' => $reassignTo > 0 ? $reassignTo : null,
        ], $request);

        flash_success('Category deleted.');
        return Response::redirect(url('/admin/categories'));
    }

    /**
     * @return array<int,string>
     */
    private function validate(Request $request, ?int $excludeId, ?CatalogRepository $repo = null): array
    {
        $errors = [];
        $repo ??= new CatalogRepository();

        $name = trim((string) $request->input('name', ''));
        $slug = trim((string) $request->input('slug', ''));
        $parentId = (int) ($request->input('parent_id') ?? 0);

        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Name is required (min 2 characters).';
        }

        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            $errors[] = 'Slug is required (lowercase letters, numbers, hyphens only).';
        } else {
            // Uniqueness check.
            $sql = 'SELECT COUNT(*) FROM categories WHERE slug = ?';
            $params = [$slug];
            if ($excludeId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $excludeId;
            }
            $countStmt = \App\Support\Database\Connection::pdo()->prepare($sql);
            $countStmt->execute($params);
            if ((int) $countStmt->fetchColumn() > 0) {
                $errors[] = 'A category with this slug already exists.';
            }
        }

        // Self-parent check.
        if ($excludeId !== null && $parentId === $excludeId) {
            $errors[] = 'A category cannot be its own parent.';
        }

        return $errors;
    }

    private function handleImageUpload(Request $request, array &$errors): ?string
    {
        $file = $request->file('image');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed.';
            return null;
        }

        $maxBytes = (int) Config::get('security.uploads.max_bytes', 4 * 1024 * 1024);
        $allowedMimes = Config::get('security.uploads.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if (($file['size'] ?? 0) > $maxBytes) {
            $errors[] = 'Image must be at most ' . round($maxBytes / 1024 / 1024, 1) . ' MiB.';
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true)) {
            $errors[] = 'Image must be JPEG, PNG, or WebP.';
            return null;
        }

        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $safeName = 'category-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = BASE_PATH . '/public/uploads/categories';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0770, true);
        }
        $destPath = $uploadDir . '/' . $safeName;

        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destPath);
        } else {
            rename($file['tmp_name'], $destPath);
        }

        return 'uploads/categories/' . $safeName;
    }
}
