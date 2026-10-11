<?php /** @var list<array> $pairs */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/customers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Customers</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Duplicate contacts</h1>
<p class="mt-1 text-sm text-slate-500">Customers that share a phone number or email with another customer. Soft-deleted customers are excluded.</p>

<?php if ($pairs === []): ?>
    <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-5 py-10 text-center text-sm text-green-700">
        No duplicate contacts detected. Phone numbers and emails are unique at the database level.
    </div>
<?php else: ?>
    <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Match on</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Customer A</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Customer B</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Shared value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($pairs as $pair): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"><?= e($pair['match_on']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/customers/' . $pair['a_id'])) ?>"><?= e($pair['a_name']) ?></a>
                            <span class="block text-xs text-slate-400">#<?= (int) $pair['a_id'] ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/customers/' . $pair['b_id'])) ?>"><?= e($pair['b_name']) ?></a>
                            <span class="block text-xs text-slate-400">#<?= (int) $pair['b_id'] ?></span>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            <?php if ($pair['match_on'] === 'phone'): ?>
                                <?= e($pair['phone']) ?>
                            <?php else: ?>
                                <?= e($pair['email']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
