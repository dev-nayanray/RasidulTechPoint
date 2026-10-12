<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Services\ContentService;
use App\Support\Response;

/**
 * Homepage controller.
 *
 * Serves the custom uploaded homepage HTML (public/homepage.html)
 * as a pixel-perfect, responsive design. The HTML is stored as a
 * static file and served directly — it's a self-contained bundler
 * page that renders at runtime via JavaScript.
 *
 * The CMS manages dynamic content (blocks, banners, pages, FAQs,
 * SEO) for all other storefront pages. The homepage HTML itself
 * can be edited via the content_blocks admin (block_key =
 * 'homepage_html') — the admin stores the raw HTML in the
 * database and the file is served from public/.
 */
final class HomeController
{
    public function index(): Response
    {
        // Serve the custom uploaded homepage HTML directly.
        $htmlFile = BASE_PATH . '/public/homepage.html';
        if (is_file($htmlFile)) {
            $html = file_get_contents($htmlFile);
            return Response::html($html)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // Fallback to the default PHP-rendered storefront homepage.
        $catalog = new \App\Repositories\CatalogRepository();
        return Response::html(\App\Support\View::render('storefront/home', [
            'pageTitle' => 'Home',
            'featured' => $catalog->publishedForStore(limit: 8),
            'brands' => $catalog->brands(onlyActive: true),
            'metaTitle' => 'Rasidul Tech Point — Dubai Imported Authentic Used Laptops',
            'metaDescription' => 'Authentic used laptops imported from Dubai. Tested and quality checked with warranty. Buy online or visit our shop.',
        ]));
    }
}
