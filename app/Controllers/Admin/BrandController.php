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
 * Brand CRUD: name, unique slug, description, logo upload, sort order,
 * publication status (is_active), search, and pagination.
 *
 * Delete safety: a brand with product_models cannot be deleted unless
 * a reassignment target is provided. Historical product references are
 * preserved by moving models to the target brand in a transaction.
 */
final class BrandController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $q = trim((string) $request->query('q', ''));
        $result = (new CatalogRepository())->searchBrands($q, $page, $perPage);

        return Response::html(View::render('admin/brands/index', [
            'pageTitle' => 'Brands',
            'brands' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => $q,
        ]));
    }

    public function store(Request $request): Response
    {
        $errors = $this->validate($request, null);

        $logoPath = $this->handleLogoUpload($request, $errors);

        if ($errors !== []) {
            flash_error(implode(' ', $errors));
            Session::flashInput($request->post());
            return Response::redirect(url('/admin/brands'));
        }

        $data = $request->post();
        $repo = new CatalogRepository();

        $id = $repo->createBrandFull([
            'name' => $data['name'],
            'slug' => str_slug($data['slug']),
            'description' => $data['description'] ?? null,
            'logo_path' => $logoPath,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        AuditService::record('brand.created', 'brand', $id, [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ], $request);

        flash_success('Brand created.');
        return Response::redirect(url('/admin/brands'));
    }

    public function update(string $id, Request $request): Response
    {
        $repo = new CatalogRepository();
        $brand = $repo->findBrand((int) $id);
        if ($brand === null) {
            throw new NotFoundException('Brand not found.');
        }

        $errors = $this->validate($request, (int) $id, $repo);

        $logoPath = $this->handleLogoUpload($request, $errors);

        if ($errors !== []) {
            flash_error(implode(' ', $errors));
            return Response::redirect(url('/admin/brands'));
        }

        $data = $request->post();
        $repo->updateBrandFull((int) $id, [
            'name' => $data['name'],
            'slug' => str_slug($data['slug']),
            'description' => $data['description'] ?? null,
            'logo_path' => $logoPath ?? $brand['logo_path'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active']),
        ]);

        AuditService::record('brand.updated', 'brand', (int) $id, [
            'name' => $data['name'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'logo_changed' => $logoPath !== null,
        ], $request);

        flash_success('Brand updated.');
        return Response::redirect(url('/admin/brands'));
    }

    public function destroy(string $id, Request $request): Response
    {
        $repo = new CatalogRepository();
        $brand = $repo->findBrand((int) $id);
        if ($brand === null) {
            throw new NotFoundException('Brand not found.');
        }

        $reassignTo = (int) $request->input('reassign_to', 0);
        $repo->deleteBrand((int) $id, $reassignTo > 0 ? $reassignTo : null);

        AuditService::record('brand.deleted', 'brand', (int) $id, [
            'name' => $brand['name'],
            'reassigned_to' => $reassignTo > 0 ? $reassignTo : null,
        ], $request);

        flash_success('Brand deleted.');
        return Response::redirect(url('/admin/brands'));
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

        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Name is required (min 2 characters).';
        }

        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            $errors[] = 'Slug is required (lowercase letters, numbers, hyphens only).';
        } elseif ($repo->brandSlugExists($slug, $excludeId)) {
            $errors[] = 'A brand with this slug already exists.';
        }

        return $errors;
    }

    private function handleLogoUpload(Request $request, array &$errors): ?string
    {
        $file = $request->file('logo');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed.';
            return null;
        }

        $maxBytes = (int) Config::get('security.uploads.max_bytes', 4 * 1024 * 1024);
        $allowedMimes = Config::get('security.uploads.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if (($file['size'] ?? 0) > $maxBytes) {
            $errors[] = 'Logo must be at most ' . round($maxBytes / 1024 / 1024, 1) . ' MiB.';
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true)) {
            $errors[] = 'Logo must be JPEG, PNG, or WebP.';
            return null;
        }

        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $safeName = 'brand-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = BASE_PATH . '/public/uploads/brands';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0770, true);
        }
        $destPath = $uploadDir . '/' . $safeName;

        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destPath);
        } else {
            rename($file['tmp_name'], $destPath);
        }

        return 'uploads/brands/' . $safeName;
    }
}
