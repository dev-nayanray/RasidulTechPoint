<?php
/** @var int $active $expired $voided $pendingClaims
 *  @var list<array> $recentClaims
 *  @var list<array> $policies
 */
?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?= \App\Support\View::component('stat-card', ['label' => 'Active warranties', 'value' => $active, 'icon' => 'shield']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Expired', 'value' => $expired, 'icon' => 'clock']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Voided', 'value' => $voided, 'icon' => 'x-circle']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Open claims', 'value' => $pendingClaims, 'icon' => 'alert-circle']) ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-[2fr_1fr]">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent claims</h2>
            <a href="<?= e(url('/admin/warranty/claims')) ?>" class="text-xs font-medium text-blue-700 hover:underline">View all →</a>
        </header>
        <?php if ($recentClaims === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No warranty claims yet.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Claim #</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Customer</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Type</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Filed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recentClaims as $c): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-blue-700"><a href="<?= e(url('/admin/warranty/claims/' . $c['id'])) ?>"><?= e($c['claim_number']) ?></a></td>
                    <td class="px-4 py-2 text-slate-700"><?= e($c['customer_name']) ?></td>
                    <td class="px-4 py-2 capitalize"><?= e($c['claim_type']) ?></td>
                    <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $c['status']]) ?></td>
                    <td class="px-4 py-2 text-xs text-slate-400"><?= e(local_datetime($c['filed_at'], 'd M Y')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Warranty policies</h2>
            <?php if ($policies === []): ?>
                <p class="mt-2 text-xs text-slate-400">No active policies. <a class="text-blue-700 hover:underline" href="<?= e(url('/admin/warranty/policies/create')) ?>">Create one →</a></p>
            <?php else: ?>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                <?php foreach ($policies as $p): ?>
                <li class="py-2">
                    <a class="font-medium text-blue-700 hover:underline" href="<?= e(url('/admin/warranty/policies/' . $p['id'] . '/edit')) ?>"><?= e($p['name']) ?></a>
                    <p class="text-xs text-slate-500"><?= (int) $p['duration_days'] ?> days · <?= e(ucfirst(str_replace('_', ' ', $p['coverage_type']))) ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="mt-3 flex gap-2">
                <a href="<?= e(url('/admin/warranty/policies')) ?>" class="btn-secondary text-xs">All policies</a>
                <a href="<?= e(url('/admin/warranty/registrations')) ?>" class="btn-secondary text-xs">Registrations</a>
            </div>
        </div>
    </aside>
</div>
