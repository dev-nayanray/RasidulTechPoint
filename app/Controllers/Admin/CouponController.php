<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CatalogRepository;
use App\Repositories\CouponRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\CouponService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Coupon & promotion management.
 *
 * Permission map:
 *  - coupons.manage   : create / edit / submit for approval / pause / resume / archive
 *  - coupons.approve  : approve / reject pending coupons
 *
 * Coupon status lifecycle (enforced by CouponService::STATUS_TRANSITIONS):
 *   draft → pending_approval → active ⇄ paused → archived
 *                            ↘ rejected (terminal)
 *
 * Coupons never go live until they reach status='active' AND the
 * validity window is open AND usage limits are not exhausted. The
 * server recalculates the discount on every redemption from the
 * coupon definition — the client's claimed discount is advisory.
 */
final class CouponController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $repo = new CouponRepository();
        $result = $repo->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );

        return Response::html(View::render('admin/coupons/index', [
            'pageTitle' => 'Coupons & Promotions',
            'coupons' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => CouponService::STATUSES,
            'pendingCount' => $repo->countPendingApprovals(),
            'canApprove' => AuthService::can('coupons.approve'),
        ]));
    }

    public function show(string $id): Response
    {
        $coupon = (new CouponRepository())->find((int) $id);
        if ($coupon === null) {
            throw new NotFoundException('Coupon not found.');
        }
        $repo = new CouponRepository();
        return Response::html(View::render('admin/coupons/show', [
            'pageTitle' => 'Coupon ' . $coupon['code'],
            'coupon' => $coupon,
            'usages' => $repo->usagesForCoupon((int) $coupon['id']),
            'usageCount' => $repo->countUsages((int) $coupon['id']),
            'canApprove' => AuthService::can('coupons.approve'),
            'canManage' => AuthService::can('coupons.manage'),
            'statuses' => CouponService::STATUSES,
            'transitions' => CouponService::STATUS_TRANSITIONS[(string) $coupon['status']] ?? [],
        ]));
    }

    public function create(): Response
    {
        return Response::html(View::render('admin/coupons/form', [
            'pageTitle' => 'New coupon',
            'coupon' => null,
            'errors' => [],
            'products' => (new CatalogRepository())->allModels(),
            'categories' => (new CatalogRepository())->allCategories(),
            'discountTypes' => CouponService::DISCOUNT_TYPES,
            'appliesTo' => CouponService::APPLIES_TO,
            'eligibilityModes' => CouponService::ELIGIBILITY_MODES,
        ]));
    }

    public function store(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'code' => 'required|string|max:64',
                'description' => 'nullable|string|max:255',
                'discount_type' => 'required|in:fixed,percentage',
                'discount_value' => 'required|decimal:2',
                'min_subtotal' => 'nullable|decimal:2',
                'max_discount' => 'nullable|decimal:2',
                'starts_at' => 'nullable|string|max:32',
                'ends_at' => 'nullable|string|max:32',
                'usage_limit' => 'nullable|integer',
                'usage_limit_per_customer' => 'required|integer',
                'applies_to' => 'required|in:all,categories,products',
                'eligibility_mode' => 'required|in:include,exclude',
                'target_product_ids' => 'nullable|array',
                'target_category_ids' => 'nullable|array',
            ], [
                'code' => 'Coupon code',
                'discount_type' => 'Discount type',
                'discount_value' => 'Discount value',
                'usage_limit_per_customer' => 'Per-customer limit',
            ])->validate();
        } catch (ValidationException $e) {
            return Response::html(View::render('admin/coupons/form', [
                'pageTitle' => 'New coupon',
                'coupon' => $request->post(),
                'errors' => $e->errors,
                'products' => (new CatalogRepository())->allModels(),
                'categories' => (new CatalogRepository())->allCategories(),
                'discountTypes' => CouponService::DISCOUNT_TYPES,
                'appliesTo' => CouponService::APPLIES_TO,
                'eligibilityModes' => CouponService::ELIGIBILITY_MODES,
            ]), 422);
        }

        try {
            $id = (new CouponService())->create($this->normalizeInput($data), current_user_id());
            flash_success('Coupon created as draft. Submit it for approval to go live.');
            AuditService::record('coupon.created', 'coupon', $id, ['code' => $data['code']], $request);
            return Response::redirect(url('/admin/coupons/' . $id));
        } catch (ValidationException $e) {
            Session::flashInput($request->post());
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/coupons/create'));
        }
    }

    public function edit(string $id): Response
    {
        $coupon = (new CouponRepository())->find((int) $id);
        if ($coupon === null) {
            throw new NotFoundException('Coupon not found.');
        }
        return Response::html(View::render('admin/coupons/form', [
            'pageTitle' => 'Edit coupon ' . $coupon['code'],
            'coupon' => $coupon,
            'errors' => [],
            'products' => (new CatalogRepository())->allModels(),
            'categories' => (new CatalogRepository())->allCategories(),
            'discountTypes' => CouponService::DISCOUNT_TYPES,
            'appliesTo' => CouponService::APPLIES_TO,
            'eligibilityModes' => CouponService::ELIGIBILITY_MODES,
        ]));
    }

    public function update(string $id, Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'code' => 'required|string|max:64',
                'description' => 'nullable|string|max:255',
                'discount_type' => 'required|in:fixed,percentage',
                'discount_value' => 'required|decimal:2',
                'min_subtotal' => 'nullable|decimal:2',
                'max_discount' => 'nullable|decimal:2',
                'starts_at' => 'nullable|string|max:32',
                'ends_at' => 'nullable|string|max:32',
                'usage_limit' => 'nullable|integer',
                'usage_limit_per_customer' => 'required|integer',
                'applies_to' => 'required|in:all,categories,products',
                'eligibility_mode' => 'required|in:include,exclude',
                'target_product_ids' => 'nullable|array',
                'target_category_ids' => 'nullable|array',
            ], [
                'code' => 'Coupon code',
                'discount_type' => 'Discount type',
                'discount_value' => 'Discount value',
                'usage_limit_per_customer' => 'Per-customer limit',
            ])->validate();
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/coupons/' . $id . '/edit'));
        }

        try {
            (new CouponService())->update((int) $id, $this->normalizeInput($data), current_user_id());
            flash_success('Coupon updated. Editing reverted status to draft — submit for approval to go live again.');
            AuditService::record('coupon.updated', 'coupon', (int) $id, ['code' => $data['code']], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    // ---------------------------------------------------- STATUS TRANSITIONS --

    public function submit(string $id, Request $request): Response
    {
        try {
            (new CouponService())->submitForApproval((int) $id, current_user_id());
            flash_success('Coupon submitted for approval.');
            AuditService::record('coupon.submitted', 'coupon', (int) $id, [], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    public function approve(string $id, Request $request): Response
    {
        if (!AuthService::can('coupons.approve')) {
            flash_error('You do not have permission to approve coupons.');
            return Response::redirect(url('/admin/coupons/' . $id));
        }
        try {
            (new CouponService())->approve((int) $id, current_user_id());
            flash_success('Coupon approved and is now active.');
            AuditService::record('coupon.approved', 'coupon', (int) $id, [], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    public function reject(string $id, Request $request): Response
    {
        if (!AuthService::can('coupons.approve')) {
            flash_error('You do not have permission to reject coupons.');
            return Response::redirect(url('/admin/coupons/' . $id));
        }
        $reason = trim((string) $request->input('rejection_reason', ''));
        if ($reason === '') {
            flash_error('A rejection reason is required.');
            return Response::redirect(url('/admin/coupons/' . $id));
        }
        try {
            (new CouponService())->reject((int) $id, current_user_id(), $reason);
            flash_success('Coupon rejected.');
            AuditService::record('coupon.rejected', 'coupon', (int) $id, ['reason' => $reason], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    public function pause(string $id, Request $request): Response
    {
        try {
            (new CouponService())->pause((int) $id, current_user_id());
            flash_success('Coupon paused.');
            AuditService::record('coupon.paused', 'coupon', (int) $id, [], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    public function resume(string $id, Request $request): Response
    {
        try {
            (new CouponService())->resume((int) $id, current_user_id());
            flash_success('Coupon resumed.');
            AuditService::record('coupon.resumed', 'coupon', (int) $id, [], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    public function archive(string $id, Request $request): Response
    {
        try {
            (new CouponService())->archive((int) $id, current_user_id());
            flash_success('Coupon archived.');
            AuditService::record('coupon.archived', 'coupon', (int) $id, [], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/coupons/' . $id));
    }

    // ---------------------------------------------------- CHECKOUT PREVIEW --

    /**
     * Preview a coupon's discount for a cart, without committing.
     * Used by the POS / online checkout to show the customer the
     * server-computed discount before they confirm.
     *
     * Returns JSON: {ok:bool, discount?:string, code?:string, message?:string}
     */
    public function preview(Request $request): Response
    {
        $code = trim((string) $request->input('code', ''));
        $subtotal = (float) $request->input('subtotal', 0);
        $customerId = $request->input('customer_id') !== null ? (int) $request->input('customer_id') : null;

        if ($code === '' || $subtotal <= 0) {
            return Response::json(['ok' => false, 'message' => 'Code and subtotal are required.']);
        }

        try {
            $result = (new CouponService())->validateAndCompute($code, ['subtotal' => $subtotal], $customerId);
            return Response::json([
                'ok' => true,
                'coupon_id' => $result['coupon_id'],
                'code' => $result['code'],
                'discount' => number_format($result['discount'], 2, '.', ''),
            ]);
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'message' => implode(' ', $e->errors)]);
        }
    }

    // ---------------------------------------------------- HELPERS --

    private function normalizeInput(array $data): array
    {
        $data['discount_value'] = (float) $data['discount_value'];
        $data['min_subtotal'] = (float) ($data['min_subtotal'] ?? 0);
        $data['max_discount'] = ($data['max_discount'] ?? '') === '' ? null : (float) $data['max_discount'];
        $data['usage_limit'] = ($data['usage_limit'] ?? '') === '' ? null : (int) $data['usage_limit'];
        $data['usage_limit_per_customer'] = max(1, (int) ($data['usage_limit_per_customer'] ?? 1));
        $data['target_product_ids'] = array_values(array_filter(array_map('intval', (array) ($data['target_product_ids'] ?? [])), fn ($v) => $v > 0));
        $data['target_category_ids'] = array_values(array_filter(array_map('intval', (array) ($data['target_category_ids'] ?? [])), fn ($v) => $v > 0));
        return $data;
    }
}
