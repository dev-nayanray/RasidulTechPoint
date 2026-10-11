<?php

declare(strict_types=1);

/**
 * Database bootstrap: establishes the shared PDO connection on demand.
 * Configuration comes from config/database.php (env driven).
 */

require __DIR__ . '/app.php';

return App\Support\Database\Connection::pdo();
