<?php
/** @var array $claim */
$allowed = $claim['allowed_transitions'] ?? [];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/warranty/claims')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Claims</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Claim <?= e($claim['claim_number']) ?></h1>
<p class="mt-1 text-sm text-slate-500">
    <?= \App\Support\View::component('badge', ['status' => $claim['status']]) ?>
    · <?= e(ucfirst($claim['claim_type'])) ?> claim
    · filed <?= e(local_datetime($claim['filed_at'])) ?>
</p>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Claim details</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Customer-reported issue</dt><dd class="mt-1 text-slate-700"><?= e($claim['customer_reported_issue']) ?></dd></div>
                <?php if (!empty($claim['description'])): ?><div><dt class="text-slate-500">Internal description</dt><dd class="mt-1 text-slate-700"><?= e($claim['description']) ?></dd></div><?php endif; ?>
                <?php if (!empty($claim['decision_reason'])): ?><div><dt class="text-slate-500">Decision reason</dt><dd class="mt-1 text-slate-700"><?= e($claim['decision_reason']) ?></dd></div><?php endif; ?>
                <?php if (!empty($claim['resolution_notes'])): ?><div><dt class="text-slate-500">Resolution notes</dt><dd class="mt-1 text-slate-700"><?= e($claim['resolution_notes']) ?></dd></div><?php endif; ?>
            </dl>
        </div>

        <!-- Decision forms -->
        <?php if (in_array('approved', array_column($allowed, 'to'))): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 p-5 text-sm">
            <h2 class="font-semibold text-green-900">Approve claim</h2>
            <form method="post" action="<?= e(url('/admin/warranty/claims/' . $claim['id'] . '/approve')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Approve this claim?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-green-900" for="approve-reason">Reason (optional)</label>
                <input id="approve-reason" name="reason" maxlength="255" class="input" placeholder="Within warranty period; issue covered by policy…">
                <button type="submit" class="btn-primary">Approve</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (in_array('rejected', array_column($allowed, 'to'))): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
            <h2 class="font-semibold text-red-900">Reject claim</h2>
            <form method="post" action="<?= e(url('/admin/warranty/claims/' . $claim['id'] . '/reject')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Reject this claim?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-red-900" for="reject-reason">Reason (required)</label>
                <textarea id="reject-reason" name="reason" rows="2" required maxlength="255" class="input" placeholder="Warranty expired; issue not covered; customer damage…"></textarea>
                <button type="submit" class="btn-danger">Reject</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (in_array('in_progress', array_column($allowed, 'to'))): ?>
        <form method="post" action="<?= e(url('/admin/warranty/claims/' . $claim['id'] . '/start')) ?>" class="inline" data-loading-form>
            <?= csrf_field() ?>
            <button type="submit" class="btn-secondary">Start repair</button>
        </form>
        <?php endif; ?>

        <?php if (in_array('completed', array_column($allowed, 'to'))): ?>
        <details class="rounded-xl border border-green-200 bg-green-50 p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-green-900">Complete claim</summary>
            <form method="post" action="<?= e(url('/admin/warranty/claims/' . $claim['id'] . '/complete')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Mark this claim as completed?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-green-900" for="resolution-notes">Resolution notes (optional)</label>
                <textarea id="resolution-notes" name="resolution_notes" rows="2" maxlength="2000" class="input" placeholder="Replaced HDD; reinstalled OS; tested 48h burn-in…"></textarea>
                <button type="submit" class="btn-primary">Complete</button>
            </form>
        </details>
        <?php endif; ?>

        <?php if (in_array('cancelled', array_column($allowed, 'to'))): ?>
        <form method="post" action="<?= e(url('/admin/warranty/claims/' . $claim['id'] . '/cancel')) ?>" class="inline" data-loading-form data-confirm="Cancel this claim?">
            <?= csrf_field() ?>
            <input type="text" name="reason" maxlength="255" class="input" placeholder="Reason (optional)">
            <button type="submit" class="btn-secondary text-xs">Cancel claim</button>
        </form>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Product</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900"><?= e($claim['product_name']) ?></dd></div>
                <div><dt class="text-slate-500">SKU</dt><dd class="font-mono text-slate-900"><?= e($claim['unit_sku']) ?></dd></div>
                <div><dt class="text-slate-500">Serial</dt><dd class="font-mono text-slate-900"><?= e($claim['unit_serial'] ?? '—') ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Warranty</h2>
            <dl class="mt-3 space-y-2">
                <div><dt class="text-slate-500">Registration</dt><dd class="font-mono text-blue-700"><a class="hover:underline" href="<?= e(url('/admin/warranty/registrations/' . $claim['warranty_registration_id'])) ?>"><?= e($claim['registration_number']) ?></a></dd></div>
                <div><dt class="text-slate-500">Start date</dt><dd class="font-medium text-slate-900"><?= e($claim['start_date']) ?></dd></div>
                <div><dt class="text-slate-500">End date</dt><dd class="font-medium text-slate-900"><?= e($claim['end_date']) ?></dd></div>
                <div><dt class="text-slate-500">Registration status</dt><dd><?= \App\Support\View::component('badge', ['status' => $claim['registration_status']]) ?></dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Status history</h2>
            <?php if (($claim['history'] ?? []) === []): ?>
                <p class="mt-2 text-xs text-slate-400">No history recorded.</p>
            <?php else: ?>
            <ol class="mt-3 space-y-2 text-xs">
                <?php foreach ($claim['history'] as $h): ?>
                <li>
                    <span class="font-medium text-slate-700"><?= e($h['from_status'] === 'new' ? 'Created' : ucfirst($h['from_status'])) ?> → <?= e(ucfirst($h['to_status'])) ?></span>
                    <span class="block text-slate-400">by <?= e($h['actor_name'] ?? 'system') ?> · <?= e(local_datetime($h['created_at'])) ?><?php if (!empty($h['reason'])): ?> · <?= e($h['reason']) ?><?php endif; ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
        </div>
    </aside>
</div>
