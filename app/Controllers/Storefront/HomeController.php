<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Repositories\CatalogRepository;
use App\Services\ContentService;
use App\Services\SettingService;
use App\Support\Response;
use App\Support\View;

/**
 * Homepage controller — premium marketing storefront.
 *
 * Renders the PHP/Tailwind view at resources/views/storefront/home.php.
 * All sections (announcement bar, header, hero, categories, budget,
 * featured laptops, why-choose, how-we-check, brands, special deals,
 * customer reviews, FAQ, final CTA, footer) live in the view + the
 * shared public.php layout. Data is fetched from the catalog (real
 * inventory) and the content service (CMS-managed blocks, FAQs,
 * banners). Marketing copy that is not yet backed by operational
 * services renders in an explicit empty state — we never fabricate
 * inventory, reviews, ratings, or warranty durations.
 */
final class HomeController
{
    public function index(): Response
    {
        $catalog = new CatalogRepository();
        $content = new ContentService();

        // Real published product models with at least one available unit —
        // one EXACT unit per model so each card shows real grade + price.
        $featured = $catalog->featuredUnits(limit: 8);

        // Categories that actually carry available stock.
        $categories = $catalog->storeCategories();

        // Active brands — only those the store actually carries stock for.
        $brands = array_filter(
            $catalog->brands(onlyActive: true),
            fn ($b) => $this->brandHasAvailableUnits((int) $b['id']),
        );

        // Configurable budget ranges from the settings table. Falls back
        // to a sensible BDT default when the admin hasn't configured any.
        $budgetRanges = $this->budgetRanges();

        // Live price bounds for the budget range bars (honest scale).
        $priceBounds = $catalog->priceBounds();

        // CMS-managed FAQ entries (Prompt 36). Empty list is fine — the
        // view renders a tasteful "FAQ coming soon" placeholder.
        $faqs = $content->publishedFaqs();

        return Response::html(View::render('storefront/home', [
            'pageTitle' => 'Home',
            'featured' => $featured,
            'categories' => $categories,
            'brands' => $brands,
            'budgetRanges' => $budgetRanges,
            'priceBounds' => $priceBounds,
            'faqs' => $faqs,
            // Trust / marketing blocks pulled from the CMS so the admin
            // can edit copy without touching code. Falls back to inline
            // copy when the block is not yet configured.
            'heroBlock' => $content->publishedBlock('home_hero') ?? '',
            'whyChooseBlock' => $content->publishedBlock('home_why_choose') ?? '',
            'howWeCheckBlock' => $content->publishedBlock('home_how_we_check') ?? '',
            'finalCtaBlock' => $content->publishedBlock('home_final_cta') ?? '',
            'metaTitle' => 'Rasidul Tech Point — Dubai Imported Authentic Used Laptops',
            'metaDescription' => 'Authentic used laptops imported from Dubai. Tested and quality checked with warranty options. Shop online or visit our store.',
        ]));
    }

    /**
     * Return the configured budget ranges. Each range is [label, min, max]
     * where max = null means "and above". The ranges are editable via
     * the system settings (currency category) — `home_budget_ranges`
     * setting holds a JSON-encoded list.
     *
     * @return list<array{label:string,min:int,max:?int}>
     */
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
        // Sensible BDT defaults — admin can override via system settings.
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
