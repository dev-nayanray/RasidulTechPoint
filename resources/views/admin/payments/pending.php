<?php /** @var list<array> $pending @var bool $canManage */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/payments')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Payments</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Payment verification queue</h1>
<p class="mt-1 text-sm text-slate-500">Non-cash payments awaiting manual verification. Open the gateway / bank portal and confirm the reference matches a real credit before marking completed.</p>

<?php if ($pending === []): ?>
    <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-5 py-10 text-center text-sm text-green-700">No payments awaiting verification.</div>
<?php else: ?>
    <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">ID</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Order</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Customer</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Method</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Reference</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Recorded</th>
                    <th class="px-4 py-3 text-right font-medium text-slate-500">Amount</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($pending as $p): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3"><a class="font-medium text-blue-700" href="<?= e(url('/admin/payments/' . $p['id'])) ?>">#<?= (int) $p['id'] ?></a></td>
                        <td class="px-4 py-3"><a class="text-blue-700 hover:underline" href="<?= e(url('/admin/orders/' . $p['order_id'])) ?>"><?= e($p['order_reference']) ?></a></td>
                        <td class="px-4 py-3 text-slate-700"><?= e($p['customer_name']) ?></td>
                        <td class="px-4 py-3 capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500"><?= e($p['reference'] ?? '—') ?></td>
                        <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($p['created_at'])) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= money($p['amount']) ?></td>
                        <td class="px-4 py-3 text-right">
                            <?php if ($canManage): ?>
                                <a href="<?= e(url('/admin/payments/' . $p['id'])) ?>" class="btn-secondary text-xs">Verify →</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
