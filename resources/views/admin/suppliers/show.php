<?php
/** @var array $supplier
 *  @var list<array> $units
 */
$isDeleted = !empty($supplier['deleted_at']);
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/suppliers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Suppliers</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($supplier['name']) ?></h1>
        <p class="text-sm text-slate-500">
            <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs"><?= e($supplier['slug']) ?></code>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if (!$isDeleted && \App\Services\AuthService::can('purchases.manage')): ?>
            <a href="<?= e(url('/admin/suppliers/' . $supplier['id'] . '/edit')) ?>" class="btn-secondary">Edit</a>
            <form method="post" action="<?= e(url('/admin/suppliers/' . $supplier['id'] . '/delete')) ?>" data-confirm="Delete this supplier? It will be soft-deleted — history preserved." data-loading-form>
                <?= csrf_field() ?>
                <button type="submit" class="btn-danger">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDeleted): ?>
    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">This supplier was soft-deleted on <?= e(local_datetime($supplier['deleted_at'])) ?>. Historical references are preserved.</p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Contact</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Contact person</dt><dd class="font-medium text-slate-900"><?= e($supplier['contact_name'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($supplier['email'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($supplier['phone'] ?? '—') ?></dd></div>
        </dl>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Address</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($supplier['address'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">City</dt><dd class="font-medium text-slate-900"><?= e($supplier['city'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Country</dt><dd class="font-medium text-slate-900"><?= e($supplier['country'] ?? '—') ?></dd></div>
        </dl>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Summary</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Status</dt><dd><?= \App\Support\View::component('badge', ['status' => $isDeleted ? 'inactive' : ((int) $supplier['is_active'] === 1 ? 'active' : 'inactive')]) ?></dd></div>
            <div><dt class="text-slate-500">Outstanding balance</dt><dd class="font-bold <?= (float) $supplier['outstanding_balance'] > 0 ? 'text-amber-700' : 'text-slate-900' ?>"><?= e(money($supplier['outstanding_balance'])) ?></dd></div>
            <div><dt class="text-slate-500">Units supplied</dt><dd class="font-medium text-slate-900"><?= (int) ($supplier['unit_count'] ?? 0) ?></dd></div>
            <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($supplier['created_at'])) ?></dd></div>
        </dl>
    </div>
</div>

<?php if (!empty($supplier['notes'])): ?>
<div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-sm">
    <h2 class="font-semibold text-slate-900">Notes</h2>
    <p class="mt-2 text-slate-600"><?= e($supplier['notes']) ?></p>
</div>
<?php endif; ?>

<!-- Purchase history (units received from this supplier) -->
<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-slate-900">Inventory units from this supplier (<?= count($units) ?>)</h2>
    </header>
    <?php if ($units === []): ?>
        <p class="px-4 py-8 text-center text-sm text-slate-400">No inventory units reference this supplier.</p>
    <?php else: ?>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Model</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Grade</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Purchase cost</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Selling price</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($units as $unit): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/inventory/' . $unit['id'])) ?>"><?= e($unit['sku']) ?></a></td>
                        <td class="px-4 py-3 text-slate-700"><?= e($unit['product_name']) ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $unit['condition_grade']]) ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $unit['status']]) ?></td>
                        <td class="px-4 py-3 text-right text-slate-500"><?= e(money($unit['purchase_cost'])) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= e(money($unit['selling_price'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
