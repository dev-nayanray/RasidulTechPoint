<?php /** @var array $q */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/crm/quotations')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Quotations</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Quotation <?= e($q['quote_number']) ?></h1>
<p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $q['status']]) ?>
    · <?= e($q['customer_name']) ?> · <?= e($q['customer_phone']) ?>
    <?php if (!empty($q['whatsapp_link'])): ?> · <a href="<?= e($q['whatsapp_link']) ?>" target="_blank" rel="noopener" class="text-green-700 hover:underline">WhatsApp ↗</a><?php endif; ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50"><tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Item</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">SKU</th>
                <th class="px-4 py-3 text-center font-medium text-slate-500">Qty</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Unit price</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Line total</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach (($q['items'] ?? []) as $item): ?>
                <tr>
                    <td class="px-4 py-2 font-medium text-slate-900"><?= e($item['name'] ?? $item['product_name'] ?? '—') ?></td>
                    <td class="px-4 py-2 font-mono text-xs text-slate-500"><?= e($item['sku'] ?? '—') ?></td>
                    <td class="px-4 py-2 text-center text-slate-500"><?= (int) ($item['qty'] ?? 1) ?></td>
                    <td class="px-4 py-2 text-right text-slate-700"><?= money($item['unit_price'] ?? '0') ?></td>
                    <td class="px-4 py-2 text-right font-medium text-slate-900"><?= money($item['line_total'] ?? '0') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
            <p class="flex justify-between text-slate-500"><span>Subtotal</span><span><?= money($q['subtotal']) ?></span></p>
            <?php if ((float) $q['discount'] > 0): ?><p class="flex justify-between text-slate-500"><span>Discount</span><span>-<?= money($q['discount']) ?></span></p><?php endif; ?>
            <p class="flex justify-between text-base font-bold text-slate-900"><span>Total</span><span><?= money($q['total']) ?></span></p>
        </div>
        <?php if (!empty($q['notes'])): ?><p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500"><?= e($q['notes']) ?></p><?php endif; ?>
    </div>

    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Actions</h2>
            <div class="mt-3 space-y-2">
                <?php if ($q['status'] === 'draft'): ?>
                <form method="post" action="<?= e(url('/admin/crm/quotations/' . $q['id'] . '/send')) ?>" data-loading-form data-confirm="Mark as sent?">
                    <?= csrf_field() ?><button type="submit" class="btn-primary w-full text-xs">Mark as sent</button>
                </form>
                <?php endif; ?>
                <?php if ($q['status'] === 'sent'): ?>
                <form method="post" action="<?= e(url('/admin/crm/quotations/' . $q['id'] . '/accept')) ?>" data-loading-form>
                    <?= csrf_field() ?><button type="submit" class="btn-primary w-full text-xs">Accept</button>
                </form>
                <form method="post" action="<?= e(url('/admin/crm/quotations/' . $q['id'] . '/reject')) ?>" data-loading-form>
                    <?= csrf_field() ?><button type="submit" class="btn-danger w-full text-xs">Reject</button>
                </form>
                <?php endif; ?>
                <?php if (!empty($q['whatsapp_link'])): ?>
                <a href="<?= e($q['whatsapp_link']) ?>" target="_blank" rel="noopener" class="btn-secondary w-full text-xs">Share via WhatsApp ↗</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Details</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Customer</dt><dd class="font-medium text-slate-900"><?= e($q['customer_name']) ?></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($q['customer_phone']) ?></dd></div>
                <?php if (!empty($q['product_name'])): ?><div><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900"><?= e($q['product_name']) ?></dd></div><?php endif; ?>
                <div><dt class="text-slate-500">Valid until</dt><dd class="font-medium text-slate-900"><?= e($q['valid_until'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($q['created_at'])) ?></dd></div>
                <?php if (!empty($q['sent_at'])): ?><div><dt class="text-slate-500">Sent</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($q['sent_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($q['accepted_at'])): ?><div><dt class="text-slate-500">Accepted</dt><dd class="font-medium text-green-700"><?= e(local_datetime($q['accepted_at'])) ?></dd></div><?php endif; ?>
            </dl>
        </div>
    </aside>
</div>
