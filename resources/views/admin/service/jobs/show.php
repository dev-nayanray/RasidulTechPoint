<?php
/** @var array $job
 *  @var list<string> $noteTypes
 *  @var list<string> $statuses
 */
$allowed = $job['allowed_transitions'] ?? [];
$isTerminal = in_array($job['status'], ['completed', 'cancelled'], true);
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/service/jobs')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Jobs</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Job <?= e($job['job_number']) ?></h1>
<p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $job['status']]) ?>
    · <?= e(ucfirst($job['service_type'])) ?> · <?= e(ucfirst($job['priority'])) ?> priority
    · received <?= e(local_datetime($job['received_at'], 'd M Y')) ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <!-- Reported issue + diagnosis -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Issue &amp; diagnosis</h2>
            <div class="mt-3 space-y-3">
                <div><dt class="text-slate-500 text-xs">Reported issue</dt><dd class="mt-1 text-slate-700"><?= e($job['reported_issue']) ?></dd></div>
                <?php if (!empty($job['diagnosis'])): ?>
                    <div><dt class="text-slate-500 text-xs">Diagnosis</dt><dd class="mt-1 text-slate-700"><?= e($job['diagnosis']) ?></dd></div>
                <?php endif; ?>
            </div>
            <?php if (!$isTerminal && $job['status'] === 'received'): ?>
            <details class="mt-4 border-t border-slate-100 pt-4">
                <summary class="cursor-pointer font-medium text-blue-700 text-xs">Record diagnosis</summary>
                <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/diagnose')) ?>" class="mt-3 space-y-2" data-loading-form>
                    <?= csrf_field() ?>
                    <textarea name="diagnosis" rows="3" required class="input" placeholder="Motherboard short on capacitor C12; HDD failing SMART test…"></textarea>
                    <button type="submit" class="btn-primary text-xs">Save diagnosis</button>
                </form>
            </details>
            <?php endif; ?>
        </div>

        <!-- Status transition -->
        <?php if (!$isTerminal && $allowed !== []): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Status transition</h2>
            <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/transition')) ?>" class="mt-3 flex flex-wrap items-end gap-2" data-loading-form data-confirm="Change status?">
                <?= csrf_field() ?>
                <select name="status" class="input w-40">
                    <?php foreach ($allowed as $t): ?>
                        <option value="<?= e($t['to']) ?>"><?= e($t['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="reason" class="input flex-1" placeholder="Reason (optional)">
                <button type="submit" class="btn-secondary">Apply</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Quality check -->
        <?php if (in_array($job['status'], ['in_progress', 'quality_check'], true)): ?>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 text-sm">
            <h2 class="font-semibold text-blue-900">Quality check</h2>
            <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/quality-check')) ?>" class="mt-3 space-y-2" data-loading-form>
                <?= csrf_field() ?>
                <textarea name="notes" rows="2" class="input" placeholder="QC notes (optional)"></textarea>
                <div class="flex gap-2">
                    <button type="submit" name="passed" value="1" class="btn-primary">Pass ✓</button>
                    <button type="submit" name="passed" value="0" class="btn-secondary">Fail ✗ (back to repair)</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Close job -->
        <?php if (!$isTerminal && in_array('completed', array_column($allowed, 'to'))): ?>
        <details class="rounded-xl border border-green-200 bg-green-50 p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-green-900">Close job</summary>
            <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/close')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Close this job?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-green-900" for="actual-cost">Actual cost (optional)</label>
                <input id="actual-cost" name="actual_cost" type="number" step="0.01" min="0" class="input" placeholder="<?= e($job['estimated_cost'] ?? '0.00') ?>">
                <label class="block text-xs font-medium text-green-900" for="resolution-notes">Resolution notes (optional)</label>
                <textarea id="resolution-notes" name="resolution_notes" rows="2" class="input" placeholder="Replaced HDD; reinstalled OS; tested 48h burn-in…"></textarea>
                <button type="submit" class="btn-primary">Close job</button>
            </form>
        </details>
        <?php endif; ?>

        <!-- Cancel -->
        <?php if (!$isTerminal && in_array('cancelled', array_column($allowed, 'to'))): ?>
        <details class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-red-900">Cancel job</summary>
            <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/cancel')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Cancel this job?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-red-900" for="cancel-reason">Reason (required)</label>
                <input id="cancel-reason" name="reason" required maxlength="255" class="input" placeholder="Customer cancelled; unit beyond repair…">
                <button type="submit" class="btn-danger">Cancel</button>
            </form>
        </details>
        <?php endif; ?>

        <!-- Parts & charges -->
        <div id="parts" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <header class="border-b border-slate-200 px-5 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Parts &amp; charges</h2>
            </header>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach (($job['parts'] ?? []) as $p): ?>
                    <tr>
                        <td class="px-4 py-2 font-medium text-slate-900"><?= e($p['part_name']) ?></td>
                        <td class="px-4 py-2 text-slate-500 font-mono text-xs"><?= e($p['part_code'] ?? '—') ?></td>
                        <td class="px-4 py-2 text-center text-slate-500"><?= (int) $p['quantity'] ?></td>
                        <td class="px-4 py-2 text-right text-slate-500"><?= money($p['unit_price']) ?></td>
                        <td class="px-4 py-2 text-right font-medium text-slate-900"><?= money($p['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
                <p class="flex justify-between text-slate-500"><span>Labor charge</span><span><?= money($job['labor_charge']) ?></span></p>
                <p class="flex justify-between text-slate-500"><span>Parts charge</span><span><?= money($job['parts_charge']) ?></span></p>
                <p class="flex justify-between text-base font-bold text-slate-900"><span>Total</span><span><?= money($job['total_charge']) ?></span></p>
                <?php if ($job['service_type'] === 'warranty'): ?>
                    <p class="mt-2 text-xs text-green-700">Warranty job — all charges are 0.00; the shop absorbs the cost.</p>
                <?php endif; ?>
            </div>
            <?php if (!$isTerminal): ?>
            <details class="border-t border-slate-100 px-4 py-4">
                <summary class="cursor-pointer text-xs font-medium text-slate-500">Add part</summary>
                <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/parts')) ?>" class="mt-3 grid gap-2 sm:grid-cols-5" data-loading-form>
                    <?= csrf_field() ?>
                    <input name="part_name" placeholder="Part name" required class="input sm:col-span-2">
                    <input name="part_code" placeholder="Code" class="input">
                    <input name="quantity" type="number" min="1" value="1" class="input">
                    <input name="unit_cost" type="number" step="0.01" min="0" placeholder="Cost" class="input">
                    <input name="unit_price" type="number" step="0.01" min="0" placeholder="Price" class="input">
                    <div class="sm:col-span-5"><button type="submit" class="btn-secondary text-xs">Add part</button></div>
                </form>
                <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/labor')) ?>" class="mt-2 flex items-end gap-2" data-loading-form>
                    <?= csrf_field() ?>
                    <label class="text-xs text-slate-500">Labor charge</label>
                    <input name="labor_charge" type="number" step="0.01" min="0" value="<?= e($job['labor_charge']) ?>" class="input w-24">
                    <button type="submit" class="btn-secondary text-xs">Set</button>
                </form>
            </details>
            <?php endif; ?>
        </div>

        <!-- Notes -->
        <div id="notes" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <header class="border-b border-slate-200 px-5 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Notes (<?= count($job['notes'] ?? []) ?>)</h2>
            </header>
            <?php if (($job['notes'] ?? []) !== []): ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($job['notes'] as $n): ?>
                <li class="px-5 py-3 text-sm">
                    <div class="flex items-baseline justify-between text-xs">
                        <span class="font-medium text-slate-700"><?= e(ucfirst(str_replace('_', ' ', $n['note_type']))) ?></span>
                        <span class="text-slate-400"><?= e($n['author_name'] ?? 'Staff') ?> · <?= e(local_datetime($n['created_at'])) ?></span>
                    </div>
                    <p class="mt-1 text-slate-700 whitespace-pre-wrap"><?= e($n['body']) ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="px-5 py-6 text-center text-sm text-slate-400">No notes yet.</p>
            <?php endif; ?>
            <?php if (!$isTerminal): ?>
            <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/notes')) ?>" class="border-t border-slate-100 px-5 py-4 space-y-2" data-loading-form>
                <?= csrf_field() ?>
                <div class="flex gap-2">
                    <select name="note_type" class="input w-40">
                        <?php foreach ($noteTypes as $nt): ?>
                            <option value="<?= e($nt) ?>"><?= e(ucfirst(str_replace('_', ' ', $nt))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="body" rows="2" required class="input flex-1" placeholder="Add a note…"></textarea>
                </div>
                <button type="submit" class="btn-secondary text-xs">Add note</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Customer</h2>
            <dl class="mt-3 space-y-2">
                <?php if (!empty($job['customer_name'])): ?>
                    <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/customers/' . $job['customer_id'])) ?>"><?= e($job['customer_name']) ?></a></dd></div>
                    <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($job['customer_phone']) ?></dd></div>
                <?php else: ?>
                    <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900">Walk-in / Internal</dd></div>
                <?php endif; ?>
            </dl>
        </div>
        <?php if (!empty($job['unit_sku'])): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Unit</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900"><?= e($job['product_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">SKU</dt><dd class="font-mono text-slate-900"><?= e($job['unit_sku']) ?></dd></div>
                <?php if (!empty($job['unit_serial'])): ?><div><dt class="text-slate-500">Serial</dt><dd class="font-mono text-slate-900"><?= e($job['unit_serial']) ?></dd></div><?php endif; ?>
            </dl>
        </div>
        <?php endif; ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Technician</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Assigned to</dt><dd class="font-medium text-slate-900"><?= e($job['assigned_to_name'] ?? '—') ?></dd></div>
            </dl>
            <?php if (!$isTerminal): ?>
            <details class="mt-3 border-t border-slate-100 pt-3">
                <summary class="cursor-pointer text-xs font-medium text-blue-700">Assign technician</summary>
                <form method="post" action="<?= e(url('/admin/service/jobs/' . $job['id'] . '/assign')) ?>" class="mt-2 flex gap-2" data-loading-form>
                    <?= csrf_field() ?>
                    <input name="technician_id" type="number" min="1" placeholder="User ID" class="input flex-1">
                    <button type="submit" class="btn-secondary text-xs">Assign</button>
                </form>
            </details>
            <?php endif; ?>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">History</h2>
            <ol class="mt-3 space-y-2 text-xs">
                <?php foreach (($job['history'] ?? []) as $h): ?>
                <li>
                    <span class="font-medium text-slate-700"><?= e($h['from_status'] === 'new' ? 'Created' : ucfirst($h['from_status'])) ?> → <?= e(ucfirst(str_replace('_', ' ', $h['to_status']))) ?></span>
                    <span class="block text-slate-400"><?= e($h['actor_name'] ?? 'system') ?> · <?= e(local_datetime($h['created_at'])) ?><?php if (!empty($h['reason'])): ?> · <?= e($h['reason']) ?><?php endif; ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </aside>
</div>
