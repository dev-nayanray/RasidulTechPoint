<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'Rasidul Tech Point'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost'),
    'key' => Env::get('APP_KEY', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Dhaka'),
    'currency' => 'BDT',
    'version' => '0.1.0-foundation',
];
