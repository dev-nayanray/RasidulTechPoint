<?php

declare(strict_types=1);

/**
 * Hero Slider v2 — premium redesign matching the reference (hero-slider.html).
 *
 * Non-destructive ALTER on the existing hero_slides table (migration 035).
 * Adds: nav_label, badge_text, category_label, specs (JSON), chips (JSON),
 * pricing, installment, ghost_word, product_id, theme_preset + custom theme
 * colors, visual_type, laptop image fields, status (draft/published).
 *
 * Also creates a single-row hero_settings table (autoplay, announcement,
 * max_slides) and seeds the 4 reference slides (Aero 14, Forge 16, Studio 16,
 * Pro 15) on first run.
 *
 * Reversible: the 'down' callback drops only the v2 columns + the new table,
 * leaving the original v1 columns intact.
 */

return [
    'up' => function (PDO $pdo): void {
        // Static reference data + helpers, kept as local closures to avoid
        // any global redeclaration risk on migration re-runs.
        $referenceSlides = function (): array {
            return [
                // 1. Aero 14 — mist teal
                [
                    'nav_label' => 'Aero 14', 'badge_text' => 'New arrival', 'category_label' => 'Ultrabook / 14 inch',
                    'headline' => '1.12 kg of all-day power.', 'description' => 'The Aero 14 pairs a 2.8K OLED panel with 18 hours of battery, in a magnesium body that disappears into your bag.',
                    'specs' => ['16 GB LPDDR5X', '1 TB NVMe SSD', 'Wi-Fi 7'],
                    'chips' => [['value' => '2.8K OLED', 'label' => '120 Hz panel'], ['value' => '18 h', 'label' => 'battery life'], ['value' => '1.12 kg', 'label' => 'magnesium body']],
                    'currency_symbol' => '$', 'price' => 1299.00, 'old_price' => 1499.00,
                    'installment_months' => 12, 'monthly_price' => 108.00, 'installment_note' => '0% interest, free setup',
                    'ghost_word' => 'Aero', 'product_id' => null,
                    'theme_preset' => 'mist_teal', 'visual_type' => 'screen_dunes',
                    'primary_cta_label' => 'Shop Aero 14', 'primary_cta_url' => '/shop', 'secondary_cta_label' => 'Compare specs', 'secondary_cta_url' => '/shop?compare=1',
                    'status' => 'published', 'is_active' => 1, 'sort_order' => 0,
                ],
                // 2. Forge 16 — graphite coral
                [
                    'nav_label' => 'Forge 16', 'badge_text' => 'Best seller', 'category_label' => 'Gaming / 16 inch',
                    'headline' => 'Frames first. Heat never.', 'description' => 'A vapor chamber keeps the Forge 16 at full clock through long sessions, on a 240 Hz QHD+ display built for fast shooters.',
                    'specs' => ['32 GB DDR5', '2 TB NVMe SSD', 'Per-key RGB'],
                    'chips' => [['value' => '240 Hz', 'label' => 'QHD+ display'], ['value' => '8 GB', 'label' => 'GDDR6 graphics'], ['value' => 'Vapor chamber', 'label' => 'sustained clocks']],
                    'currency_symbol' => '$', 'price' => 1599.00, 'old_price' => 1799.00,
                    'installment_months' => 12, 'monthly_price' => 133.00, 'installment_note' => '0% interest, free setup',
                    'ghost_word' => 'Forge', 'product_id' => null,
                    'theme_preset' => 'graphite_coral', 'visual_type' => 'screen_gaming',
                    'primary_cta_label' => 'Shop Forge 16', 'primary_cta_url' => '/shop', 'secondary_cta_label' => 'Compare specs', 'secondary_cta_url' => '/shop?compare=1',
                    'status' => 'published', 'is_active' => 1, 'sort_order' => 1,
                ],
                // 3. Studio 16 — navy amber
                [
                    'nav_label' => 'Studio 16', 'badge_text' => 'Creator pick', 'category_label' => 'Creator / 16 inch',
                    'headline' => 'Color you can sign off on.', 'description' => 'A 16 inch 4K mini-LED panel covers 100% of DCI-P3 and ships factory calibrated, with 64 GB of memory for heavy timelines.',
                    'specs' => ['64 GB DDR5', '2 TB NVMe SSD', 'SD card reader'],
                    'chips' => [['value' => '100% DCI-P3', 'label' => '4K mini-LED'], ['value' => '64 GB', 'label' => 'DDR5 memory'], ['value' => 'dE < 2', 'label' => 'factory calibrated']],
                    'currency_symbol' => '$', 'price' => 2199.00, 'old_price' => 2449.00,
                    'installment_months' => 12, 'monthly_price' => 183.00, 'installment_note' => '0% interest, free setup',
                    'ghost_word' => 'Studio', 'product_id' => null,
                    'theme_preset' => 'navy_amber', 'visual_type' => 'screen_creator',
                    'primary_cta_label' => 'Shop Studio 16', 'primary_cta_url' => '/shop', 'secondary_cta_label' => 'Compare specs', 'secondary_cta_url' => '/shop?compare=1',
                    'status' => 'published', 'is_active' => 1, 'sort_order' => 2,
                ],
                // 4. Pro 15 — stone cobalt
                [
                    'nav_label' => 'Pro 15', 'badge_text' => 'For teams', 'category_label' => 'Business / 15.6 inch',
                    'headline' => 'Built for a 9 to 5 that runs late.', 'description' => 'A spill-resistant keyboard, a MIL-STD-810H tested chassis, fingerprint login and 14 hours away from the charger.',
                    'specs' => ['16 GB DDR5', '512 GB NVMe SSD', 'Windows 11 Pro'],
                    'chips' => [['value' => '14 h', 'label' => 'battery life'], ['value' => 'MIL-STD-810H', 'label' => 'drop tested'], ['value' => 'Fingerprint', 'label' => 'one-touch login']],
                    'currency_symbol' => '$', 'price' => 899.00, 'old_price' => 1049.00,
                    'installment_months' => 12, 'monthly_price' => 75.00, 'installment_note' => 'Volume pricing from 5 units',
                    'ghost_word' => 'Pro', 'product_id' => null,
                    'theme_preset' => 'stone_cobalt', 'visual_type' => 'screen_business',
                    'primary_cta_label' => 'Shop Pro 15', 'primary_cta_url' => '/shop', 'secondary_cta_label' => 'Compare specs', 'secondary_cta_url' => '/shop?compare=1',
                    'status' => 'published', 'is_active' => 1, 'sort_order' => 3,
                ],
            ];
        };

        $insertRef = function (PDO $pdo, array $ref): void {
            $stmt = $pdo->prepare(
                'INSERT INTO hero_slides
                    (internal_name, nav_label, badge_text, category_label, headline, description, specs, chips,
                     currency_symbol, price, old_price, installment_months, monthly_price, installment_note,
                     ghost_word, product_id, theme_preset, visual_type, cta1_label, cta1_url, cta2_label, cta2_url,
                     status, is_active, sort_order, accessibility_desc)
                 VALUES
                    (:internal_name, :nav_label, :badge_text, :category_label, :headline, :description,
                     :specs, :chips, :currency_symbol, :price, :old_price, :installment_months, :monthly_price,
                     :installment_note, :ghost_word, :product_id, :theme_preset, :visual_type, :primary_cta_label,
                     :primary_cta_url, :secondary_cta_label, :secondary_cta_url, :status, :is_active, :sort_order,
                     :accessibility_desc)'
            );
            $stmt->execute([
                ':internal_name' => $ref['nav_label'],
                ':nav_label' => $ref['nav_label'],
                ':badge_text' => $ref['badge_text'],
                ':category_label' => $ref['category_label'],
                ':headline' => $ref['headline'],
                ':description' => $ref['description'],
                ':specs' => json_encode($ref['specs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':chips' => json_encode($ref['chips'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':currency_symbol' => $ref['currency_symbol'],
                ':price' => $ref['price'],
                ':old_price' => $ref['old_price'],
                ':installment_months' => $ref['installment_months'],
                ':monthly_price' => $ref['monthly_price'],
                ':installment_note' => $ref['installment_note'],
                ':ghost_word' => $ref['ghost_word'],
                ':product_id' => $ref['product_id'],
                ':theme_preset' => $ref['theme_preset'],
                ':visual_type' => $ref['visual_type'],
                ':primary_cta_label' => $ref['primary_cta_label'],
                ':primary_cta_url' => $ref['primary_cta_url'],
                ':secondary_cta_label' => $ref['secondary_cta_label'],
                ':secondary_cta_url' => $ref['secondary_cta_url'],
                ':status' => $ref['status'],
                ':is_active' => $ref['is_active'],
                ':sort_order' => $ref['sort_order'],
                ':accessibility_desc' => $ref['description'],
            ]);
        };

        $applyRef = function (PDO $pdo, int $id, array $ref): void {
            $stmt = $pdo->prepare(
                'UPDATE hero_slides SET
                    internal_name = :internal_name, nav_label = :nav_label, badge_text = :badge_text,
                    category_label = :category_label, headline = :headline, description = :description,
                    specs = :specs, chips = :chips,
                    currency_symbol = :currency_symbol, price = :price, old_price = :old_price,
                    installment_months = :installment_months, monthly_price = :monthly_price,
                    installment_note = :installment_note, ghost_word = :ghost_word,
                    product_id = :product_id, theme_preset = :theme_preset, visual_type = :visual_type,
                    cta1_label = :primary_cta_label, cta1_url = :primary_cta_url,
                    cta2_label = :secondary_cta_label, cta2_url = :secondary_cta_url,
                    status = :status, is_active = :is_active, sort_order = :sort_order,
                    accessibility_desc = :accessibility_desc
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':internal_name' => $ref['nav_label'],
                ':nav_label' => $ref['nav_label'],
                ':badge_text' => $ref['badge_text'],
                ':category_label' => $ref['category_label'],
                ':headline' => $ref['headline'],
                ':description' => $ref['description'],
                ':specs' => json_encode($ref['specs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':chips' => json_encode($ref['chips'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':currency_symbol' => $ref['currency_symbol'],
                ':price' => $ref['price'],
                ':old_price' => $ref['old_price'],
                ':installment_months' => $ref['installment_months'],
                ':monthly_price' => $ref['monthly_price'],
                ':installment_note' => $ref['installment_note'],
                ':ghost_word' => $ref['ghost_word'],
                ':product_id' => $ref['product_id'],
                ':theme_preset' => $ref['theme_preset'],
                ':visual_type' => $ref['visual_type'],
                ':primary_cta_label' => $ref['primary_cta_label'],
                ':primary_cta_url' => $ref['primary_cta_url'],
                ':secondary_cta_label' => $ref['secondary_cta_label'],
                ':secondary_cta_url' => $ref['secondary_cta_url'],
                ':status' => $ref['status'],
                ':is_active' => $ref['is_active'],
                ':sort_order' => $ref['sort_order'],
                ':accessibility_desc' => $ref['description'],
            ]);
        };

        // ------------------------------------------------------------------
        // 1) ALTER hero_slides — add v2 columns idempotently.
        // ------------------------------------------------------------------
        $existing = $pdo->query('SHOW COLUMNS FROM hero_slides')->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_map('strtolower', $existing);

        $add = [
            // Identity / nav
            'nav_label'      => "VARCHAR(20) NULL AFTER internal_name",
            'badge_text'     => "VARCHAR(24) NULL AFTER nav_label",
            'category_label' => "VARCHAR(40) NULL AFTER badge_text",
            // Specs and chips
            'specs'  => "JSON NULL AFTER description",
            'chips'  => "JSON NULL AFTER specs",
            // Pricing
            'currency_symbol'    => "VARCHAR(8) NOT NULL DEFAULT '\u{09F3}' AFTER chips",
            'price'              => "DECIMAL(12,2) NULL AFTER currency_symbol",
            'old_price'          => "DECIMAL(12,2) NULL AFTER price",
            'installment_months' => "SMALLINT UNSIGNED NULL AFTER old_price",
            'monthly_price'      => "DECIMAL(12,2) NULL AFTER installment_months",
            'installment_note'   => "VARCHAR(120) NULL AFTER monthly_price",
            // Ghost word
            'ghost_word'  => "VARCHAR(40) NULL AFTER installment_note",
            // Product link
            'product_id'  => "BIGINT UNSIGNED NULL AFTER ghost_word",
            // Theme
            'theme_preset'     => "ENUM('mist_teal','graphite_coral','navy_amber','stone_cobalt','custom') NOT NULL DEFAULT 'mist_teal' AFTER product_id",
            'custom_bg'        => "CHAR(9) NULL AFTER theme_preset",
            'custom_bg2'       => "CHAR(9) NULL AFTER custom_bg",
            'custom_ink'       => "CHAR(9) NULL AFTER custom_bg2",
            'custom_muted'     => "CHAR(9) NULL AFTER custom_ink",
            'custom_accent'    => "CHAR(9) NULL AFTER custom_muted",
            'custom_accent_ink' => "CHAR(9) NULL AFTER custom_accent",
            // Visual
            'visual_type'      => "ENUM('screen_dunes','screen_gaming','screen_creator','screen_business','image') NOT NULL DEFAULT 'screen_dunes' AFTER custom_accent_ink",
            'laptop_image_url' => "VARCHAR(500) NULL AFTER visual_type",
            'laptop_image_alt' => "VARCHAR(190) NULL AFTER laptop_image_url",
            // Status
            'status'   => "ENUM('draft','published') NOT NULL DEFAULT 'draft' AFTER laptop_image_alt",
        ];
        foreach ($add as $col => $def) {
            if (!in_array(strtolower($col), $existing, true)) {
                $pdo->exec("ALTER TABLE hero_slides ADD COLUMN {$col} {$def}");
            }
        }
        // Index product_id if not present
        $idx = $pdo->query("SHOW INDEX FROM hero_slides WHERE Key_name = 'idx_hs_product'")->fetch();
        if (!$idx) {
            $pdo->exec('ALTER TABLE hero_slides ADD INDEX idx_hs_product (product_id)');
        }

        // ------------------------------------------------------------------
        // 2) hero_settings (single-row table, key=id=1)
        // ------------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hero_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                autoplay_enabled TINYINT(1) NOT NULL DEFAULT 1,
                autoplay_interval_ms INT UNSIGNED NOT NULL DEFAULT 7000,
                pause_on_hover TINYINT(1) NOT NULL DEFAULT 1,
                announcement_enabled TINYINT(1) NOT NULL DEFAULT 1,
                announcement_items JSON NULL,
                max_slides TINYINT UNSIGNED NOT NULL DEFAULT 8,
                updated_by BIGINT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_hero_singleton CHECK (id = 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            INSERT INTO hero_settings (id, announcement_items)
            VALUES (1, JSON_ARRAY('Dubai Imported | 100% Authentic Used Laptops', 'Tested & Quality Checked', 'Warranty with every laptop'))
            ON DUPLICATE KEY UPDATE id = id
        ");

        // ------------------------------------------------------------------
        // 3) Seed the 4 reference slides — only when table is empty OR when
        //    only the 3 v1-default demo rows are present (matched by headline).
        //    User-customized slides are NEVER overwritten.
        // ------------------------------------------------------------------
        $count = (int) $pdo->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
        $v1DefaultHeadlines = [
            'Find Your Next Laptop.',
            'Work Smarter. Choose Better.',
            'The Right Laptop for Your Budget.',
        ];

        if ($count === 0) {
            foreach ($referenceSlides() as $ref) {
                $insertRef($pdo, $ref);
            }
            return;
        }

        $rows = $pdo->query("SELECT id, headline, nav_label FROM hero_slides ORDER BY sort_order, id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $headlines = array_map(fn($r) => $r['headline'] ?? '', $rows);
        $isAllV1Defaults = count($rows) === 3
            && empty(array_diff($headlines, $v1DefaultHeadlines));

        if ($isAllV1Defaults) {
            $refs = $referenceSlides();
            for ($i = 0; $i < 3; $i++) {
                $applyRef($pdo, (int) $rows[$i]['id'], $refs[$i]);
            }
            // Insert 4th if not present.
            $check = $pdo->prepare('SELECT 1 FROM hero_slides WHERE nav_label = ? LIMIT 1');
            $check->execute([$refs[3]['nav_label']]);
            if (!$check->fetch()) {
                $insertRef($pdo, $refs[3]);
            }
        } else {
            // Insert any missing reference slide by nav_label (additive only).
            foreach ($referenceSlides() as $ref) {
                $check = $pdo->prepare('SELECT 1 FROM hero_slides WHERE nav_label = ? LIMIT 1');
                $check->execute([$ref['nav_label']]);
                if (!$check->fetch()) {
                    $insertRef($pdo, $ref);
                }
            }
        }

        // ------------------------------------------------------------------
        // 4) Safety net — guarantee at least 4 ACTIVE + PUBLISHED slides so
        //    the homepage slider always shows 4 demo slides minimum. Only
        //    activates existing reference slides (never user-created custom
        //    slides) and only when fewer than 4 active+published rows exist.
        //    Never deactivates anything.
        // ------------------------------------------------------------------
        $activePublished = (int) $pdo->query(
            "SELECT COUNT(*) FROM hero_slides WHERE is_active = 1 AND status = 'published'"
        )->fetchColumn();
        if ($activePublished < 4) {
            $refNavs = array_map(fn($r) => $r['nav_label'], $referenceSlides());
            // Activate inactive reference slides first.
            foreach ($refNavs as $nav) {
                if ($activePublished >= 4) break;
                $stmt = $pdo->prepare(
                    "UPDATE hero_slides SET is_active = 1, status = 'published' WHERE nav_label = ? AND (is_active = 0 OR status <> 'published')"
                );
                $stmt->execute([$nav]);
                $activePublished += $stmt->rowCount();
            }
            // Still short? Insert any missing reference slides (additive) until
            // we hit the 4 active+published floor.
            foreach ($referenceSlides() as $ref) {
                if ($activePublished >= 4) break;
                $check = $pdo->prepare('SELECT 1 FROM hero_slides WHERE nav_label = ? LIMIT 1');
                $check->execute([$ref['nav_label']]);
                if (!$check->fetch()) {
                    $insertRef($pdo, $ref);
                    $activePublished++;
                }
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $existing = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM hero_slides')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $drop = ['nav_label','badge_text','category_label','specs','chips','currency_symbol','price','old_price','installment_months','monthly_price','installment_note','ghost_word','product_id','theme_preset','custom_bg','custom_bg2','custom_ink','custom_muted','custom_accent','custom_accent_ink','visual_type','laptop_image_url','laptop_image_alt','status'];
        foreach ($drop as $col) {
            if (in_array($col, $existing, true)) {
                $pdo->exec("ALTER TABLE hero_slides DROP COLUMN {$col}");
            }
        }
        $pdo->exec('DROP TABLE IF EXISTS hero_settings');
    },
];
