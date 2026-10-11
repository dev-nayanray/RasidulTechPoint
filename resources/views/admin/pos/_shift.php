<?php /** @var array|null $shift */ ?>
<div class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
    <h2 class="text-sm font-semibold text-slate-900">Cashier shift</h2>
    <?php if ($shift === null): ?>
        <p class="mt-2 text-xs text-slate-500">No active shift. Open one before recording sales.</p>
        <form method="post" action="<?= e(url('/admin/pos/shift/open')) ?>" class="mt-3 space-y-2" data-loading-form>
            <?= csrf_field() ?>
            <label class="block text-xs font-medium text-slate-500" for="pos-opening-float">Opening float</label>
            <input id="pos-opening-float" type="number" step="0.01" min="0" name="opening_float" value="0" class="input">
            <label class="block text-xs font-medium text-slate-500" for="pos-shift-notes">Notes (optional)</label>
            <input id="pos-shift-notes" type="text" name="notes" class="input" placeholder="Float sourced from safe #…">
            <button type="submit" class="btn-primary w-full">Open shift</button>
        </form>
    <?php else: ?>
        <dl class="mt-3 space-y-1.5 text-xs">
            <div><dt class="text-slate-500">Started</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($shift['started_at'])) ?></dd></div>
            <div><dt class="text-slate-500">Opening float</dt><dd class="font-medium text-slate-900"><?= money($shift['opening_float']) ?></dd></div>
        </dl>
        <form method="post" action="<?= e(url('/admin/pos/shift/close')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Close the shift? You will not be able to record sales until a new one is opened.">
            <?= csrf_field() ?>
            <label class="block text-xs font-medium text-slate-500" for="pos-actual-cash">Counted cash (close)</label>
            <input id="pos-actual-cash" type="number" step="0.01" min="0" name="actual_cash" value="<?= e($shift['opening_float']) ?>" class="input">
            <label class="block text-xs font-medium text-slate-500" for="pos-close-notes">Notes (optional)</label>
            <input id="pos-close-notes" type="text" name="notes" class="input" placeholder="Drop safe, dispenser refill…">
            <button type="submit" class="btn-danger w-full">Close shift &amp; reconcile</button>
        </form>
    <?php endif; ?>
</div>
