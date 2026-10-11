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

final class CategoryController
{
    public function index(): Response
    {
        return Response::html(View::render('admin/categories/index', [
            'pageTitle' => 'Categories',
            'categories' => (new CatalogRepository())->categories(),
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:100',
                'slug' => 'required|slug|max:120|unique:categories,slug',
                'parent_id' => 'nullable|integer|exists:categories,id',
                'description' => 'nullable|string|max:500',
            ])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            Session::flashInput($request->post());
            return Response::redirect(url('/admin/categories'));
        }

        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        $id = (new CatalogRepository())->createCategory($data['name'], $data['slug'], $parentId, $data['description'] ?? null);
        AuditService::record('category.created', 'category', $id, ['name' => $data['name']], $request);

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

        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:100',
                'slug' => 'required|slug|max:120|unique:categories,slug,' . (int) $id,
                'parent_id' => 'nullable|integer|exists:categories,id',
                'description' => 'nullable|string|max:500',
                'is_active' => 'boolean',
            ])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/categories'));
        }

        // A category cannot be its own parent.
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId === (int) $id) {
            flash_error('A category cannot be its own parent.');
            return Response::redirect(url('/admin/categories'));
        }

        $repo->updateCategory((int) $id, $data['name'], $data['slug'], $parentId, $data['description'] ?? null,
            $request->input('is_active') === '1');
        AuditService::record('category.updated', 'category', (int) $id, ['name' => $data['name']], $request);

        flash_success('Category updated.');
        return Response::redirect(url('/admin/categories'));
    }
}
