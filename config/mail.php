<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'driver' => Env::get('MAIL_DRIVER', 'log'), // log | null
    'from_address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
    'from_name' => Env::get('MAIL_FROM_NAME', 'Rasidul Tech Point'),
];
