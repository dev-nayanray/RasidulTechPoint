<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Database\Migrator;
use App\Support\Request;
use App\Support\Session;

/**
 * Base test case.
 *
 * Boots the application against the TEST database (rasidul_tech_point_test),
 * re-migrates it from scratch before every class run, and provides
 * PHPUnit-style assertions. Deliberately dependency-free so the suite runs
 * anywhere PHP + MySQL exist (see bin/test.php).
 */
abstract class TestCase
{
    public int $assertions = 0;
    protected static bool $booted = false;
    protected static \PDO $pdo;
    protected array $fakeSession = [];

    public static function bootOnce(): void
    {
        if (self::$booted) {
            return;
        }

        // Force test DB through config override (real .env is untouched).
        Config::set('app.env', 'local');
        Config::set('app.debug', true);
        Config::set('database.database', 'rasidul_tech_point_test');

        $pdo = Connection::build(
            (string) Config::get('database.host'),
            (int) Config::get('database.port'),
            'rasidul_tech_point_test',
            (string) Config::get('database.username'),
            (string) Config::get('database.password'),
        );
        Connection::swap($pdo);
        self::$pdo = $pdo;

        self::freshDatabase();
        self::seedBase();

        self::$booted = true;
    }

    protected static function freshDatabase(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['migrations', 'audit_logs', 'login_attempts', 'password_reset_tokens',
                  'role_permissions', 'user_roles', 'permissions', 'roles', 'users',
                  'notifications', 'settings', 'locations', 'business_profiles', 'business_hours',
                  'product_images', 'inventory_units', 'stock_movements', 'product_models',
                  'categories', 'brands',
                  'payments', 'order_items', 'orders', 'customers'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        (new Migrator($pdo, BASE_PATH . '/database/migrations'))->migrate(fn () => null);
    }

    protected static function seedBase(): void
    {
        (new \App\Database\Seeder(self::$pdo, [
            \App\Database\Seeders\RolePermissionSeeder::class,
            \App\Database\Seeders\BusinessProfileSeeder::class,
        ]))->run(fn () => null);
    }

    public function setUp(): void
    {
        self::bootOnce();
        $this->fakeSession = [];
        Session::startTest($this->fakeSession);
        \App\Services\AuthService::flushState();
        // Each test method starts with a clean throttle state.
        self::$pdo->exec('DELETE FROM login_attempts');
    }

    public function tearDown(): void
    {
        \App\Services\AuthService::flushState();
        $this->fakeSession = [];
    }

    // ------------------------------------------------------------ helpers --

    protected function pdo(): \PDO
    {
        return self::$pdo;
    }

    protected function syntheticRequest(string $method = 'GET', string $uri = '/', array $post = []): Request
    {
        return Request::synthetic($method, $uri, [], $post, ['REMOTE_ADDR' => '10.9.9.9']);
    }

    /** Create a user with roles; returns id. */
    protected function makeUser(string $email, string $password = 'Password-12345', array $roleSlugs = []): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
        $stmt->execute(['Test ' . $email, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $this->pdo()->lastInsertId();

        foreach ($roleSlugs as $slug) {
            $roleId = $this->roleId($slug);
            $this->pdo()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $roleId]);
        }
        return $id;
    }

