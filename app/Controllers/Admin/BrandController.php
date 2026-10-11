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

final class BrandController
{
    public function index(): Response
    {
        return Response::html(View::render('admin/brands/index', [
            'pageTitle' => 'Brands',
            'brands' => (new CatalogRepository())->brands(),
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:100',
                'slug' => 'required|slug|max:120|unique:brands,slug',
                'description' => 'nullable|string|max:500',
            ])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            Session::flashInput($request->post());
            return Response::redirect(url('/admin/brands'));
        }

        $id = (new CatalogRepository())->createBrand($data['name'], $data['slug'], $data['description'] ?? null);
        AuditService::record('brand.created', 'brand', $id, ['name' => $data['name']], $request);

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

        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:100',
                'slug' => 'required|slug|max:120|unique:brands,slug,' . (int) $id,
                'description' => 'nullable|string|max:500',
                'is_active' => 'boolean',
            ])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/brands'));
        }

        $repo->updateBrand((int) $id, $data['name'], $data['slug'], $data['description'] ?? null,
            $request->input('is_active') === '1');
        AuditService::record('brand.updated', 'brand', (int) $id, ['name' => $data['name']], $request);

        flash_success('Brand updated.');
        return Response::redirect(url('/admin/brands'));
    }
}
