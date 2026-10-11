<?php
/** @var array $shipment
 *  @var list<array> $couriers
 */
$isDeleted = !empty($shipment['deleted_at']);
$canManage = true; // delivery.manage is enforced at the route level
$allowed = $shipment['allowed_transitions'] ?? [];
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/delivery/shipments')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Shipments</a></p>
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Shipment <?= e($shipment['shipment_number']) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <?= \App\Support\View::component('badge', ['status' => $shipment['status']]) ?>
            · order <a class="text-blue-700 hover:underline" href="<?= e(url('/admin/orders/' . $shipment['order_id'])) ?>"><?= e($shipment['order_reference']) ?></a>
        </p>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Delivery details</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                <div><dt class="text-slate-500">Recipient</dt><dd class="font-medium text-slate-900"><?= e($shipment['recipient_name']) ?></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($shipment['recipient_phone']) ?></dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($shipment['delivery_address']) ?></dd></div>
                <div><dt class="text-slate-500">Fee</dt><dd class="font-bold text-slate-900"><?= money($shipment['fee']) ?></dd></div>
                <div><dt class="text-slate-500">Courier</dt><dd class="font-medium text-slate-900"><?= e($shipment['courier_name'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Tracking reference</dt><dd class="font-mono text-slate-900"><?= e($shipment['tracking_reference'] ?? '—') ?></dd></div>
                <div>
                    <dt class="text-slate-500">Tracking URL</dt>
                    <dd>
                        <?php if (!empty($shipment['tracking_url'])): ?>
                            <a href="<?= e($shipment['tracking_url']) ?>" target="_blank" rel="noopener" class="text-blue-700 hover:underline break-all">Open ↗</a>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                </div>
            </dl>
            <?php if (!empty($shipment['proof_of_delivery'])): ?>
            <div class="mt-3 border-t border-slate-100 pt-3">
                <dt class="text-slate-500">Proof of delivery</dt>
                <dd class="font-mono text-green-700 text-xs"><?= e($shipment['proof_of_delivery']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($shipment['failure_reason'])): ?>
            <div class="mt-3 border-t border-slate-100 pt-3">
                <dt class="text-slate-500">Failure reason</dt>
                <dd class="text-red-700 text-sm"><?= e($shipment['failure_reason']) ?></dd>
            </div>
            <?php endif; ?>
        </div>

        <!-- Dispatch form -->
        <?php if ($shipment['status'] === 'pending'): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Dispatch shipment</h2>
            <form method="post" action="<?= e(url('/admin/delivery/shipments/' . $shipment['id'] . '/dispatch')) ?>" class="mt-3 grid gap-3 sm:grid-cols-2" data-loading-form data-confirm="Dispatch this shipment?">
                <?= csrf_field() ?>
                <div class="space-y-1.5">
                    <label for="dispatch-courier" class="block text-xs font-medium text-slate-500">Courier</label>
                    <select id="dispatch-courier" name="courier_id" required class="input">
                        <option value="">Select a courier…</option>
                        <?php foreach ($couriers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?> · base <?= money($c['base_fee']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= \App\Support\View::component('field', ['name' => 'tracking_reference', 'label' => 'Tracking reference (optional)', 'help' => 'Gateway txn id / consignment number']) ?>
                <?= \App\Support\View::component('field', ['name' => 'tracking_url', 'label' => 'Tracking URL (optional)']) ?>
                <div class="sm:col-span-2"><button type="submit" class="btn-primary">Dispatch</button></div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Deliver form (from dispatched, in_transit, or failed) -->
        <?php if (in_array($shipment['status'], ['dispatched', 'in_transit', 'failed'])): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 p-5 text-sm">
            <h2 class="font-semibold text-green-900">Mark as delivered</h2>
            <form method="post" action="<?= e(url('/admin/delivery/shipments/' . $shipment['id'] . '/deliver')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Mark as delivered?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-green-900" for="proof">Proof of delivery (optional)</label>
                <input id="proof" name="proof_of_delivery" type="text" maxlength="255" class="input" placeholder="Photo URL / signature reference / recipient initials">
                <button type="submit" class="btn-primary">Mark delivered</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Fail form -->
        <?php if (in_array($shipment['status'], ['dispatched', 'in_transit'])): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-sm">
            <h2 class="font-semibold text-red-900">Mark as failed</h2>
            <form method="post" action="<?= e(url('/admin/delivery/shipments/' . $shipment['id'] . '/fail')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Mark this shipment as failed?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-red-900" for="fail-reason">Failure reason (required)</label>
                <textarea id="fail-reason" name="failure_reason" rows="2" required maxlength="255" class="input" placeholder="Customer not available; wrong address; damaged in transit…"></textarea>
                <button type="submit" class="btn-danger">Mark failed</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Return to origin -->
        <?php if (in_array('returned_to_origin', array_map(fn ($t) => $t['to'] ?? '', $allowed))): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm">
            <h2 class="font-semibold text-amber-900">Return to origin</h2>
            <p class="mt-1 text-xs text-amber-800">Marks the shipment as returned to origin. This is a physical-state label — it does NOT automatically return units to stock or refund the customer. That requires a separate refund/cancellation workflow on the order.</p>
            <form method="post" action="<?= e(url('/admin/delivery/shipments/' . $shipment['id'] . '/return')) ?>" class="mt-3 space-y-2" data-loading-form data-confirm="Return this shipment to origin?">
                <?= csrf_field() ?>
                <label class="block text-xs font-medium text-amber-900" for="return-reason">Reason (optional)</label>
                <input id="return-reason" name="reason" type="text" maxlength="255" class="input" placeholder="Customer refused delivery; address not found…">
                <button type="submit" class="btn-secondary">Return to origin</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Cancel -->
        <?php if (in_array('cancelled', array_map(fn ($t) => $t['to'] ?? '', $allowed))): ?>
        <form method="post" action="<?= e(url('/admin/delivery/shipments/' . $shipment['id'] . '/cancel')) ?>" class="mt-4" data-loading-form data-confirm="Cancel this shipment?">
            <?= csrf_field() ?>
            <button type="submit" class="btn-secondary text-xs">Cancel shipment</button>
        </form>
        <?php endif; ?>

        <!-- Transit shortcut -->
        <?php if ($shipment['status'] === 'dispatched'): ?>
        <form method="post" action="<?= e(url('/admin/delivery/shipments/' . $shipment['id'] . '/transit')) ?>" class="mt-2" data-loading-form>
            <?= csrf_field() ?>
            <button type="submit" class="btn-secondary text-xs">Mark in transit</button>
        </form>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Timeline</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div><dt class="text-slate-500">Created</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($shipment['created_at'])) ?></dd></div>
                <div><dt class="text-slate-500">Created by</dt><dd class="font-medium text-slate-900"><?= e($shipment['created_by_name'] ?? '—') ?></dd></div>
                <?php if (!empty($shipment['dispatched_at'])): ?><div><dt class="text-slate-500">Dispatched</dt><dd class="font-medium text-blue-700"><?= e(local_datetime($shipment['dispatched_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($shipment['delivered_at'])): ?><div><dt class="text-slate-500">Delivered</dt><dd class="font-medium text-green-700"><?= e(local_datetime($shipment['delivered_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($shipment['failed_at'])): ?><div><dt class="text-slate-500">Failed</dt><dd class="font-medium text-red-700"><?= e(local_datetime($shipment['failed_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($shipment['returned_at'])): ?><div><dt class="text-slate-500">Returned to origin</dt><dd class="font-medium text-amber-700"><?= e(local_datetime($shipment['returned_at'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($shipment['cancelled_at'])): ?><div><dt class="text-slate-500">Cancelled</dt><dd class="font-medium text-slate-500"><?= e(local_datetime($shipment['cancelled_at'])) ?></dd></div><?php endif; ?>
            </dl>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Allowed transitions</h2>
            <?php if ($allowed === []): ?>
                <p class="mt-2 text-xs text-slate-400">No further transitions allowed from <?= e($shipment['status']) ?>.</p>
            <?php else: ?>
                <ul class="mt-2 space-y-1 text-xs">
                    <?php foreach ($allowed as $t): ?>
                        <li class="font-medium text-slate-700">→ <?= e($t['label']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>
</div>
