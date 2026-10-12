<?php /** @var list<array> $coupons
 *  @var list<string> $statuses
 *  @var int $pendingCount
 *  @var bool $canApprove
 */
?>
<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Coupons & Promotions</h1>
        <p class="mt-1 text-sm text-slate-500">Discount codes for online and POS checkout. Every redemption is server-recalculated from the coupon definition.</p>
    </div>
    <a href="<?= e(url('/admin/coupons/create')) ?>" class="btn-primary">+ New coupon</a>
</div>

<?php if ($pendingCount > 0 && $canApprove): ?>
    <a href="<?= e(url('/admin/coupons?status=pending_approval')) ?>" class="mt-3 inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-100">
        <?= \App\Support\View::component('icon', ['name' => 'alert', 'class' => 'h-4 w-4']) ?>
        <?= (int) $pendingCount ?> coupon(s) awaiting your approval
    </a>
<?php endif; ?>

<form method="get" action="<?= e(url('/admin/coupons')) ?>" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="cp-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="cp-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Code or description…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $status,
        'options' => array_merge(['' => 'All statuses'], array_combine($statuses, array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), $statuses))),
    ]) ?>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn-primary">Apply filters</button>
        <a href="<?= e(url('/admin/coupons')) ?>" class="btn-secondary">Reset</a>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Code</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Description</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Discount</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Validity</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Usage</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Updated</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($coupons as $c): ?>
                <?php
                    $valueText = $c['discount_type'] === 'percentage'
                        ? ((float) $c['discount_value']) . '%'
                        : money($c['discount_value']);
                    $validity = 'Always';
                    if ($c['starts_at'] !== null && $c['ends_at'] !== null) {
                        $validity = e(local_datetime($c['starts_at'], 'd M Y')) . ' → ' . e(local_datetime($c['ends_at'], 'd M Y'));
                    } elseif ($c['ends_at'] !== null) {
                        $validity = 'Until ' . e(local_datetime($c['ends_at'], 'd M Y'));
                    } elseif ($c['starts_at'] !== null) {
                        $validity = 'From ' . e(local_datetime($c['starts_at'], 'd M Y'));
                    }
                    $usageLabel = (int) ($c['usage_count'] ?? 0) . ' used';
                    if ($c['usage_limit'] !== null) {
                        $usageLabel .= ' / ' . (int) $c['usage_limit'];
                    }
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><a class="font-mono font-semibold text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/coupons/' . $c['id'])) ?>"><?= e($c['code']) ?></a></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($c['description'] ?? '—') ?></td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900"><?= $valueText ?></div>
                        <div class="text-xs text-slate-400">
                            <?php if ((float) $c['min_subtotal'] > 0): ?>min <?= money($c['min_subtotal']) ?><?php endif; ?>
                            <?php if ($c['max_discount'] !== null): ?> · cap <?= money($c['max_discount']) ?><?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= $validity ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= e($usageLabel) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $c['status']]) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= e(local_datetime($c['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($coupons === []): ?>
        <p class="px-4 py-10 text-center text-sm text-slate-400">No coupons match your filters. <a href="<?= e(url('/admin/coupons/create')) ?>" class="font-medium text-blue-700 hover:text-blue-800">Create one →</a></p>
    <?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/coupons', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
