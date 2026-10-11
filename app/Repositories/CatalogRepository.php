<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Brands, categories and laptop models (catalog CRUD + storefront reads).
 */
final class CatalogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::pdo();
    }

    // ---- Brands ------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function brands(bool $onlyActive = false): array
    {
        return $this->pdo->query(
            'SELECT b.*, (SELECT COUNT(*) FROM product_models pm WHERE pm.brand_id = b.id AND pm.deleted_at IS NULL) AS model_count
             FROM brands b' . ($onlyActive ? ' WHERE b.is_active = 1' : '') . ' ORDER BY b.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findBrand(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM brands WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findBrandBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM brands WHERE slug = ? AND is_active = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function createBrand(string $name, string $slug, ?string $description): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO brands (name, slug, description) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $description]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateBrand(int $id, string $name, string $slug, ?string $description, bool $isActive): void
    {
        $stmt = $this->pdo->prepare('UPDATE brands SET name = ?, slug = ?, description = ?, is_active = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $description, $isActive ? 1 : 0, $id]);
    }

    // ---- Categories ----------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function categories(bool $onlyActive = false): array
    {
        return $this->pdo->query(
            'SELECT c.*, p.name AS parent_name,
                    (SELECT COUNT(*) FROM product_models pm WHERE pm.category_id = c.id AND pm.deleted_at IS NULL) AS model_count
             FROM categories c LEFT JOIN categories p ON p.id = c.parent_id'
                . ($onlyActive ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCategory(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findCategoryBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = ? AND is_active = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function createCategory(string $name, string $slug, ?int $parentId, ?string $description): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (name, slug, parent_id, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $slug, $parentId, $description]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateCategory(int $id, string $name, string $slug, ?int $parentId, ?string $description, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE categories SET name = ?, slug = ?, parent_id = ?, description = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$name, $slug, $parentId, $description, $isActive ? 1 : 0, $id]);
    }

    // ---- Product models --------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchModels(string $q = '', int $brandId = 0, int $categoryId = 0, int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE pm.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (pm.name LIKE ? OR pm.slug LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($brandId > 0) {
            $where .= ' AND pm.brand_id = ?';
            $params[] = $brandId;
        }
        if ($categoryId > 0) {
            $where .= ' AND pm.category_id = ?';
            $params[] = $categoryId;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM product_models pm{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT pm.*, b.name AS brand_name, c.name AS category_name,
                    (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_model_id = pm.id AND iu.status = 'available') AS available_units
             FROM product_models pm
             JOIN brands b ON b.id = pm.brand_id
             JOIN categories c ON c.id = pm.category_id
             {$where}
             ORDER BY pm.updated_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function findModel(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pm.*, b.name AS brand_name, c.name AS category_name, b.slug AS brand_slug
             FROM product_models pm
             JOIN brands b ON b.id = pm.brand_id
             JOIN categories c ON c.id = pm.category_id
             WHERE pm.id = ? AND pm.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findModelBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pm.*, b.name AS brand_name, c.name AS category_name, b.slug AS brand_slug
             FROM product_models pm
             JOIN brands b ON b.id = pm.brand_id
             JOIN categories c ON c.id = pm.category_id
             WHERE pm.slug = ? AND pm.deleted_at IS NULL AND pm.is_published = 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function createModel(array $d, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_models
                (brand_id, category_id, name, slug, processor, ram_gb, storage_type, storage_capacity_gb,
                 graphics, display_size_inches, resolution, operating_system, description,
                 seo_title, seo_description, is_published, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $d['brand_id'], $d['category_id'], $d['name'], $d['slug'],
            $d['processor'] ?? null, $d['ram_gb'] ?? null, $d['storage_type'] ?? null, $d['storage_capacity_gb'] ?? null,
            $d['graphics'] ?? null, $d['display_size_inches'] ?? null, $d['resolution'] ?? null,
            $d['operating_system'] ?? null, $d['description'] ?? null,
            $d['seo_title'] ?? null, $d['seo_description'] ?? null, !empty($d['is_published']) ? 1 : 0, $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateModel(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE product_models SET
                brand_id = ?, category_id = ?, name = ?, slug = ?, processor = ?, ram_gb = ?,
                storage_type = ?, storage_capacity_gb = ?, graphics = ?, display_size_inches = ?,
                resolution = ?, operating_system = ?, description = ?, seo_title = ?,
                seo_description = ?, is_published = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['brand_id'], $d['category_id'], $d['name'], $d['slug'],
            $d['processor'] ?? null, $d['ram_gb'] ?? null, $d['storage_type'] ?? null, $d['storage_capacity_gb'] ?? null,
            $d['graphics'] ?? null, $d['display_size_inches'] ?? null, $d['resolution'] ?? null,
            $d['operating_system'] ?? null, $d['description'] ?? null,
            $d['seo_title'] ?? null, $d['seo_description'] ?? null, !empty($d['is_published']) ? 1 : 0,
            $id,
        ]);
    }

    public function softDeleteModel(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE product_models SET deleted_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Storefront listing: published models with available-unit counts and
     * their lowest available selling price.
     *
     * @return list<array<string,mixed>>
     */
    public function publishedForStore(string $q = '', int $brandId = 0, int $categoryId = 0, int $limit = 24, int $offset = 0): array
    {
        $where = ' WHERE pm.is_published = 1 AND pm.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (pm.name LIKE ? OR pm.processor LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($brandId > 0) {
            $where .= ' AND pm.brand_id = ?';
            $params[] = $brandId;
        }
        if ($categoryId > 0) {
            $where .= ' AND pm.category_id = ?';
            $params[] = $categoryId;
        }

        $sql = "SELECT pm.id, pm.name, pm.slug, pm.processor, pm.ram_gb, pm.storage_type, pm.storage_capacity_gb,
                       pm.display_size_inches, pm.resolution, pm.graphics,
                       b.name AS brand_name, b.slug AS brand_slug,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_model_id = pm.id AND iu.status = 'available') AS available_units,
                       (SELECT MIN(iu.selling_price) FROM inventory_units iu WHERE iu.product_model_id = pm.id AND iu.status = 'available') AS from_price
                FROM product_models pm
                JOIN brands b ON b.id = pm.brand_id
                {$where}
                HAVING available_units > 0
                ORDER BY pm.updated_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countPublishedForStore(string $q = '', int $brandId = 0, int $categoryId = 0): int
    {
        $where = ' WHERE pm.is_published = 1 AND pm.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (pm.name LIKE ? OR pm.processor LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($brandId > 0) {
            $where .= ' AND pm.brand_id = ?';
            $params[] = $brandId;
        }
        if ($categoryId > 0) {
            $where .= ' AND pm.category_id = ?';
            $params[] = $categoryId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT pm.id,
                       (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_model_id = pm.id AND iu.status = 'available') AS available_units
                FROM product_models pm {$where}
                HAVING available_units > 0
             ) x"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
