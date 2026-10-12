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

final class HeroSlideController
{
    public function index(): Response
    {
        $svc = new HeroSlideService();
        return Response::html(View::render('admin/hero-slides/index', [
            'pageTitle' => 'Homepage Hero Slider',
            'slides' => $svc->all(),
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/hero-slides/form', [
            'pageTitle' => 'New Hero Slide',
            'slide' => null,
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $svc = new HeroSlideService();
        $data = $request->post();
        // Handle image uploads
        foreach (['desktop_image', 'mobile_image', 'product_image'] as $field) {
            $file = $request->file($field);
            if ($file !== null) {
                try {
                    $path = $svc->saveImage($file);
                    if ($path !== null) {
                        $data[$field] = $path;
                    }
                } catch (ValidationException $e) {
                    flash_error(implode(' ', $e->errors));
                    return Response::redirect(url('/admin/hero-slides/create'));
                }
            }
        }
        try {
            $id = $svc->create($data, current_user_id());
            flash_success('Hero slide created.');
            AuditService::record('heroslide.created', 'hero_slide', $id, [], $request);
            return Response::redirect(url('/admin/hero-slides/' . $id));
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
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
            'pageTitle' => 'Slide: ' . ($slide['internal_name'] ?? ''),
            'slide' => $slide,
        ]));
    }

    public function edit(string $id): Response
    {
        $slide = (new HeroSlideService())->find((int) $id);
        if ($slide === null) {
            throw new NotFoundException('Hero slide not found.');
        }
        return Response::html(View::render('admin/hero-slides/form', [
            'pageTitle' => 'Edit Hero Slide',
            'slide' => $slide,
            'errors' => [],
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
        // Handle image uploads — keep existing if no new file
        foreach (['desktop_image', 'mobile_image', 'product_image'] as $field) {
            $file = $request->file($field);
            if ($file !== null) {
                try {
                    $path = $svc->saveImage($file);
                    if ($path !== null) {
                        $data[$field] = $path;
                    } else {
                        $data[$field] = $slide[$field] ?? '';
                    }
                } catch (ValidationException $e) {
                    flash_error(implode(' ', $e->errors));
                    return Response::redirect(url('/admin/hero-slides/' . $id . '/edit'));
                }
            } else {
                $data[$field] = $slide[$field] ?? '';
            }
        }
        try {
            $svc->update((int) $id, $data, current_user_id());
            flash_success('Hero slide updated.');
            AuditService::record('heroslide.updated', 'hero_slide', (int) $id, [], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/hero-slides/' . $id));
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
        flash_success('Slide duplicated (inactive copy).');
        AuditService::record('heroslide.duplicated', 'hero_slide', (int) $id, ['new_id' => $newId], $request);
        return Response::redirect(url('/admin/hero-slides/' . $newId));
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
}
