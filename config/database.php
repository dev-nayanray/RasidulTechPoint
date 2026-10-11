<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'rasidul_tech_point'),
    'username' => Env::get('DB_USERNAME', 'root'),
    'password' => Env::get('DB_PASSWORD', ''),
];
