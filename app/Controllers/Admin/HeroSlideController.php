<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\AuditService;
use App\Services\HeroSlideService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Hero slider admin controller.
 *
 * Routes (all require permission: content.manage):
 *   GET    /admin/hero-slides                  list
 *   GET    /admin/hero-slides/create           form
 *   POST   /admin/hero-slides                  store
 *   GET    /admin/hero-slides/{id}             show / preview markup
 *   GET    /admin/hero-slides/{id}/edit        edit form
 *   POST   /admin/hero-slides/{id}/update      save
 *   POST   /admin/hero-slides/{id}/toggle      activate / deactivate
 *   POST   /admin/hero-slides/{id}/duplicate   duplicate as draft
 *   POST   /admin/hero-slides/{id}/delete      delete
 *   POST   /admin/hero-slides/reorder          bulk reorder
 *   GET    /admin/hero-slides/settings         settings tab
 *   POST   /admin/hero-slides/settings         save settings
 */
final class HeroSlideController
{
    public function index(): Response
    {
        $svc = new HeroSlideService();
        return Response::html(View::render('admin/hero-slides/index', [
            'pageTitle' => 'Homepage Hero Slider',
            'slides' => $svc->all(),
            'settings' => $svc->settings(),
        ]));
    }

    public function create(): Response
    {
        $svc = new HeroSlideService();
        return Response::html(View::render('admin/hero-slides/form', [
            'pageTitle' => 'New Hero Slide',
            'slide' => null,
            'errors' => [],
            'presets' => HeroSlideService::presets(),
            'maxSlides' => $svc->settings()['max_slides'],
        ]));
    }

    public function store(Request $request): Response
    {
        $svc = new HeroSlideService();
        $data = $request->post();
        $this->handleImageUploads($svc, $request, $data, null);

        try {
            $id = $svc->create($data, current_user_id());
            flash_success('Hero slide created.');
            AuditService::record('heroslide.created', 'hero_slide', $id, [], $request);
            return Response::redirect(url('/admin/hero-slides/' . $id));
        } catch (ValidationException $e) {
            $this->flashErrors($e);
            return Response::redirect(url('/admin/hero-slides/create'));
        }
    }

    public function show(string $id): Response
    {
        $slide = (new HeroSlideService())->find((int) $id);
        if ($slide === null) {
            throw new NotFoundException('Hero slide not found.');
        }
        return Response::html(View::render('admin/hero-slides/show', [
            'pageTitle' => 'Slide: ' . ($slide['nav_label'] ?? ''),
            'slide' => $slide,
        ]));
    }

    public function edit(string $id): Response
    {
        $svc = new HeroSlideService();
        $slide = $svc->find((int) $id);
        if ($slide === null) {
            throw new NotFoundException('Hero slide not found.');
        }
        return Response::html(View::render('admin/hero-slides/form', [
            'pageTitle' => 'Edit Hero Slide',
            'slide' => $slide,
            'errors' => [],
            'presets' => HeroSlideService::presets(),
            'maxSlides' => $svc->settings()['max_slides'],
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        $svc = new HeroSlideService();
        $slide = $svc->find((int) $id);
        if ($slide === null) {
            throw new NotFoundException('Hero slide not found.');
        }
        $data = $request->post();
        $this->handleImageUploads($svc, $request, $data, $slide);

        try {
            $svc->update((int) $id, $data, current_user_id());
            flash_success('Hero slide saved.');
            AuditService::record('heroslide.updated', 'hero_slide', (int) $id, [], $request);
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        return Response::redirect(url('/admin/hero-slides/' . $id . '/edit'));
    }

    public function toggle(string $id, Request $request): Response
    {
        $svc = new HeroSlideService();
        $slide = $svc->find((int) $id);
        if ($slide === null) {
            throw new NotFoundException('Hero slide not found.');
        }
        $active = (int) $slide['is_active'] === 1 ? false : true;
        $svc->toggle((int) $id, $active);
        flash_success($active ? 'Slide activated.' : 'Slide deactivated.');
        AuditService::record('heroslide.toggled', 'hero_slide', (int) $id, ['active' => $active], $request);
        return Response::redirect(url('/admin/hero-slides'));
    }

    public function duplicate(string $id, Request $request): Response
    {
        $svc = new HeroSlideService();
        $newId = $svc->duplicate((int) $id, current_user_id());
        flash_success('Slide duplicated (inactive draft copy).');
        AuditService::record('heroslide.duplicated', 'hero_slide', (int) $id, ['new_id' => $newId], $request);
        return Response::redirect(url('/admin/hero-slides/' . $newId . '/edit'));
    }

    public function delete(string $id, Request $request): Response
    {
        (new HeroSlideService())->delete((int) $id);
        flash_success('Hero slide deleted.');
        AuditService::record('heroslide.deleted', 'hero_slide', (int) $id, [], $request);
        return Response::redirect(url('/admin/hero-slides'));
    }

    public function reorder(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        if ($ids !== []) {
            (new HeroSlideService())->reorder($ids);
        }
        return Response::json(['ok' => true]);
    }

    public function settings(): Response
    {
        $svc = new HeroSlideService();
        return Response::html(View::render('admin/hero-slides/settings', [
            'pageTitle' => 'Hero Slider Settings',
            'settings' => $svc->settings(),
            'slideCount' => count($svc->all()),
        ]));
    }

    public function updateSettings(Request $request): Response
    {
        $svc = new HeroSlideService();
        try {
            $svc->updateSettings($request->post(), current_user_id());
            flash_success('Settings saved.');
            AuditService::record('herosettings.updated', 'hero_settings', 1, [], $request);
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        return Response::redirect(url('/admin/hero-slides/settings'));
    }

    /**
     * Render a slide's HTML markup for the live preview iframe.
     * Uses the exact same partial the public site uses.
     */
    public function preview(string $id): Response
    {
        $svc = new HeroSlideService();
        $slide = $svc->find((int) $id);
        if ($slide === null) {
            throw new NotFoundException('Hero slide not found.');
        }
        $html = View::partial('storefront/hero/_slide', ['slide' => $slide, 'i' => 0, 'isActive' => true]);
        return Response::html('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Figtree:wght@400..700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet"><style>body{margin:0;background:#0b0f14}</style>' . View::partial('storefront/hero/_slider_css', []) . '</head><body class="hero-preview"><section class="hero t0 is-active" style="min-height:clamp(360px,60vh,560px)">' . $html . '</section></body></html>');
    }

    // ----------------------------------------------------------- helpers
    private function handleImageUploads(HeroSlideService $svc, Request $request, array &$data, ?array $existing): void
    {
        foreach (['laptop_image_url' => 'laptop_image_url', 'desktop_image' => 'desktop_image'] as $field => $dataKey) {
            $file = $request->file($field);
            if ($file !== null) {
                try {
                    $path = $svc->saveImage($file);
                    if ($path !== null) {
                        $data[$dataKey] = $path;
                    }
                } catch (ValidationException $e) {
                    flash_error(implode(' ', $e->errors));
                }
            }
        }
    }

    private function flashErrors(ValidationException $e): void
    {
        foreach ($e->errors as $field => $msg) {
            flash_error($field . ': ' . $msg);
        }
    }
}
