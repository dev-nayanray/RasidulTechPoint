<?php

declare(strict_types=1);

/**
 * Public policy/info pages (Marketing-Website Prompt — final phase).
 *
 * Seeds the CMS `content_pages` table with the standard public pages a
 * trustworthy laptop store needs: warranty, returns, privacy, terms,
 * shipping & delivery, and the inspection process. The storefront
 * renders them at /page/{slug} (routed to PageController::cmsPage).
 *
 * IMPORTANT — draft content:
 *  The copy below is deliberately conservative: it makes only
 *  commitments the system already enforces (per-unit condition grades,
 *  warranty registrations, delivery fees from settings) and avoids
 *  invented legal guarantees, durations, or promises. The business
 *  owner should review and refine each page in
 *  Admin → Marketing & Content → Pages. Every field is editable there.
 *
 * Idempotent: INSERT IGNORE on the unique slug — existing/edited
 * pages are never overwritten. Reversible (seeding only).
 */

return [
    'up' => function (PDO $pdo): void {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO content_pages (slug, title, body, meta_title, meta_description, is_published, display_order, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?, NULL)'
        );

        $pages = [
            [
                'warranty',
                'Warranty & Service',
                <<<'HTML'
<h2>Warranty coverage</h2>
<p>Every laptop we sell is registered in our system with its own warranty record, so coverage is tied to your specific unit — not just the model. When you buy, your warranty registration is created against that exact serial number, and you can view its status any time from your account under <strong>Warranty</strong>.</p>
<p>The exact warranty duration and what it covers are confirmed at the time of purchase and recorded on your invoice. In general, coverage applies to hardware defects that arise through normal use — not to accidental damage, liquid spills, or user-modified software.</p>
<h2>What to do if something goes wrong</h2>
<ul>
<li><strong>Option 1 — your account:</strong> sign in and open a service request from the Warranty section.</li>
<li><strong>Option 2 — contact us:</strong> send the inquiry through our <a href="/contact">contact page</a> with your order reference; we route it to our service desk.</li>
</ul>
<p>Our technicians diagnose the issue and update the service job status, which you can track from your account's <strong>Service Jobs</strong> section.</p>
<h2>Battery note</h2>
<p>Used laptop batteries are consumable parts. We report each unit's measured battery health with its condition information; natural battery wear over time is not a defect, but a battery that fails within the stated coverage period is serviced under warranty.</p>
HTML,
                'Warranty & Service — Rasidul Tech Point',
                'How warranty works for Dubai-imported used laptops from Rasidul Tech Point, and how to request service.',
                10,
            ],
            [
                'returns',
                'Returns & Exchanges',
                <<<'HTML'
<h2>Our approach</h2>
<p>We want you to buy with confidence. Because every unit we sell is individually inspected and condition-graded before listing, what you see on the product page is what arrives. If something is not as described, we will make it right.</p>
<h2>Return window</h2>
<p>The standard return window for a unit that arrives not-as-described is confirmed on your invoice at purchase time. Returns are processed as a tracked return record in our system — you receive the reference when the return is accepted.</p>
<h2>How to start a return</h2>
<ul>
<li>Contact us through the <a href="/contact">contact page</a> (topic: Order support) with your order reference and a description of the issue.</li>
<li>Our team reviews the request and opens a return or exchange record if it qualifies.</li>
<li>Once the unit is received and verified, the refund or exchange is processed and you are notified.</li>
</ul>
<h2>Condition expectations</h2>
<p>Used laptops carry cosmetic signs of use — that is reflected in each unit's condition grade and exact-condition notes on the product page. Cosmetic wear consistent with the stated grade is not a defect. Functional problems are a different matter and are covered by our <a href="/page/warranty">warranty &amp; service</a> process.</p>
HTML,
                'Returns & Exchanges — Rasidul Tech Point',
                'How returns and exchanges work at Rasidul Tech Point for used laptops.',
                20,
            ],
            [
                'shipping',
                'Shipping & Delivery',
                <<<'HTML'
<h2>Delivery options</h2>
<p>When you check out, the available delivery methods and their exact fees are calculated and shown before you confirm the order — delivery charges come from our live business settings, so the price you see at review time is the price you pay.</p>
<h2>Delivery address</h2>
<p>The address you enter at checkout is stored with your order as a snapshot, so later changes to your saved profile never affect an in-flight delivery.</p>
<h2>Tracking your order</h2>
<p>Sign in to your account and open <strong>Orders</strong> to see the live status of every order — from confirmation through processing to completion. Status updates appear there as our team progresses your order.</p>
<h2>Questions</h2>
<p>If you need a delivery to a specific area or have timing requirements, mention it when ordering or reach us through the <a href="/contact">contact page</a> — we will confirm what is possible before dispatch.</p>
HTML,
                'Shipping & Delivery — Rasidul Tech Point',
                'Delivery methods, fees, and order tracking for Rasidul Tech Point orders.',
                30,
            ],
            [
                'inspection-process',
                'Laptop Inspection & Quality Check',
                <<<'HTML'
<h2>Why inspection matters</h2>
<p>Every laptop we import from Dubai goes through our internal quality-check workflow before it is listed for sale. A unit cannot reach the sales floor without passing this process — that is the core of what we do.</p>
<h2>The process</h2>
<ul>
<li><strong>1. Intake:</strong> each unit is received into inventory as a unique, individually tracked item with its own serial and SKU.</li>
<li><strong>2. QA inspection:</strong> our inspectors work through a structured checklist for the unit and record the results against that specific unit — not the model in general.</li>
<li><strong>3. Condition grading:</strong> the unit receives its condition information, which appears on its product page as exact-condition notes.</li>
<li><strong>4. Listing:</strong> only units that clear the process are published; units that fail are routed to service or written off, never sold.</li>
</ul>
<h2>What this means for you</h2>
<p>On every product page you can see the specific units available with their condition details, so you know exactly what you are buying — the same information our inspectors recorded.</p>
<p>Want to know more about a specific unit? Ask us through the <a href="/contact">contact page</a> with the product name and we will share what our inspection recorded.</p>
HTML,
                'Laptop Inspection & Quality Check — Rasidul Tech Point',
                'How Rasidul Tech Point inspects and quality-checks every Dubai-imported used laptop before sale.',
                40,
            ],
            [
                'privacy',
                'Privacy Policy',
                <<<'HTML'
<h2>Overview</h2>
<p>This policy explains what information Rasidul Tech Point collects when you use this website and how it is handled. It is written to be readable, not to hide anything.</p>
<h2>What we collect</h2>
<ul>
<li><strong>Account information:</strong> your name, email, and phone number when you register or place an order.</li>
<li><strong>Order and delivery information:</strong> the items you purchase and the delivery address you provide, stored as a snapshot with each order.</li>
<li><strong>Contact form submissions:</strong> the name, email, and message you send us through the contact page, kept so we can reply and for record-keeping.</li>
<li><strong>Technical data:</strong> when you submit the contact form, your IP address and browser type are recorded for abuse prevention and rate limiting.</li>
</ul>
<h2>How we use it</h2>
<p>To process your orders, honour warranties, provide support, and keep the service secure. We do not sell your personal information.</p>
<h2>Access and control</h2>
<p>You can view and update your profile, password, and address book from your account at any time. To request deletion of your data, contact us through the <a href="/contact">contact page</a>.</p>
<h2>Security</h2>
<p>Passwords are stored only as modern one-way hashes. Sessions are protected against fixation, and administrative access is restricted to authorised staff.</p>
HTML,
                'Privacy Policy — Rasidul Tech Point',
                'How Rasidul Tech Point collects, uses, and protects your personal information.',
                50,
            ],
            [
                'terms',
                'Terms & Conditions',
                <<<'HTML'
<h2>Using this website</h2>
<p>By browsing or placing an order with Rasidul Tech Point you agree to these terms. If you have questions about anything here, please <a href="/contact">contact us</a> before ordering.</p>
<h2>Products and pricing</h2>
<p>We sell authentic used laptops imported from Dubai. Because every unit is unique, availability is limited to the physical units in stock — a listing's availability reflects real inventory. Prices are shown in Bangladeshi Taka and are confirmed at checkout.</p>
<h2>Condition and expectations</h2>
<p>All products are pre-owned and individually condition-graded; the condition details shown on each product page are part of the sale terms. Cosmetic wear consistent with the stated grade is expected and is not grounds for a return — see our <a href="/page/returns">returns policy</a> and <a href="/page/warranty">warranty terms</a>.</p>
<h2>Orders</h2>
<p>An order is an offer to buy that we accept when confirmed. We may decline an order — for example, if a unit becomes unavailable or a payment cannot be verified — and will notify you.</p>
<h2>Accounts</h2>
<p>You are responsible for keeping your account credentials confidential and for activity under your account. We may suspend accounts used for fraud or abuse.</p>
<h2>Changes</h2>
<p>These terms may be updated; the version in force is the one published on this page at the time you place your order.</p>
HTML,
                'Terms & Conditions — Rasidul Tech Point',
                'The terms that apply when buying from Rasidul Tech Point.',
                60,
            ],
        ];

        foreach ($pages as [$slug, $title, $body, $metaTitle, $metaDescription, $order]) {
            $insert->execute([$slug, $title, $body, $metaTitle, $metaDescription, $order]);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec(
            "DELETE FROM content_pages WHERE slug IN ('warranty','returns','shipping','inspection-process','privacy','terms')"
        );
    },
];
