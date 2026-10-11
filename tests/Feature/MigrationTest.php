<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class MigrationTest extends TestCase
{
    public function testAllFoundationTablesExist(): void
    {
        $expected = [
            'users', 'roles', 'permissions', 'user_roles', 'role_permissions',
            'password_reset_tokens', 'login_attempts', 'audit_logs',
            'business_profiles', 'locations', 'settings', 'notifications', 'business_hours',
            'brands', 'categories', 'product_models', 'product_images',
            'inventory_units', 'stock_movements',
            'customers', 'orders', 'order_items', 'payments',
            'migrations',
        ];
        $rows = $this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($expected as $table) {
            $this->assertContains($table, $rows, "Table {$table} should exist");
        }
    }

    public function testMigrationRunnerTracksBatches(): void
    {
        $count = (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $this->assertSame(13, $count, 'thirteen migration files should be recorded');

        // Re-running is a no-op.
        $migrator = new \App\Support\Database\Migrator($this->pdo(), BASE_PATH . '/database/migrations');
        $this->assertSame(0, count($migrator->pending()), 'no migrations should remain pending');
    }

    public function testForeignKeyRejectsOrphanUserRole(): void
    {
        $this->assertThrows(\PDOException::class, function () {
            $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (999999, 1)')->execute();
        }, 'FK on user_roles.user_id must reject unknown users');
    }

    public function testForeignKeyRejectsOrderItemWithoutOrder(): void
    {
        $this->assertThrows(\PDOException::class, function () {
            $this->pdo()->prepare('INSERT INTO order_items (order_id, inventory_unit_id, product_name, sku, unit_price, line_total)
                VALUES (999999, 1, "x", "x", 1, 1)')->execute();
        }, 'FK on order_items.order_id must reject unknown orders');
    }

    public function testUniqueSkuEnforced(): void
    {
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $this->makeUnit($modelId, ['sku' => 'UNIQ-1']);

        $this->assertThrows(\PDOException::class, function () use ($modelId) {
            $this->makeUnit($modelId, ['sku' => 'UNIQ-1']);
        }, 'duplicate SKU must be rejected by the unique index');
    }

    public function testUniqueSerialAllowsNulls(): void
    {
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $this->makeUnit($modelId, ['serial_number' => '']);
        $this->makeUnit($modelId, ['serial_number' => '']); // second NULL must be fine
        $this->assertTrue(true);
    }

    public function testUniqueOrderReferenceEnforced(): void
    {
        $customerId = $this->makeCustomer();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO orders (reference, customer_id) VALUES (?, ?)'
        );
        $stmt->execute(['DUP-REF', $customerId]);

        $this->assertThrows(\PDOException::class, function () use ($customerId) {
            $this->pdo()->prepare('INSERT INTO orders (reference, customer_id) VALUES (?, ?)')
                ->execute(['DUP-REF', $customerId]);
        }, 'duplicate order reference must be rejected');
    }

    public function testMoneyColumnsUseDecimal(): void
    {
        foreach (['orders' => ['subtotal', 'total'], 'payments' => ['amount'], 'inventory_units' => ['selling_price']] as $table => $columns) {
            foreach ($columns as $column) {
                $type = $this->pdo()->query(
                    "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'"
                )->fetchColumn();
                $this->assertSame('decimal', $type, "{$table}.{$column} must be DECIMAL");
            }
        }
    }

    public function testCharsetIsUtf8mb4(): void
    {
        $charset = $this->pdo()->query(
            "SELECT CCSA.CHARACTER_SET_NAME FROM information_schema.TABLES T
             JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
               ON CCSA.COLLATION_NAME = T.TABLE_COLLATION
             WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = 'users'"
        )->fetchColumn();
        $this->assertSame('utf8mb4', $charset);
    }

    public function testTransactionRollbackRestoresState(): void
    {
        [$brandId, $categoryId, $modelId] = $this->makeCatalog();
        $unitId = $this->makeUnit($modelId);

        try {
            \App\Support\Database\Connection::transaction(function ($pdo) use ($unitId) {
                $pdo->prepare('UPDATE inventory_units SET status = "damaged" WHERE id = ?')->execute([$unitId]);
                throw new \RuntimeException('boom');
            });
            $this->fail('exception should propagate');
        } catch (\RuntimeException) {
        }

        $status = $this->pdo()->prepare('SELECT status FROM inventory_units WHERE id = ?');
        $status->execute([$unitId]);
        $this->assertSame('awaiting_inspection', (string) $status->fetchColumn(), 'rollback must undo the update');
    }
}
