<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Repositories\CatalogRepository;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class HomeController
{
    public function index(): Response
    {
        $catalog = new CatalogRepository();

        return Response::html(View::render('storefront/home', [
            'pageTitle' => 'Home',
            'featured' => $catalog->publishedForStore(limit: 8),
            'brands' => $catalog->brands(onlyActive: true),
            'metaTitle' => 'Rasidul Tech Point — Dubai Imported Authentic Used Laptops',
            'metaDescription' => 'Authentic used laptops imported from Dubai. Tested and quality checked with warranty. Buy online or visit our shop.',
        ]));
    }
}
