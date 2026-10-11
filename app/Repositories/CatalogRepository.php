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
             FROM brands b' . ($onlyActive ? ' WHERE b.is_active = 1' : '') . ' ORDER BY b.sort_order ASC, b.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Paginated search by name or slug. */
    public function searchBrands(string $q, int $page, int $perPage): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (b.name LIKE ? OR b.slug LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM brands b{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT b.*, (SELECT COUNT(*) FROM product_models pm WHERE pm.brand_id = b.id AND pm.deleted_at IS NULL) AS model_count
             FROM brands b{$where}
             ORDER BY b.sort_order ASC, b.name ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
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

    /** Full create with sort_order and logo_path. */
    public function createBrandFull(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO brands (name, slug, description, logo_path, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $d['name'], $d['slug'], $d['description'] ?? null,
            $d['logo_path'] ?? null, (int) ($d['sort_order'] ?? 0),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Full update with sort_order and logo_path. */
    public function updateBrandFull(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE brands SET name = ?, slug = ?, description = ?, logo_path = ?, sort_order = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['name'], $d['slug'], $d['description'] ?? null,
            $d['logo_path'] ?? null, (int) ($d['sort_order'] ?? 0),
            !empty($d['is_active']) ? 1 : 0, $id,
        ]);
    }

    /** Backward-compat wrapper. */
    public function createBrand(string $name, string $slug, ?string $description): int
    {
        return $this->createBrandFull(['name' => $name, 'slug' => $slug, 'description' => $description]);
    }

    /** Backward-compat wrapper. */
    public function updateBrand(int $id, string $name, string $slug, ?string $description, bool $isActive): void
    {
        $this->updateBrandFull($id, [
            'name' => $name, 'slug' => $slug, 'description' => $description, 'is_active' => $isActive,
        ]);
    }

    /** Count product_models referencing this brand (non-deleted). */
    public function countModelsInBrand(int $brandId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM product_models WHERE brand_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$brandId]);
        return (int) $stmt->fetchColumn();
    }

    /** Whether a slug is already used by a different brand. */
    public function brandSlugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM brands WHERE slug = ? AND id != ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM brands WHERE slug = ?');
            $stmt->execute([$slug]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Delete a brand. If it has product_models, they must be reassigned
     * to $reassignTo first. Preserves historical references by moving
     * models to the target brand in a single transaction. If no
     * reassignment target is provided and there are models, throws.
     */
    public function deleteBrand(int $id, ?int $reassignTo = null): void
    {
        $brand = $this->findBrand($id);
        if ($brand === null) {
            throw new \App\Exceptions\NotFoundException('Brand not found.');
        }

        $modelCount = $this->countModelsInBrand($id);
        if ($modelCount > 0 && ($reassignTo === null || $reassignTo === 0)) {
            throw new \App\Exceptions\ValidationException([
                'brand' => "Cannot delete a brand with {$modelCount} product model(s). Choose a reassignment target or remove the models first.",
            ]);
        }

        if ($reassignTo !== null && $reassignTo > 0) {
            $target = $this->findBrand($reassignTo);
            if ($target === null) {
                throw new \App\Exceptions\ValidationException([
                    'reassign_to' => 'Reassignment target brand does not exist.',
                ]);
            }
            if ($reassignTo === $id) {
                throw new \App\Exceptions\ValidationException([
                    'reassign_to' => 'Cannot reassign models to the brand being deleted.',
                ]);
            }
        }

        \App\Support\Database\Connection::transaction(function (PDO $pdo) use ($id, $reassignTo) {
            if ($reassignTo !== null && $reassignTo > 0) {
                $pdo->prepare('UPDATE product_models SET brand_id = ? WHERE brand_id = ?')
                    ->execute([$reassignTo, $id]);
            }
            $pdo->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
        });
    }

    // ---- Categories ----------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function categories(bool $onlyActive = false): array
    {
        return $this->pdo->query(
            'SELECT c.*, p.name AS parent_name,
                    (SELECT COUNT(*) FROM product_models pm WHERE pm.category_id = c.id AND pm.deleted_at IS NULL) AS model_count,
                    (SELECT COUNT(*) FROM categories ch WHERE ch.parent_id = c.id) AS child_count
             FROM categories c LEFT JOIN categories p ON p.id = c.parent_id'
                . ($onlyActive ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order ASC, c.name ASC'
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

    public function createCategory(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (name, slug, parent_id, description, seo_title, seo_description, sort_order, image_path, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $d['name'], $d['slug'], $d['parent_id'] ?? null,
            $d['description'] ?? null, $d['seo_title'] ?? null, $d['seo_description'] ?? null,
            (int) ($d['sort_order'] ?? 0), $d['image_path'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateCategoryFull(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE categories SET name = ?, slug = ?, parent_id = ?, description = ?,
                seo_title = ?, seo_description = ?, sort_order = ?, image_path = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['name'], $d['slug'], $d['parent_id'] ?? null,
            $d['description'] ?? null, $d['seo_title'] ?? null, $d['seo_description'] ?? null,
            (int) ($d['sort_order'] ?? 0), $d['image_path'] ?? null,
            !empty($d['is_active']) ? 1 : 0, $id,
        ]);
    }

    /** Backward-compat wrapper for the old signature. */
    public function updateCategory(int $id, string $name, string $slug, ?int $parentId, ?string $description, bool $isActive): void
    {
        $this->updateCategoryFull($id, [
            'name' => $name, 'slug' => $slug, 'parent_id' => $parentId,
            'description' => $description, 'is_active' => $isActive,
        ]);
    }

    /** Count product_models in this category (non-deleted). */
    public function countModelsInCategory(int $categoryId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM product_models WHERE category_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$categoryId]);
        return (int) $stmt->fetchColumn();
    }

    /** Count direct child categories. */
    public function countChildren(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
        $stmt->execute([$categoryId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Check if setting parentId would create a circular reference.
     * Walks up the parent chain from parentId; if we reach $id, it's circular.
     */
    public function wouldCreateCycle(int $categoryId, ?int $parentId): bool
    {
        if ($parentId === null || $parentId === 0) {
            return false;
        }
        $visited = [$categoryId];
        $current = $parentId;
        $maxDepth = 20; // safety valve
        while ($current !== null && $current > 0 && $maxDepth-- > 0) {
            if (in_array($current, $visited, true)) {
                return true;
            }
            $visited[] = $current;
            $stmt = $this->pdo->prepare('SELECT parent_id FROM categories WHERE id = ?');
            $stmt->execute([$current]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                break;
            }
            $current = (int) $current;
        }
        return false;
    }

    /**
     * Delete a category. If it has product_models, they must be
     * reassigned to $reassignTo first. If $reassignTo is null and
     * there are models, throws. Children are reparented to the
     * deleted category's parent (or null if it was a root).
     */
    public function deleteCategory(int $id, ?int $reassignTo = null): void
    {
        $category = $this->findCategory($id);
        if ($category === null) {
            throw new \App\Exceptions\NotFoundException('Category not found.');
        }

        $modelCount = $this->countModelsInCategory($id);
        if ($modelCount > 0 && ($reassignTo === null || $reassignTo === 0)) {
            throw new \App\Exceptions\ValidationException([
                'category' => "Cannot delete a category with {$modelCount} product model(s). Choose a reassignment target or remove the models first.",
            ]);
        }

        if ($reassignTo !== null && $reassignTo > 0) {
            // Verify the reassignment target exists and isn't the same category.
            $target = $this->findCategory($reassignTo);
            if ($target === null) {
                throw new \App\Exceptions\ValidationException([
                    'reassign_to' => 'Reassignment target category does not exist.',
                ]);
            }
            if ($reassignTo === $id) {
                throw new \App\Exceptions\ValidationException([
                    'reassign_to' => 'Cannot reassign models to the category being deleted.',
                ]);
            }
        }

        \App\Support\Database\Connection::transaction(function (PDO $pdo) use ($id, $reassignTo, $category) {
            // Reassign models to the target category.
            if ($reassignTo !== null && $reassignTo > 0) {
                $pdo->prepare('UPDATE product_models SET category_id = ? WHERE category_id = ?')
                    ->execute([$reassignTo, $id]);
            }

            // Reassign children to the deleted category's parent (or null).
            $grandparent = $category['parent_id'] ?? null;
            $pdo->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?')
                ->execute([$grandparent, $id]);

            // Delete the category.
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        });
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
                 seo_title, seo_description, is_published, show_price, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $d['brand_id'], $d['category_id'], $d['name'], $d['slug'],
            $d['processor'] ?? null, $d['ram_gb'] ?? null, $d['storage_type'] ?? null, $d['storage_capacity_gb'] ?? null,
            $d['graphics'] ?? null, $d['display_size_inches'] ?? null, $d['resolution'] ?? null,
            $d['operating_system'] ?? null, $d['description'] ?? null,
            $d['seo_title'] ?? null, $d['seo_description'] ?? null,
            !empty($d['is_published']) ? 1 : 0,
            !isset($d['show_price']) || $d['show_price'] ? 1 : 0,
            $userId,
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
                seo_description = ?, is_published = ?, show_price = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $d['brand_id'], $d['category_id'], $d['name'], $d['slug'],
            $d['processor'] ?? null, $d['ram_gb'] ?? null, $d['storage_type'] ?? null, $d['storage_capacity_gb'] ?? null,
            $d['graphics'] ?? null, $d['display_size_inches'] ?? null, $d['resolution'] ?? null,
            $d['operating_system'] ?? null, $d['description'] ?? null,
            $d['seo_title'] ?? null, $d['seo_description'] ?? null,
            !empty($d['is_published']) ? 1 : 0,
            !isset($d['show_price']) || $d['show_price'] ? 1 : 0,
            $id,
        ]);
    }

    /**
     * Price range for a model: min, max, average selling_price of
     * available inventory units. Returns nulls when no available
     * units exist.
     *
     * @return array{min:?float,max:?float,avg:?float,count:int}
     */
    public function priceRange(int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT MIN(selling_price) AS mn, MAX(selling_price) AS mx, AVG(selling_price) AS av, COUNT(*) AS cnt
             FROM inventory_units
             WHERE product_model_id = ? AND status = 'available'"
        );
        $stmt->execute([$modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['cnt'] === 0) {
            return ['min' => null, 'max' => null, 'avg' => null, 'count' => 0];
        }
        return [
            'min' => (float) $row['mn'],
            'max' => (float) $row['mx'],
            'avg' => round((float) $row['av'], 2),
            'count' => (int) $row['cnt'],
        ];
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
