<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Repositories\CatalogRepository;
use App\Services\ContentService;
use App\Services\HeroSlideService;
use App\Services\SettingService;
use App\Support\Response;
use App\Support\View;

final class HomeController
{
    public function index(): Response
    {
        $catalog = new CatalogRepository();
        $content = new ContentService();
        $heroSvc = new HeroSlideService();

        $featured = $catalog->publishedForStore(limit: 8);
        $heroSlides = $heroSvc->published();
        $heroSettings = $heroSvc->settings();

        // GUARANTEED FALLBACK — if the DB has 0 published slides (migration
        // didn't seed, admin deleted them all, etc.), use the 4 reference
        // slides as in-memory defaults so the homepage slider ALWAYS renders.
        // When admin saves real slides to the DB, those take over.
        if ($heroSlides === []) {
            $heroSlides = HeroSlideService::referenceSlides();
            // Force autoplay on for the fallback (the user explicitly wants
            // autoplay + 4 slides minimum).
            $heroSettings['autoplay_enabled'] = true;
            $heroSettings['announcement_enabled'] = true;
            $heroSettings['announcement_items'] = [
                'Dubai Imported | 100% Authentic Used Laptops',
                'Tested & Quality Checked',
                'Warranty with every laptop',
            ];
        }
        $brands = array_filter(
            $catalog->brands(onlyActive: true),
            fn ($b) => $this->brandHasAvailableUnits((int) $b['id']),
        );
        $budgetRanges = $this->budgetRanges();
        $faqs = $content->publishedFaqs();

        // home.php is self-contained (has its own DOCTYPE, header, footer,
        // and inline CSS). Use View::partial() (NOT View::render) to avoid
        // layout wrapping — View::render(layout: null) STILL wraps because
        // the ??= operator treats null as "unset" and assigns the default.
        return Response::html(View::partial('storefront/home', [
            'pageTitle' => 'Home',
            'featured' => $featured,
            'heroSlides' => $heroSlides,
            'heroSettings' => $heroSettings,
            'brands' => $brands,
            'budgetRanges' => $budgetRanges,
            'faqs' => $faqs,
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
