<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database\Connection;
use PDO;

/**
 * Storefront customer reviews.
 *
 * Publication rules:
 *  - Only is_published = 1 rows are ever returned.
 *  - Rows flagged is_demo = 1 are LOCAL PREVIEW content. They must always be
 *    labelled as demo in the storefront — never presented as verified buyers.
 *  - Real reviews are expected to be created from confirmed orders only
 *    (admin moderation); that writer does not exist yet.
 */
final class ReviewService
{
    /** Published reviews for storefront display (demo rows last). */
    public function published(int $limit = 6): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT cr.*, pm.name AS product_name, pm.slug AS product_slug
             FROM customer_reviews cr
             LEFT JOIN product_models pm ON pm.id = cr.product_model_id
             WHERE cr.is_published = 1
             ORDER BY cr.is_demo ASC, cr.display_order ASC, cr.id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** True when any published row exists (drives the homepage section state). */
    public function hasPublished(): bool
    {
        return (int) Connection::pdo()->query(
            'SELECT COUNT(*) FROM customer_reviews WHERE is_published = 1'
        )->fetchColumn() > 0;
    }
}
