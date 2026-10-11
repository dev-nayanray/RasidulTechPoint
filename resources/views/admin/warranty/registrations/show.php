<?php
/** @var array $reg
 *  @var list<string> $claimTypes
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/warranty/registrations')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Registrations</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Warranty <?= e($reg['registration_number']) ?></h1>
<p class="mt-1 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $reg['status']]) ?>
    <?php if ($reg['is_eligible']): ?> · <span class="text-green-700 font-medium">Eligible for claims</span><?php endif; ?>
    · valid <?= e($reg['start_date']) ?> → <?= e($reg['end_date']) ?>
</p>

<?php if ($reg['status'] === 'voided'): ?>
<p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">Voided on <?= e(local_datetime($reg['voided_at'])) ?> by <?= e($reg['voided_by_name'] ?? 'unknown') ?>. Reason: <?= e($reg['void_reason'] ?? '—') ?>.</p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <!-- File a claim -->
        <?php if ($reg['is_eligible']): ?>
        <details class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-slate-900">File a new claim</summary>
            <form method="post" action="<?= e(url('/admin/warranty/claims')) ?>" class="mt-4 space-y-3" data-loading-form>
                <?= csrf_field() ?>
                <input type="hidden" name="warranty_registration_id" value="<?= (int) $reg['id'] ?>">
                <div class="space-y-1.5">
                    <label for="claim-type" class="block text-xs font-medium text-slate-500">Claim type</label>
                    <select id="claim-type" name="claim_type" class="input">
                        <?php foreach ($claimTypes as $t): ?>
                            <option value="<?= e($t) ?>"><?= e(ucfirst($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label for="claim-issue" class="block text-xs font-medium text-slate-500">Customer-reported issue (required)</label>
                    <textarea id="claim-issue" name="customer_reported_issue" rows="3" required class="input" placeholder="Laptop won't boot; screen flickers after 30 min; battery drains fast…"></textarea>
                </div>
                <div class="space-y-1.5">
                    <label for="claim-desc" class="block text-xs font-medium text-slate-500">Internal description (optional)</label>
                    <textarea id="claim-desc" name="description" rows="2" class="input" placeholder="Verified by technician: motherboard short on capacitor C12…"></textarea>
                </div>
                <button type="submit" class="btn-primary">File claim</button>
            </form>
        </details>
        <?php endif; ?>

        <!-- Claims for this registration -->
        <?php if (!empty($reg['claims'])): ?>
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <header class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Claims (<?= count($reg['claims']) ?>)</h2>
            </header>
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <tbody>
                    <?php foreach ($reg['claims'] as $c): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5"><a class="font-mono font-medium text-blue-700 hover:underline" href="<?= e(url('/admin/warranty/claims/' . $c['id'])) ?>"><?= e($c['claim_number']) ?></a></td>
                        <td class="px-4 py-2.5 capitalize"><?= e($c['claim_type']) ?></td>
                        <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $c['status']]) ?></td>
                        <td class="px-4 py-2.5 text-xs text-slate-400"><?= e(local_datetime($c['filed_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <!-- Void registration -->
        <?php if ($reg['status'] === 'active'): ?>
        <details class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-red-900">Void this warranty</summary>
            <form method="post" action="<?= e(url('/admin/warranty/registrations/' . $reg['id'] . '/void')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Void this warranty? No further claims can be filed.">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-red-900" for="void-reason">Void reason (required)</label>
                <input id="void-reason" name="reason" required maxlength="255" class="input" placeholder="Registered against the wrong unit; customer voided their purchase…">
                <button type="submit" class="btn-danger">Void warranty</button>
            </form>
        </details>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Product</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900"><?= e($reg['product_name']) ?></dd></div>
                <div><dt class="text-slate-500">SKU</dt><dd class="font-mono text-slate-900"><?= e($reg['unit_sku']) ?></dd></div>
                <div><dt class="text-slate-500">Serial</dt><dd class="font-mono text-slate-900"><?= e($reg['unit_serial'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Grade</dt><dd><?= \App\Support\View::component('badge', ['status' => $reg['unit_grade']]) ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Sale</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Order</dt><dd class="font-medium text-slate-900"><a class="hover:text-blue-700" href="<?= e(url('/admin/orders/' . $reg['order_id'])) ?>"><?= e($reg['order_reference']) ?></a></dd></div>
                <div><dt class="text-slate-500">Customer</dt><dd class="font-medium text-slate-900"><?= e($reg['customer_name']) ?></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($reg['customer_phone']) ?></dd></div>
                <div><dt class="text-slate-500">Sold at</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($reg['unit_sold_at'], 'd M Y')) ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Policy</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Policy</dt><dd class="font-medium text-slate-900"><?= e($reg['policy_name'] ?? 'Business default') ?></dd></div>
                <div><dt class="text-slate-500">Duration</dt><dd class="font-medium text-slate-900"><?= (int) ($reg['policy_duration'] ?? 0) ?> days</dd></div>
                <div><dt class="text-slate-500">Coverage</dt><dd class="capitalize text-slate-900"><?= e(str_replace('_', ' ', $reg['policy_coverage'] ?? 'repair')) ?></dd></div>
                <div><dt class="text-slate-500">Registered by</dt><dd class="text-slate-900"><?= e($reg['registered_by_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Registered at</dt><dd class="text-slate-900"><?= e(local_datetime($reg['created_at'])) ?></dd></div>
            </dl>
        </div>
    </aside>
</div>
