<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use PDO;

/**
 * DEVELOPMENT content seed: professional storefront FAQs (plain business
 * content, admin-editable via Content → FAQs) and DEMO customer reviews
 * (is_demo = 1 — always labelled "Demo review" in the storefront, never
 * presented as verified customers).
 *
 * Guarded to APP_ENV=local. Idempotent: FAQs matched by question text,
 * demo reviews matched by (reviewer_name, title).
 */
final class DemoContentSeeder
{
    /** @param callable(string): void|null $out @return array<string,int> */
    public function run(PDO $pdo, ?callable $out = null): array
    {
        if ((string) \App\Support\Config::get('app.env') !== 'local') {
            throw new \RuntimeException('DemoContentSeeder may only run in the local environment.');
        }
        $stats = ['faqs_inserted' => 0, 'faqs_skipped' => 0, 'reviews_inserted' => 0, 'reviews_skipped' => 0];

        // ---- FAQs (business content, published) --------------------------------
        $faqs = [
            ['Are your laptops really imported from Dubai?', 'Yes. Our stock is sourced directly from Dubai. Every import lot is documented, and each laptop is checked against that manifest before it is listed.', 'Buying', 10],
            ['What does "Tested & Quality Checked" actually cover?', 'Every unit goes through our 4-step inspection: we verify the model and serial, test the hardware (keyboard, trackpad, screen, ports, battery, Wi-Fi, storage health), photograph and record any cosmetic marks, and only then approve it for sale.', 'Buying', 20],
            ['What do the condition grades A, B and C mean?', 'Grade A means like new, B means light wear, and C means visible marks. The exact condition of each unit — including any known defects — is written on its product page before you buy.', 'Buying', 30],
            ['Is a warranty included?', 'Where warranty applies it is shown on the product page for that laptop. Warranty and service options are also explained on our Warranty & Support page.', 'Warranty', 40],
            ['Can I inspect a laptop in person before buying?', 'Yes. Visit our store at Shop 110, Ground Floor, Jalil Tower, Khulna. If you cannot visit, each product page lists the exact specs and documented condition of the unit you would receive.', 'Buying', 50],
            ['How does delivery work?', 'We deliver across Bangladesh. The delivery fee depends on your location and is calculated at checkout before you pay.', 'Delivery', 60],
            ['Can I return a laptop if I am not satisfied?', 'Yes — our return policy is published on the Returns page. Because these are used units, we document condition upfront so you know exactly what to expect.', 'Returns', 70],
            ['Do you sell new laptops as well?', 'We specialise in authentic used laptops imported from Dubai. That focus is what lets us offer tested, original laptops at better prices than new equivalents.', 'Buying', 80],
            ['How do I know the battery is healthy?', 'Battery health is tested during inspection. Any noticeable battery wear is noted on the product page together with the rest of the condition report.', 'Buying', 90],
            ['I am a student on a tight budget. What are my options?', 'We keep a dedicated budget range with laptops under ৳15,000 — tested, study-ready machines. Open the Budget Deals section from the menu to see current stock.', 'Buying', 100],
            ['Do you provide servicing after the sale?', 'Yes, we run a service centre alongside the store. You can bring a laptop in for diagnosis and repair, or contact us to arrange service in our coverage areas.', 'Warranty', 110],
            ['Can businesses buy in bulk?', 'Yes. Contact us with your requirements — we regularly supply offices with multiple units and can check incoming shipments against your needed specifications.', 'Buying', 120],
        ];
        $findFaq = $pdo->prepare('SELECT id FROM faqs WHERE question = ?');
        $insFaq = $pdo->prepare('INSERT INTO faqs (question, answer, category, is_published, display_order) VALUES (?, ?, ?, 1, ?)');
        foreach ($faqs as [$q, $a, $cat, $order]) {
            $findFaq->execute([$q]);
            if ($findFaq->fetchColumn() !== false) {
                $stats['faqs_skipped']++;
                continue;
            }
            $insFaq->execute([$q, $a, $cat, $order]);
            $stats['faqs_inserted']++;
        }

        // ---- Demo reviews (is_demo = 1, clearly labelled in the storefront) ---
        $modelId = static function (PDO $pdo, string $slug): ?int {
            $stmt = $pdo->prepare('SELECT id FROM product_models WHERE slug = ?');
            $stmt->execute([$slug]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        };
        $reviews = [
            ['Tanvir Ahmed', 'Khulna', 5, 'Exactly as described', 'The condition notes on the product page matched the laptop perfectly — down to the small mark near the trackpad. Nothing was hidden, and it feels far newer than the price suggests.', 'lenovo-thinkpad-t490'],
            ['Nusrat Jahan', 'Jessore', 5, 'Perfect for my classes', 'Bought a budget laptop for university work. It runs my classes, browsing and assignments without any trouble, and the battery easily lasts a full day of lectures.', 'lenovo-ideapad-slim-3-15abr05'],
            ['Mahmudul Hasan', 'Dhaka', 4, 'The inspection report gave me confidence', 'I was nervous about buying a used laptop online. Seeing the documented test results and condition for the exact unit made the decision easy. Delivery to Dhaka took a couple of days.', 'hp-elitebook-840-g7'],
            ['Rifat Chowdhury', 'Khulna', 5, 'Bought two for our office', 'We needed dependable machines for document work and accounts. Both units arrived tested and clean, and the team answered every question before we ordered. Straightforward experience.', 'dell-latitude-5410'],
            ['Sharmin Akter', 'Satkhira', 4, 'Honest about the details', 'They clearly mentioned the scratches on the lid before I paid, and the price reflected it. That honesty is why I would buy here again.', 'dell-latitude-7280'],
            ['Arif Hossain', 'Bagerhat', 5, 'Great value MacBook', 'Found a MacBook here for much less than the usual market price. It is original, tested, and working flawlessly three months in. The team clearly knows their stock.', 'apple-macbook-air-m1-2020-a2337'],
        ];
        $findRev = $pdo->prepare('SELECT id FROM customer_reviews WHERE reviewer_name = ? AND title = ?');
        $insRev = $pdo->prepare(
            'INSERT INTO customer_reviews (reviewer_name, city, rating, title, body, product_model_id, is_demo, is_published, display_order)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)'
        );
        $order = 10;
        foreach ($reviews as [$name, $city, $rating, $title, $body, $slug]) {
            $findRev->execute([$name, $title]);
            if ($findRev->fetchColumn() !== false) {
                $stats['reviews_skipped']++;
                continue;
            }
            $insRev->execute([$name, $city, $rating, $title, $body, $modelId($pdo, $slug), $order]);
            $stats['reviews_inserted']++;
            $order += 10;
        }

        return $stats;
    }
}
