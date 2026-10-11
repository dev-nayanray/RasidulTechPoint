<?php
/** @var list<array> $methods
 *  @var bool $canManage
 */
$canManage = \App\Services\AuthService::can('settings.manage');
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/payments')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Payments</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Payment methods</h1>
<p class="mt-1 text-sm text-slate-500">Admin-managed catalog of accepted payment methods with gateway classification and verification instructions.</p>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900">
    <strong>Card security rule:</strong> the system MUST NOT store CVV, CVC, security codes, or banking PINs. Any payload containing those keys is rejected by PaymentService::assertNoCardSecurityData() before any DB write.
</div>

<section class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Code</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Label</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Gateway</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Description</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Instructions</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Active</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                <?php if ($canManage): ?><th class="px-4 py-3"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($methods as $m): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= e($m['code']) ?></td>
                    <td class="px-4 py-3 font-medium text-slate-900"><?= e($m['label']) ?></td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $m['gateway']]) ?></td>
                    <td class="px-4 py-3 text-slate-600"><?= e($m['description'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500 max-w-md"><?= e($m['instructions'] ?? '—') ?></td>
                    <td class="px-4 py-3"><?= (int) $m['is_active'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
                    <td class="px-4 py-3 text-slate-500"><?= (int) $m['display_order'] ?></td>
                    <?php if ($canManage): ?>
                    <td class="px-4 py-3 text-right">
                        <a href="<?= e(url('/admin/payments/methods/' . $m['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
