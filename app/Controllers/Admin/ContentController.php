<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Services\ContentService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Content management controller.
 * All routes require content.manage permission.
 */
final class ContentController
{
    public function index(): Response
    {
        $svc = new ContentService();
        return Response::html(View::render('admin/content/dashboard', [
            'pageTitle' => 'Marketing & Content',
            'blocks' => $svc->allBlocks(),
            'banners' => $svc->allBanners(),
            'pages' => $svc->allPages(),
            'faqs' => $svc->allFaqs(),
        ]));
    }

    // ---------------------------------------------------- BLOCKS --

    public function blockIndex(): Response
    {
        return Response::html(View::render('admin/content/blocks/index', [
            'pageTitle' => 'Content Blocks',
            'blocks' => (new ContentService())->allBlocks(),
        ]));
    }

    public function blockEdit(string $key): Response
    {
        $svc = new ContentService();
        $block = $svc->findBlock($key);
        if ($block === null) {
            // Create a new block with this key.
            $block = ['id' => 0, 'block_key' => $key, 'title' => ucfirst(str_replace('_', ' ', $key)), 'body' => '', 'is_published' => 1];
        }
        return Response::html(View::render('admin/content/blocks/edit', [
            'pageTitle' => 'Edit: ' . $block['title'],
            'block' => $block,
        ]));
    }

    public function blockStore(Request $request): Response
    {
        $svc = new ContentService();
        $svc->upsertBlock(
            (string) $request->input('block_key', ''),
            (string) $request->input('title', ''),
            (string) $request->input('body', ''),
            !empty($request->input('is_published')),
            current_user_id(),
        );
        flash_success('Content block saved.');
        return Response::redirect(url('/admin/content/blocks'));
    }

    // ---------------------------------------------------- BANNERS --

    public function bannerIndex(): Response
    {
        return Response::html(View::render('admin/content/banners/index', [
            'pageTitle' => 'Banners',
            'banners' => (new ContentService())->allBanners(),
        ]));
    }

    public function bannerStore(Request $request): Response
    {
        $svc = new ContentService();
        $file = $request->file('image');
        try {
            $imagePath = $svc->saveBannerImage($file);
            if ($imagePath === null && empty($request->input('image_path'))) {
                flash_error('Banner image is required.');
                return Response::redirect(url('/admin/content/banners'));
            }
            $data = $request->post();
            $data['image_path'] = $imagePath ?? $request->input('image_path');
            $svc->createBanner($data, current_user_id());
            flash_success('Banner created.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/content/banners'));
    }

    public function bannerDelete(string $id): Response
    {
        (new ContentService())->deleteBanner((int) $id);
        flash_success('Banner deleted.');
        return Response::redirect(url('/admin/content/banners'));
    }

    // ---------------------------------------------------- PAGES --

    public function pageIndex(): Response
    {
        return Response::html(View::render('admin/content/pages/index', [
            'pageTitle' => 'Info Pages',
            'pages' => (new ContentService())->allPages(),
        ]));
    }

    public function pageCreateForm(): Response
    {
        return Response::html(View::render('admin/content/pages/form', [
            'pageTitle' => 'New Page',
            'page' => null,
            'errors' => [],
        ]));
    }

    public function pageStore(Request $request): Response
    {
        try {
            $svc = new ContentService();
            $id = $svc->createPage($request->post(), current_user_id());
            flash_success('Page created.');
            return Response::redirect(url('/admin/content/pages/' . $id . '/edit'));
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::html(View::render('admin/content/pages/form', [
                'pageTitle' => 'New Page',
                'page' => $request->post(),
                'errors' => $e->errors,
            ]), 422);
        }
    }

    public function pageEditForm(string $id): Response
    {
        $svc = new ContentService();
        $pages = $svc->allPages();
        $page = null;
        foreach ($pages as $p) {
            if ((int) $p['id'] === (int) $id) {
                $page = $p;
                break;
            }
        }
        if ($page === null) {
            throw new NotFoundException('Page not found.');
        }
        return Response::html(View::render('admin/content/pages/form', [
            'pageTitle' => 'Edit: ' . $page['title'],
            'page' => $page,
            'errors' => [],
        ]));
    }

    public function pageUpdate(string $id, Request $request): Response
    {
        try {
            (new ContentService())->updatePage((int) $id, $request->post());
            flash_success('Page updated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/content/pages/' . $id . '/edit'));
    }

    public function pageDelete(string $id): Response
    {
        (new ContentService())->deletePage((int) $id);
        flash_success('Page deleted.');
        return Response::redirect(url('/admin/content/pages'));
    }

    // ---------------------------------------------------- FAQs --

    public function faqIndex(): Response
    {
        return Response::html(View::render('admin/content/faqs/index', [
            'pageTitle' => 'FAQs',
            'faqs' => (new ContentService())->allFaqs(),
        ]));
    }

    public function faqStore(Request $request): Response
    {
        try {
            (new ContentService())->createFaq($request->post());
            flash_success('FAQ created.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/content/faqs'));
    }

    public function faqDelete(string $id): Response
    {
        (new ContentService())->deleteFaq((int) $id);
        flash_success('FAQ deleted.');
        return Response::redirect(url('/admin/content/faqs'));
    }

    // ---------------------------------------------------- SEO --

    public function seoIndex(): Response
    {
        $svc = new ContentService();
        $seo = $svc->findSeo('home') ?? ['page_key' => 'home', 'meta_title' => '', 'meta_description' => '', 'og_title' => '', 'og_description' => '', 'og_image_path' => '', 'canonical_url' => '', 'extra_head_tags' => ''];
        return Response::html(View::render('admin/content/seo', [
            'pageTitle' => 'SEO Metadata',
            'seo' => $seo,
        ]));
    }

    public function seoStore(Request $request): Response
    {
        $pageKey = (string) $request->input('page_key', 'home');
        (new ContentService())->upsertSeo($pageKey, $request->post());
        flash_success('SEO metadata saved.');
        return Response::redirect(url('/admin/content/seo?page_key=' . $pageKey));
    }
}
