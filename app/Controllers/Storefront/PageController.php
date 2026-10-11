<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class PageController
{
    public function about(): Response
    {
        return Response::html(View::render('storefront/about', [
            'pageTitle' => 'About us',
            'metaTitle' => 'About Rasidul Tech Point',
        ]));
    }

    public function contact(): Response
    {
        return Response::html(View::render('storefront/contact', [
            'pageTitle' => 'Contact',
            'metaTitle' => 'Contact Rasidul Tech Point',
        ]));
    }
}
