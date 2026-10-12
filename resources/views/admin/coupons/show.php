<?php /** @var array $coupon
 *  @var list<array> $usages
 *  @var int $usageCount
 *  @var bool $canApprove
 *  @var bool $canManage
 *  @var list<string> $statuses
 *  @var list<string> $transitions
 */
$status = (string) $coupon['status'];
$valueText = $coupon['discount_type'] === 'percentage'
    ? ((float) $coupon['discount_value']) . '%'
    : money($coupon['discount_value']);
$usageLimitText = $coupon['usage_limit'] !== null ? (int) $coupon['usage_limit'] : '∞';
$validity = 'Always';
if ($coupon['starts_at'] !== null && $coupon['ends_at'] !== null) {
    $validity = local_datetime($coupon['starts_at']) . ' → ' . local_datetime($coupon['ends_at']);
} elseif ($coupon['ends_at'] !== null) {
    $validity = 'Until ' . local_datetime($coupon['ends_at']);
} elseif ($coupon['starts_at'] !== null) {
    $validity = 'From ' . local_datetime($coupon['starts_at']);
}
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/coupons')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Coupons</a></p>
<div class="mt-2 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">
            <span class="font-mono"><?= e($coupon['code']) ?></span>
        </h1>
        <p class="mt-1 text-sm text-slate-500"><?= e($coupon['description'] ?? '—') ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= \App\Support\View::component('badge', ['status' => $status]) ?>
        <?php if ($canManage): ?>
            <a href="<?= e(url('/admin/coupons/' . $coupon['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Definition</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs uppercase text-slate-400">Discount</dt><dd class="font-medium text-slate-900"><?= e($valueText ?> (<?= e(ucfirst($coupon['discount_type'])) ?>)</dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Minimum subtotal</dt><dd class="font-medium text-slate-900"><?= (float) $coupon['min_subtotal'] > 0 ? money($coupon['min_subtotal']) : 'None' ?></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Max discount cap</dt><dd class="font-medium text-slate-900"><?= $coupon['max_discount'] !== null ? money($coupon['max_discount']) : 'No cap' ?></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Validity window</dt><dd class="font-medium text-slate-900"><?= e($validity) ?></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Total usage limit</dt><dd class="font-medium text-slate-900"><?= e((string) $usageLimitText) ?></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Per-customer limit</dt><dd class="font-medium text-slate-900"><?= e((string) $coupon['usage_limit_per_customer']) ?></dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Applies to</dt><dd class="font-medium text-slate-900">
                    <?= e(ucfirst($coupon['applies_to'])) ?> (<?= e(ucfirst($coupon['eligibility_mode'])) ?>)
                </dd></div>
                <div><dt class="text-xs uppercase text-slate-400">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($coupon['created_at'])) ?><?php if (!empty($coupon['created_by_name'])): ?> by <?= e($coupon['created_by_name']) ?><?php endif; ?></dd></div>
                <?php if ($coupon['approved_at'] !== null): ?>
                    <div><dt class="text-xs uppercase text-slate-400">Approved</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($coupon['approved_at'])) ?><?php if (!empty($coupon['approved_by_name'])): ?> by <?= e($coupon['approved_by_name']) ?><?php endif; ?></dd></div>
                <?php endif; ?>
                <?php if ($coupon['rejected_at'] !== null): ?>
                    <div><dt class="text-xs uppercase text-slate-400">Rejected</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($coupon['rejected_at'])) ?> by <?= e($coupon['rejected_by_name'] ?? 'Unknown') ?></dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs uppercase text-slate-400">Reason</dt><dd class="text-red-700"><?= e($coupon['rejection_reason']) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (!empty($coupon['target_products']) || !empty($coupon['target_categories'])): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Eligibility rules</h2>
                <?php if (!empty($coupon['target_products'])): ?>
                    <p class="mt-3 text-xs uppercase text-slate-400">Products (<?= e(ucfirst($coupon['eligibility_mode'])) ?>)</p>
                    <ul class="mt-1 flex flex-wrap gap-1.5 text-xs">
                        <?php foreach ($coupon['target_products'] as $p): ?>
                            <li class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-slate-700"><?= e($p['name']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($coupon['target_categories'])): ?>
                    <p class="mt-3 text-xs uppercase text-slate-400">Categories (<?= e(ucfirst($coupon['eligibility_mode'])) ?>)</p>
                    <ul class="mt-1 flex flex-wrap gap-1.5 text-xs">
                        <?php foreach ($coupon['target_categories'] as $c): ?>
                            <li class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-slate-700"><?= e($c['name']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="rounded-xl border border-slate-200 bg-white">
            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100">
                <h2 class="text-base font-semibold text-slate-900">Redemptions</h2>
                <span class="text-xs text-slate-500"><?= $usageCount ?> total</span>
            </div>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50"><tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Applied by</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Applied at</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Discount</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($usages as $u): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a class="font-mono text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/orders/' . $u['order_id'])) ?>"><?= e($u['order_reference']) ?></a></td>
                        <td class="px-4 py-3 text-slate-700"><?= e($u['customer_name'] ?? 'Walk-in') ?></td>
                        <td class="px-4 py-3 text-slate-500 text-xs"><?= e($u['applied_by_name'] ?? '—') ?></td>
                        <td class="px-4 py-3 text-xs text-slate-500"><?= e(local_datetime($u['applied_at'])) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($u['discount_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($usages === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No redemptions yet.</p><?php endif; ?>
        </div>
    </div>

    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Status controls</h2>
            <p class="mt-1 text-xs text-slate-500">Current status: <strong><?= e(ucfirst(str_replace('_', ' ', $status))) ?></strong></p>

            <div class="mt-4 space-y-2">
                <?php if ($canManage && $status === 'draft'): ?>
                    <form method="post" action="<?= e(url('/admin/coupons/' . $coupon['id'] . '/submit')) ?>" data-loading-form>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-primary w-full">Submit for approval</button>
                    </form>
                <?php endif; ?>

                <?php if ($canApprove && in_array($status, ['draft', 'pending_approval'], true)): ?>
                    <form method="post" action="<?= e(url('/admin/coupons/' . $coupon['id'] . '/approve')) ?>" data-loading-form>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-primary w-full">Approve & activate</button>
                    </form>
                    <details class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm">
                        <summary class="cursor-pointer font-medium text-red-700">Reject</summary>
                        <form method="post" action="<?= e(url('/admin/coupons/' . $coupon['id'] . '/reject')) ?>" class="mt-2 space-y-2" data-loading-form data-confirm="Reject this coupon? This cannot be undone.">
                            <?= csrf_field() ?>
                            <label class="block text-xs font-medium text-red-700">Reason</label>
                            <input type="text" name="rejection_reason" class="input" placeholder="Reason for rejection" required>
                            <button type="submit" class="btn-danger w-full text-xs">Reject coupon</button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if ($canManage && $status === 'active'): ?>
                    <form method="post" action="<?= e(url('/admin/coupons/' . $coupon['id'] . '/pause')) ?>" data-loading-form data-confirm="Pause this coupon? It will stop being redeemable until resumed.">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-secondary w-full">Pause</button>
                    </form>
                <?php endif; ?>

                <?php if ($canManage && $status === 'paused'): ?>
                    <form method="post" action="<?= e(url('/admin/coupons/' . $coupon['id'] . '/resume')) ?>" data-loading-form>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-primary w-full">Resume</button>
                    </form>
                <?php endif; ?>

                <?php if ($canManage && in_array($status, ['active', 'paused'], true)): ?>
                    <form method="post" action="<?= e(url('/admin/coupons/' . $coupon['id'] . '/archive')) ?>" data-loading-form data-confirm="Archive this coupon? It cannot be revived; historical usages remain.">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-danger w-full text-xs">Archive</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($status, ['rejected', 'archived'], true)): ?>
                    <p class="rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600">This coupon is <?= e($status) ?> and cannot be modified. Create a new coupon to replace it.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-5 text-xs text-slate-500">
            <h3 class="font-semibold text-slate-700">How redemption works</h3>
            <ul class="mt-2 space-y-1.5 list-disc pl-4">
                <li>The discount is <strong>always recomputed server-side</strong> from the coupon definition — the client's claimed amount is advisory.</li>
                <li>The discount is clamped to the order subtotal so totals can never go negative.</li>
                <li>A coupon can be applied at most once per order (DB-enforced unique constraint).</li>
                <li>Per-customer and overall usage limits are checked under row lock at checkout.</li>
            </ul>
        </div>
    </aside>
</div>
