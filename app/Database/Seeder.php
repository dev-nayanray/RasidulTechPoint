<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Seeder runner. Seeders are registered by class name in bin/seed.php.
 */
final class Seeder
{
    /** @param array<class-string> $classes */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $classes,
    ) {
    }

    /** @param callable(string): void $output */
    public function run(callable $output): int
    {
        $count = 0;
        foreach ($this->classes as $class) {
            $output('Seeding: ' . (new \ReflectionClass($class))->getShortName());
            (new $class())->run($this->pdo);
            $count++;
        }
        return $count;
    }
}
