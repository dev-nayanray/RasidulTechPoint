<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Exceptions\NotFoundException;
use App\Repositories\CatalogRepository;
use App\Repositories\InventoryRepository;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class ShopController
{
    /**
     * AJAX search suggestions for the header search bar.
     * Returns JSON: {results: [{name, brand, slug, price, units}]}
     * Used by the live search dropdown on every page.
     */
    public function searchSuggest(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return Response::json(['results' => [], 'more' => false]);
        }
        $catalog = new CatalogRepository();
        $products = $catalog->publishedForStore($q, 0, 0, 8, 0);
        $results = [];
        foreach ($products as $p) {
            $img = trim((string) ($p['image_path'] ?? ''));
            $results[] = [
                'name' => $p['name'],
                'brand' => $p['brand_name'] ?? '',
                'slug' => $p['slug'],
                'price' => $p['from_price'] !== null ? '৳' . number_format((float) $p['from_price']) : '—',
                'units' => (int) ($p['available_units'] ?? 0),
                'url' => url('/shop/' . $p['slug']),
                'processor' => $p['processor'] ?? '',
                'ram' => isset($p['ram_gb']) ? $p['ram_gb'] . ' GB' : '',
                'image' => $img !== '' && is_file(BASE_PATH . '/public/' . $img) ? url($img) : null,
            ];
        }
        return Response::json([
            'results' => $results,
            'more' => count($products) >= 8,
            'search_url' => url('/shop?q=' . urlencode($q)),
        ]);
    }

    public function index(Request $request): Response
    {
        [$page] = paginate($request->query('page', 1), 24, 24, 24);
        $catalog = new CatalogRepository();

        $q = trim((string) $request->query('q', ''));
        $brandId = (int) $request->query('brand', 0);
        $categoryId = (int) $request->query('category', 0);

        // Build filters array from query params (budget, grade, in_stock, sort).
        $filters = [
            'budget' => (int) $request->query('budget', 0),
            'grade' => (array) $request->query('grade', []),
            'sort' => (string) $request->query('sort', ''),
        ];

        $perPage = 24;
        $products = $catalog->publishedForStore($q, $brandId, $categoryId, $perPage, ($page - 1) * $perPage, $filters);
        $total = $catalog->countPublishedForStore($q, $brandId, $categoryId, $filters);
        $pages = max(1, (int) ceil($total / $perPage));

        return Response::html(View::render('storefront/shop', [
            'pageTitle' => 'Shop',
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'brandId' => $brandId,
            'categoryId' => $categoryId,
            'brands' => $catalog->brands(onlyActive: true),
            'categories' => $catalog->categories(onlyActive: true),
            'metaTitle' => 'Shop Used Laptops — Rasidul Tech Point',
        ]));
    }

    public function show(string $slug): Response
    {
        $catalog = new CatalogRepository();
        $model = $catalog->findModelBySlug($slug);
        if ($model === null) {
            throw new NotFoundException('Product not found.');
        }

        $units = (new InventoryRepository())->availableUnitsForModel((int) $model['id']);
        $images = $catalog->imagesForModel((int) $model['id']);

        return Response::html(View::render('storefront/product', [
            'pageTitle' => $model['name'],
            'model' => $model,
            'units' => $units,
            'images' => $images,
            'metaTitle' => $model['seo_title'] ?: $model['name'] . ' — Rasidul Tech Point',
            'metaDescription' => $model['seo_description'] ?: 'Buy authentic used ' . $model['name'] . ' imported from Dubai.',
        ]));
    }

    public function brand(string $slug, Request $request): Response
    {
        $catalog = new CatalogRepository();
        $brand = $catalog->findBrandBySlug($slug);
        if ($brand === null) {
            throw new NotFoundException('Brand not found.');
        }
        return $this->filteredListing($catalog, (int) $brand['id'], 0, 'brand', $brand['name']);
    }

    public function category(string $slug, Request $request): Response
    {
        $catalog = new CatalogRepository();
        $category = $catalog->findCategoryBySlug($slug);
        if ($category === null) {
            throw new NotFoundException('Category not found.');
        }
        return $this->filteredListing($catalog, 0, (int) $category['id'], 'category', $category['name']);
    }

    private function filteredListing(CatalogRepository $catalog, int $brandId, int $categoryId, string $filterKey, string $filterLabel): Response
    {
        $products = $catalog->publishedForStore('', $brandId, $categoryId, 24, 0);
        $total = $catalog->countPublishedForStore('', $brandId, $categoryId);

        return Response::html(View::render('storefront/shop', [
            'pageTitle' => $filterLabel,
            'products' => $products,
            'total' => $total,
            'page' => 1,
            'pages' => max(1, (int) ceil($total / 24)),
            'q' => '',
            'brandId' => $brandId,
            'categoryId' => $categoryId,
            'brands' => $catalog->brands(onlyActive: true),
            'categories' => $catalog->categories(onlyActive: true),
            'metaTitle' => $filterLabel . ' — Rasidul Tech Point',
        ]));
    }
}
