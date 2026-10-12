<?php

declare(strict_types=1);

namespace App\Controllers\Storefront;

use App\Services\HeroSlideService;
use App\Support\Cache\SimpleCache;
use App\Support\Response;
use App\Support\View;

/**
 * Public hero-slider controller.
 *
 * - GET /            -> not used (HomeController renders the homepage)
 * - GET /api/hero-slider  -> JSON payload with settings + published slides
 *   (5-minute cache; invalidated on every admin write)
 */
final class HeroSliderController
{
    public function api(): Response
    {
        $cached = SimpleCache::get('hero_slider.public', 300);
        if ($cached !== null) {
            return Response::json($cached)
                ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
                ->header('X-Hero-Cache', 'HIT');
        }
        $payload = (new HeroSlideService())->publicPayload();
        SimpleCache::set('hero_slider.public', $payload);
        return Response::json($payload)
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            ->header('X-Hero-Cache', 'MISS');
    }
}
