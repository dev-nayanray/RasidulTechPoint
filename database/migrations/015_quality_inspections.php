<?php

declare(strict_types=1);

/**
 * Quality inspection: configurable checklists, inspection records with
 * hardware test results, defect notes, photo evidence, pass/fail/hold
 * outcomes, technician assignment, and approval history.
 *
 * - inspection_checklists: configurable checklist items (name, category,
 *   description, is_active, sort_order). Admins can add/remove/reorder.
 * - inspection_records: one per unit per inspection session. Links to
 *   the unit, the technician, the outcome (pass/fail/hold), condition
 *   grade, defect notes, and stores the checklist results as JSON.
 *
 * Only an authorized approval (inventory.approve permission) makes a
 * unit sellable — the inspection record stores the outcome, but the
 * unit's status transition to 'available' requires the
 * InventoryService::transition() with 'inspection' → 'available',
 * which the controller gates behind inventory.approve.
 *
 * Reversible.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE inspection_checklists (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                category VARCHAR(60) NOT NULL DEFAULT 'general',
                description VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_checklist_active (is_active),
                KEY idx_checklist_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default checklist items.
        $defaults = [
            ['Power-on test', 'hardware', 'Unit powers on and boots to BIOS/OS'],
            ['Display test', 'hardware', 'Screen has no dead pixels, scratches, or backlight issues'],
            ['Keyboard test', 'hardware', 'All keys functional, no sticking'],
            ['Touchpad test', 'hardware', 'Pointer moves, click and tap work'],
            ['Ports test', 'hardware', 'USB, HDMI, audio, network ports functional'],
            ['Wi-Fi test', 'hardware', 'Connects to network, stable connection'],
            ['Battery test', 'hardware', 'Holds charge, reports health status'],
            ['Storage test', 'hardware', 'Disk health OK, no bad sectors (SMART)'],
            ['Camera test', 'hardware', 'Webcam functional'],
            ['Speakers test', 'hardware', 'Audio output clear, no distortion'],
            ['Chassis inspection', 'physical', 'No cracks, dents, or missing screws'],
            ['Hinges test', 'physical', 'Lid opens/closes smoothly, no looseness'],
            ['Cooling test', 'physical', 'Fans spin, no overheating under load'],
            ['OS activation', 'software', 'Operating system installed and activated'],
            ['Driver check', 'software', 'All device drivers installed'],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO inspection_checklists (name, category, description, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($defaults as $i => $item) {
            $stmt->execute([$item[0], $item[1], $item[2], $i]);
        }

        $pdo->exec("
            CREATE TABLE inspection_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inventory_unit_id BIGINT UNSIGNED NOT NULL,
                technician_id BIGINT UNSIGNED NULL,
                outcome ENUM('pass','fail','hold') NOT NULL,
                condition_grade ENUM('A','B','C','D') NULL,
                defect_notes TEXT NULL,
                checklist_results TEXT NULL,
                photo_paths TEXT NULL,
                approved_by BIGINT UNSIGNED NULL,
                approved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ir_unit (inventory_unit_id),
                KEY idx_ir_outcome (outcome),
                KEY idx_ir_technician (technician_id),
                CONSTRAINT fk_ir_unit FOREIGN KEY (inventory_unit_id)
                    REFERENCES inventory_units (id) ON DELETE CASCADE,
                CONSTRAINT fk_ir_tech FOREIGN KEY (technician_id)
                    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_ir_approver FOREIGN KEY (approved_by)
                    REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS inspection_records');
        $pdo->exec('DROP TABLE IF EXISTS inspection_checklists');
    },
];
