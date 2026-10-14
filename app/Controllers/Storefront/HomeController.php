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
        // One grouped query for per-brand available-unit counts (used both to
        // filter the list and to render real stock counts on the brand cards).
        $brandUnits = $catalog->availableUnitCountsByBrand();
        $brands = array_values(array_filter(
            $catalog->brands(onlyActive: true),
            fn (array $b): bool => ($brandUnits[(int) $b['id']] ?? 0) > 0,
        ));
        foreach ($brands as &$b) {
            $b['available_units'] = $brandUnits[(int) $b['id']] ?? 0;
        }
        unset($b);
        $budgetRanges = $this->budgetRanges();
        $faqs = $content->publishedFaqs();
        $reviews = (new \App\Services\ReviewService())->published(6);

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
            'reviews' => $reviews,
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
}
