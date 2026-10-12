<?php
/** @var array $rr
 *  @var list<string> $inspectionResults
 *  @var list<string> $dispositions
 */
$allowed = $rr['allowed_transitions'] ?? [];
$isTerminal = in_array($rr['status'], ['completed', 'rejected', 'cancelled'], true);
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/returns')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Returns</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Return <?= e($rr['return_number']) ?></h1>
<p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $rr['status']]) ?>
    · <?= e(ucfirst(str_replace('_', ' ', $rr['request_type']))) ?>
    · order <a class="text-blue-700 hover:underline" href="<?= e(url('/admin/orders/' . $rr['order_id'])) ?>"><?= e($rr['order_reference']) ?></a>
</p>

<?php if ($rr['status'] === 'rejected'): ?>
<p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">Rejected. Reason: <?= e($rr['rejection_reason'] ?? '—') ?>.</p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <!-- Items + inspection -->
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <header class="border-b border-slate-200 px-5 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Returned items (<?= count($rr['items'] ?? []) ?>)</h2>
            </header>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50"><tr>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Product</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">SKU</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Unit status</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Inspection</th>
                    <th class="px-4 py-2 text-left font-medium text-slate-500">Disposition</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach (($rr['items'] ?? []) as $item): ?>
                    <tr>
                        <td class="px-4 py-2 font-medium text-slate-900"><?= e($item['product_name']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-slate-500"><?= e($item['sku']) ?></td>
                        <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $item['unit_status']]) ?></td>
                        <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $item['inspection_result']]) ?></td>
                        <td class="px-4 py-2 capitalize text-xs"><?= e(str_replace('_', ' ', $item['inventory_disposition'] ?? '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Inspection form (when status='received') -->
        <?php if ($rr['status'] === 'received'): ?>
        <details class="rounded-xl border border-slate-200 bg-white p-5 text-sm" open>
            <summary class="cursor-pointer font-semibold text-slate-900">Inspect items</summary>
            <p class="mt-1 text-xs text-slate-500">Set inspection result + disposition per item. Units marked 'pass' + 'return_to_stock' will become sellable after completion. Units marked 'fail' will be moved to 'damaged'.</p>
            <form method="post" action="<?= e(url('/admin/returns/' . $rr['id'] . '/inspect')) ?>" class="mt-4 space-y-4" data-loading-form>
                <?= csrf_field() ?>
                <?php foreach (($rr['items'] ?? []) as $item): ?>
                <div class="rounded-lg border border-slate-100 p-3 space-y-2">
                    <p class="font-medium text-slate-900"><?= e($item['product_name']) ?> <span class="font-mono text-xs text-slate-400"><?= e($item['sku']) ?></span></p>
                    <div class="flex flex-wrap items-end gap-2">
                        <input type="hidden" name="items[<?= (int) $item['id'] ?>][inspection_result]" value="pending">
                        <div class="space-y-1">
                            <label class="text-xs text-slate-500">Inspection</label>
                            <select name="items[<?= (int) $item['id'] ?>][inspection_result]" class="input">
                                <?php foreach ($inspectionResults as $ir): ?>
                                    <option value="<?= e($ir) ?>"><?= e(ucfirst($ir)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs text-slate-500">Disposition</label>
                            <select name="items[<?= (int) $item['id'] ?>][inventory_disposition]" class="input">
                                <option value="">— Not set —</option>
                                <?php foreach ($dispositions as $d): ?>
                                    <option value="<?= e($d) ?>"><?= e(ucfirst(str_replace('_', ' ', $d))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input name="items[<?= (int) $item['id'] ?>][inspection_notes]" class="input flex-1" placeholder="Inspection notes (optional)">
                    </div>
                </div>
                <?php endforeach; ?>
                <textarea name="global_notes" rows="2" class="input" placeholder="Global inspection notes (optional)"></textarea>
                <button type="submit" class="btn-primary">Record inspection</button>
            </form>
        </details>
        <?php endif; ?>

        <!-- Action buttons -->
        <?php if (!$isTerminal): ?>
        <div class="flex flex-wrap gap-2">
            <?php if (in_array('approved', array_column($allowed, 'to'))): ?>
            <form method="post" action="<?= e(url('/admin/returns/' . $rr['id'] . '/approve')) ?>" data-loading-form data-confirm="Approve this return?">
                <?= csrf_field() ?><button type="submit" class="btn-primary">Approve</button>
            </form>
            <?php endif; ?>
            <?php if (in_array('rejected', array_column($allowed, 'to'))): ?>
            <details><summary class="btn-danger cursor-pointer">Reject…</summary>
                <form method="post" action="<?= e(url('/admin/returns/' . $rr['id'] . '/reject')) ?>" class="mt-2 flex gap-2" data-loading-form>
                    <?= csrf_field() ?>
                    <input name="reason" required maxlength="255" class="input" placeholder="Rejection reason">
                    <button type="submit" class="btn-danger">Reject</button>
                </form>
            </details>
            <?php endif; ?>
            <?php if (in_array('received', array_column($allowed, 'to'))): ?>
            <form method="post" action="<?= e(url('/admin/returns/' . $rr['id'] . '/receive')) ?>" data-loading-form data-confirm="Mark items as received? Units will be marked as 'returned' (NOT sellable until inspection).">
                <?= csrf_field() ?><button type="submit" class="btn-secondary">Receive items</button>
            </form>
            <?php endif; ?>
            <?php if (in_array('completed', array_column($allowed, 'to'))): ?>
            <form method="post" action="<?= e(url('/admin/returns/' . $rr['id'] . '/complete')) ?>" data-loading-form data-confirm="Complete this return? Refunds will be processed and inventory will be dispositioned per inspection results.">
                <?= csrf_field() ?><button type="submit" class="btn-primary">Complete return</button>
            </form>
            <?php endif; ?>
            <?php if (in_array('cancelled', array_column($allowed, 'to'))): ?>
            <form method="post" action="<?= e(url('/admin/returns/' . $rr['id'] . '/cancel')) ?>" data-loading-form data-confirm="Cancel this return?">
                <?= csrf_field() ?><button type="submit" class="btn-secondary text-xs">Cancel</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Customer</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900"><?= e($rr['customer_name']) ?></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($rr['customer_phone']) ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Reason</h2>
            <p class="mt-2 text-slate-700"><?= e($rr['reason']) ?></p>
            <?php if (!empty($rr['customer_reported'])): ?><p class="mt-2 text-xs text-slate-500">Customer reported: <?= e($rr['customer_reported']) ?></p><?php endif; ?>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Timeline</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Requested</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($rr['created_at'])) ?></dd></div>
                <?php if (!empty($rr['approved_at'])): ?><div><dt class="text-slate-500">Approved</dt><dd class="font-medium text-blue-700"><?= e(local_datetime($rr['approved_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($rr['received_at'])): ?><div><dt class="text-slate-500">Received</dt><dd class="font-medium text-amber-700"><?= e(local_datetime($rr['received_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($rr['inspected_at'])): ?><div><dt class="text-slate-500">Inspected</dt><dd class="font-medium text-purple-700"><?= e(local_datetime($rr['inspected_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($rr['completed_at'])): ?><div><dt class="text-slate-500">Completed</dt><dd class="font-medium text-green-700"><?= e(local_datetime($rr['completed_at'])) ?></dd></div><?php endif; ?>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">History</h2>
            <ol class="mt-3 space-y-2 text-xs">
                <?php foreach (($rr['history'] ?? []) as $h): ?>
                <li><span class="font-medium text-slate-700"><?= e($h['from_status'] === 'new' ? 'Created' : ucfirst($h['from_status'])) ?> → <?= e(ucfirst($h['to_status'])) ?></span><span class="block text-slate-400"><?= e($h['actor_name'] ?? 'system') ?> · <?= e(local_datetime($h['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </aside>
</div>
