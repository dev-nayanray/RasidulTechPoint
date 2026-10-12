<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Repositories\CatalogRepository;
use App\Services\ContentService;
use App\Services\SettingService;
use App\Support\Response;
use App\Support\View;

/**
 * Homepage controller.
 *
 * Serves the static premium homepage HTML (public/homepage.html)
 * when it exists — this is the pixel-perfect clone of the uploaded
 * design with demo data. Falls back to the PHP/Tailwind view at
 * resources/views/storefront/home.php when the static file is absent.
 */
final class HomeController
{
    public function index(): Response
    {
        // Always render the dynamic PHP view — it pulls real products
        // from the database + has the complete design CSS inline.
        $catalog = new CatalogRepository();
        $content = new ContentService();

        $featured = $catalog->publishedForStore(limit: 8);

        $brands = array_filter(
            $catalog->brands(onlyActive: true),
            fn ($b) => $this->brandHasAvailableUnits((int) $b['id']),
        );

        $budgetRanges = $this->budgetRanges();
        $faqs = $content->publishedFaqs();

        return Response::html(View::render('storefront/home', [
            'pageTitle' => 'Home',
            'featured' => $featured,
            'brands' => $brands,
            'budgetRanges' => $budgetRanges,
            'faqs' => $faqs,
            'heroBlock' => $content->publishedBlock('home_hero') ?? '',
            'whyChooseBlock' => $content->publishedBlock('home_why_choose') ?? '',
            'howWeCheckBlock' => $content->publishedBlock('home_how_we_check') ?? '',
            'finalCtaBlock' => $content->publishedBlock('home_final_cta') ?? '',
            'metaTitle' => 'Rasidul Tech Point — Dubai Imported Authentic Used Laptops',
            'metaDescription' => 'Authentic used laptops imported from Dubai. Tested and quality checked with warranty options. Shop online or visit our store.',
        ]));
    }

    private function budgetRanges(): array
    {
        $json = (string) SettingService::get('home_budget_ranges', '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_filter(array_map(
                    fn ($r) => isset($r['label'], $r['min']) ? [
                        'label' => (string) $r['label'],
                        'min' => (int) $r['min'],
                        'max' => isset($r['max']) ? (int) $r['max'] : null,
                    ] : null,
                    $decoded,
                )));
            }
        }
        return [
            ['label' => 'Under ৳15,000', 'min' => 0, 'max' => 15000],
            ['label' => '৳15,000 – ৳25,000', 'min' => 15000, 'max' => 25000],
            ['label' => '৳25,000 – ৳40,000', 'min' => 25000, 'max' => 40000],
            ['label' => '৳40,000+', 'min' => 40000, 'max' => null],
        ];
    }

    private function brandHasAvailableUnits(int $brandId): bool
    {
        $stmt = \App\Support\Database\Connection::pdo()->prepare(
            "SELECT 1 FROM inventory_units iu
             JOIN product_models pm ON pm.id = iu.product_model_id
             WHERE pm.brand_id = ? AND iu.status = 'available'
             LIMIT 1"
        );
        $stmt->execute([$brandId]);
        return $stmt->fetchColumn() !== false;
    }
}
