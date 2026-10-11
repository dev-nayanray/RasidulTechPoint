<?php /** @var list<array> $heldSales */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/pos')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← POS terminal</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Held sales</h1>
<p class="mt-1 text-sm text-slate-500">Carts parked while serving other customers. Resuming re-validates each unit — items that have since been sold are flagged for removal.</p>

<?php if ($heldSales === []): ?>
    <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-5 py-10 text-center text-sm text-green-700">No held sales right now.</div>
<?php else: ?>
    <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Reference</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Cashier</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Held at</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($heldSales as $held): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-blue-700"><a href="<?= e(url('/admin/pos')) ?>"><?= e($held['reference']) ?></a></td>
                        <td class="px-4 py-3 text-slate-700"><?= e($held['customer_name']) ?><span class="block text-xs text-slate-400"><?= e($held['customer_phone'] ?? '') ?></span></td>
                        <td class="px-4 py-3 text-slate-700"><?= e($held['cashier_name'] ?? '—') ?></td>
                        <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $held['status']]) ?></td>
                        <td class="px-4 py-3 text-slate-500"><?= e(local_datetime($held['held_at'])) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($held['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
