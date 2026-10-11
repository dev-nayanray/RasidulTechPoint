<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CatalogRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class ProductModelController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $catalog = new CatalogRepository();

        $result = $catalog->searchModels(
            trim((string) $request->query('q', '')),
            (int) $request->query('brand', 0),
            (int) $request->query('category', 0),
            $page,
            $perPage
        );

        return Response::html(View::render('admin/products/index', [
            'pageTitle' => 'Laptop models',
            'models' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'brandFilter' => (int) $request->query('brand', 0),
            'categoryFilter' => (int) $request->query('category', 0),
            'brands' => $catalog->brands(),
            'categories' => $catalog->categories(),
        ]));
    }

    public function create(): Response
    {
        $catalog = new CatalogRepository();
        return Response::html(View::render('admin/products/form', [
            'pageTitle' => 'Add laptop model',
            'model' => null,
            'brands' => $catalog->brands(),
            'categories' => $catalog->categories(),
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = $this->validateModel($request);
        } catch (\App\Exceptions\ValidationException $e) {
            return $this->formError($request, null, $e->errors);
        }

        $data['slug'] = $this->uniqueSlug('product_models', $data['slug']);
        $id = (new CatalogRepository())->createModel($data, current_user_id());

        AuditService::record('product_model.created', 'product_model', $id, [
            'name' => $data['name'],
            'published' => !empty($data['is_published']),
        ], $request);

        flash_success('Laptop model created.');
        return Response::redirect(url('/admin/products/' . $id));
    }

    public function show(string $id): Response
    {
        $catalog = new CatalogRepository();
        $model = $catalog->findModel((int) $id);
        if ($model === null) {
            throw new NotFoundException('Product model not found.');
        }

        return Response::html(View::render('admin/products/show', [
            'pageTitle' => $model['name'],
            'model' => $model,
            'units' => (new \App\Repositories\InventoryRepository())->search('', '', (int) $id, 1, 50)['rows'],
            'priceRange' => $catalog->priceRange((int) $id),
        ]));
    }

    public function edit(string $id): Response
    {
        $catalog = new CatalogRepository();
        $model = $catalog->findModel((int) $id);
        if ($model === null) {
            throw new NotFoundException('Product model not found.');
        }

        return Response::html(View::render('admin/products/form', [
            'pageTitle' => 'Edit: ' . $model['name'],
            'model' => $model,
            'brands' => $catalog->brands(),
            'categories' => $catalog->categories(),
            'errors' => [],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $catalog = new CatalogRepository();
        $model = $catalog->findModel((int) $id);
        if ($model === null) {
            throw new NotFoundException('Product model not found.');
        }

        try {
            $data = $this->validateModel($request, (int) $id);
        } catch (\App\Exceptions\ValidationException $e) {
            return $this->formError($request, $model, $e->errors);
        }

        // Keep slugs stable when unchanged; ensure uniqueness when edited.
        if ($data['slug'] !== $model['slug']) {
            $data['slug'] = $this->uniqueSlug('product_models', $data['slug'], (int) $id);
        }

        $catalog->updateModel((int) $id, $data);
        AuditService::record('product_model.updated', 'product_model', (int) $id, [
            'name' => $data['name'],
            'published' => !empty($data['is_published']),
        ], $request);

        flash_success('Laptop model updated.');
        return Response::redirect(url('/admin/products/' . $id));
    }

    public function destroy(string $id, Request $request): Response
    {
        $catalog = new CatalogRepository();
        $model = $catalog->findModel((int) $id);
        if ($model === null) {
            throw new NotFoundException('Product model not found.');
        }

        $catalog->softDeleteModel((int) $id);
        AuditService::record('product_model.deleted', 'product_model', (int) $id, ['name' => $model['name']], $request);

        flash_success('Laptop model removed (soft delete — history preserved).');
        return Response::redirect(url('/admin/products'));
    }

    private function validateModel(Request $request, ?int $ignoreId = null): array
    {
        $unique = $ignoreId
            ? 'unique:product_models,slug,' . $ignoreId
            : 'nullable';

        $data = Validator::make($request->post(), [
            'name' => 'required|string|min:2|max:190',
            'slug' => 'nullable|slug|max:220',
            'brand_id' => 'required|integer|exists:brands,id',
            'category_id' => 'required|integer|exists:categories,id',
            'processor' => 'nullable|string|max:190',
            'ram_gb' => 'nullable|integer|min:1|max:512',
            'storage_type' => 'nullable|in:HDD,SSD,HDD+SSD',
            'storage_capacity_gb' => 'nullable|integer|min:16|max:16384',
            'graphics' => 'nullable|string|max:190',
            'display_size_inches' => 'nullable|decimal:2',
            'resolution' => 'nullable|string|max:60',
            'operating_system' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:5000',
            'seo_title' => 'nullable|string|max:190',
            'seo_description' => 'nullable|string|max:300',
        ], [
            'brand_id' => 'Brand',
            'category_id' => 'Category',
            'display_size_inches' => 'Display size',
        ])->validate();

        $data['slug'] = $data['slug'] !== null && $data['slug'] !== ''
            ? $data['slug']
            : str_slug($data['name']);
        if ($unique !== 'nullable' && $this->slugTaken('product_models', $data['slug'], $ignoreId)) {
            $data['slug'] = $this->uniqueSlug('product_models', $data['slug'], $ignoreId);
        }
        $data['is_published'] = $request->input('is_published') === '1';
        $data['show_price'] = $request->input('show_price') !== '0';

        return $data;
    }

    private function slugTaken(string $table, string $slug, ?int $ignoreId): bool
    {
        $stmt = \App\Support\Database\Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE slug = ?" . ($ignoreId ? ' AND id != ?' : '')
        );
        $stmt->execute($ignoreId ? [$slug, $ignoreId] : [$slug]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function uniqueSlug(string $table, string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        $i = 1;
        while ($this->slugTaken($table, $slug, $ignoreId)) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    private function formError(Request $request, ?array $model, array $errors): Response
    {
        Session::flashInput($request->post());
        $catalog = new CatalogRepository();
        return Response::html(View::render('admin/products/form', [
            'pageTitle' => $model !== null ? 'Edit: ' . ($model['name'] ?? '') : 'Add laptop model',
            'model' => array_merge($model ?? [], $request->post()),
            'brands' => $catalog->brands(),
            'categories' => $catalog->categories(),
            'errors' => $errors,
        ]), 422);
    }
}