    protected function roleId(string $slug): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM roles WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int) $stmt->fetchColumn();
    }

    /** Simulate an authenticated session for a user id. */
    protected function actingAs(int $userId): void
    {
        Session::set('user_id', $userId);
        \App\Services\AuthService::flushState();
        \App\Services\AuthService::boot();
    }

    /** Create catalog basics; returns [brandId, categoryId, modelId]. */
    protected function makeCatalog(): array
    {
        $unique = bin2hex(random_bytes(4));
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO brands (name, slug) VALUES (?, ?)')->execute(['TestBrand ' . $unique, 'test-brand-' . $unique]);
        $brandId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)')->execute(['TestCat ' . $unique, 'test-cat-' . $unique]);
        $categoryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO product_models (brand_id, category_id, name, slug, is_published) VALUES (?, ?, ?, ?, 1)'
        )->execute([$brandId, $categoryId, 'TestModel 100 ' . $unique, 'testmodel-100-' . $unique]);
        $modelId = (int) $pdo->lastInsertId();
        return [$brandId, $categoryId, $modelId];
    }

    protected function makeUnit(int $modelId, array $overrides = []): int
    {
        $data = array_merge([
            'sku' => 'TEST-' . bin2hex(random_bytes(4)),
            'serial_number' => '',
            'product_model_id' => $modelId,
            'condition_grade' => 'B',
            'purchase_cost' => '100.00',
            'landed_cost' => '10.00',
            'selling_price' => '150.00',
            'supplier_ref' => 'TestSupplier',
            'purchase_ref' => 'LOT-1',
        ], $overrides);
        return (new \App\Services\InventoryService())->receive($data, 1);
    }

    protected function makeCustomer(string $phone = ''): int
    {
        $phone = $phone !== '' ? $phone : '+88017' . random_int(10000000, 99999999);
        $stmt = $this->pdo()->prepare('INSERT INTO customers (name, phone) VALUES (?, ?)');
        $stmt->execute(['Test Customer', $phone]);
        return (int) $this->pdo()->lastInsertId();
    }

    protected function makeAvailableUnit(int $modelId, array $overrides = []): int
    {
        $unitId = $this->makeUnit($modelId, $overrides);
        (new \App\Services\InventoryService())->passInspection($unitId, 'test inspection', 1);
        return $unitId;
    }

    public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            throw new \RuntimeException(
                $message ?: 'Failed asserting not same: ' . var_export($expected, true)
            );
        }
    }

    // -------------------------------------------------------- assertions --

    public function assertTrue(mixed $condition, string $message = 'Failed asserting true'): void
    {
        $this->assertions++;
        if ($condition !== true) {
            throw new \RuntimeException($message);
        }
    }

    public function assertFalse(mixed $condition, string $message = 'Failed asserting false'): void
    {
        $this->assertTrue($condition === false, $message);
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message ?: 'Failed asserting same: ' . var_export($expected, true)
                    . ' vs ' . var_export($actual, true)
            );
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        // loose equality on purpose for string/numeric DB comparisons
        if ($expected != $actual) { // phpcs:ignore
            throw new \RuntimeException(
                $message ?: 'Failed asserting equals: ' . var_export($expected, true)
                    . ' vs ' . var_export($actual, true)
            );
        }
    }

    public function assertNull(mixed $actual, string $message = 'Failed asserting null'): void
    {
        $this->assertions++;
        if ($actual !== null) {
            throw new \RuntimeException($message . ': ' . var_export($actual, true));
        }
    }

    public function assertNotNull(mixed $actual, string $message = 'Failed asserting not null'): void
    {
        $this->assertions++;
        if ($actual === null) {
            throw new \RuntimeException($message);
        }
    }

    public function assertCount(int $expected, array|Countable $actual, string $message = ''): void
    {
        $this->assertions++;
        if (count($actual) !== $expected) {
            throw new \RuntimeException(
                $message ?: "Failed asserting count {$expected}, got " . count($actual)
            );
        }
    }

    public function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException($message ?: 'Failed asserting contains ' . var_export($needle, true));
        }
    }

    public function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (in_array($needle, $haystack, true)) {
            throw new \RuntimeException($message ?: 'Failed asserting not contains ' . var_export($needle, true));
        }
    }

    public function assertStringStartsWith(string $prefix, string $value, string $message = ''): void
    {
        $this->assertions++;
        if (!str_starts_with($value, $prefix)) {
            throw new \RuntimeException(
                $message ?: "Failed asserting that '" . $value . "' starts with '" . $prefix . "'"
            );
        }
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException(
                $message ?: "Failed asserting that '" . $haystack . "' contains '" . $needle . "'"
            );
        }
    }

    public function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!($actual >= $expected)) {
            throw new \RuntimeException(
                $message ?: "Failed asserting {$actual} >= {$expected}"
            );
        }
    }

    public function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!($actual > $expected)) {
            throw new \RuntimeException(
                $message ?: "Failed asserting {$actual} > {$expected}"
            );
        }
    }

    public function assertMatchesRegularExpression(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertions++;
        if (!preg_match($pattern, $subject)) {
            throw new \RuntimeException($message ?: "Failed asserting '{$subject}' matches {$pattern}");
        }
    }

    public function assertThrows(string $exceptionClass, callable $fn, string $message = ''): void
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return;
            }
            throw new \RuntimeException(
                $message ?: "Expected {$exceptionClass} but got " . get_class($e) . ': ' . $e->getMessage()
            );
        }
        throw new \RuntimeException($message ?: "Expected {$exceptionClass} but nothing was thrown");
    }

    public function fail(string $message): void
    {
        throw new \RuntimeException($message);
    }
}
