<?php
/** @var array $customer
 *  @var bool $canManage
 *  @var bool $canSeeFinance
 */
$isDeleted = !empty($customer['deleted_at']);
$statuses = \App\Repositories\CustomerRepository::ACCOUNT_STATUSES;
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/customers')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Customers</a></p>

<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($customer['name']) ?></h1>
        <p class="mt-1 text-sm text-slate-500">
            Account status: <?= \App\Support\View::component('badge', ['status' => $customer['account_status']]) ?>
            <?php if (!empty($customer['last_order_at'])): ?>
                <span class="ml-3">Last order <?= e(local_datetime($customer['last_order_at'], 'd M Y')) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if (!$isDeleted && $canManage): ?>
            <a href="<?= e(url('/admin/customers/' . $customer['id'] . '/edit')) ?>" class="btn-secondary">Edit</a>
            <form method="post" action="<?= e(url('/admin/customers/' . $customer['id'] . '/delete')) ?>" data-confirm="Delete this customer? It will be soft-deleted — history preserved. Customers with orders cannot be deleted." data-loading-form>
                <?= csrf_field() ?>
                <button type="submit" class="btn-danger">Delete</button>
            </form>
        <?php elseif ($isDeleted && $canManage): ?>
            <form method="post" action="<?= e(url('/admin/customers/' . $customer['id'] . '/restore')) ?>" data-loading-form>
                <?= csrf_field() ?>
                <button type="submit" class="btn-primary">Restore</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDeleted): ?>
    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">This customer was soft-deleted on <?= e(local_datetime($customer['deleted_at'])) ?>. Historical references are preserved.</p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Contact</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($customer['phone']) ?></dd></div>
            <?php if (!empty($customer['alt_phone'])): ?><div><dt class="text-slate-500">Alt phone</dt><dd class="font-medium text-slate-900"><?= e($customer['alt_phone']) ?></dd></div><?php endif; ?>
            <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($customer['email'] ?? $customer['account_email'] ?? '—') ?></dd></div>
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($customer['address'] ?? '—') ?><?= !empty($customer['city']) ? ', ' . e($customer['city']) : '' ?></dd></div>
            <div><dt class="text-slate-500">Customer since</dt><dd class="font-medium text-slate-900"><?= e(local_datetime($customer['created_at'])) ?></dd></div>
        </dl>

        <?php if ($canManage && !$isDeleted): ?>
        <form method="post" action="<?= e(url('/admin/customers/' . $customer['id'] . '/status')) ?>" class="mt-4 space-y-2" data-loading-form>
            <?= csrf_field() ?>
            <label for="customer-status" class="block text-xs font-medium text-slate-500">Change account status</label>
            <select id="customer-status" name="account_status" class="input">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $customer['account_status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-secondary w-full">Update status</button>
        </form>
        <?php endif; ?>

        <?php if (\App\Services\AuthService::can('orders.manage')): ?>
            <a href="<?= e(url('/admin/orders/create')) ?>" class="btn-primary mt-4 w-full">New order for this customer</a>
        <?php endif; ?>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Summary</h2>
        <dl class="mt-3 space-y-2">
            <div><dt class="text-slate-500">Total orders</dt><dd class="font-medium text-slate-900"><?= (int) $customer['orders_count'] ?></dd></div>
            <?php if ($canSeeFinance): ?>
                <div><dt class="text-slate-500">Lifetime value</dt><dd class="font-bold text-slate-900"><?= e(money($customer['lifetime_value'])) ?></dd></div>
            <?php else: ?>
                <div><dt class="text-slate-500">Lifetime value</dt><dd class="text-xs text-slate-400">Restricted to finance staff.</dd></div>
            <?php endif; ?>
            <div><dt class="text-slate-500">Last order</dt><dd class="font-medium text-slate-900"><?= !empty($customer['last_order_at']) ? e(local_datetime($customer['last_order_at'])) : '—' ?></dd></div>
        </dl>
    </div>

    <?php if (!empty($customer['notes'])): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">Summary notes</h2>
        <p class="mt-2 text-slate-600"><?= e($customer['notes']) ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Order history -->
<section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-900">Order history (<?= count($customer['orders']) ?>)</h2>
    </header>
    <?php if ($customer['orders'] === []): ?>
        <p class="px-5 py-10 text-center text-sm text-slate-400">No orders for this customer yet.</p>
    <?php else: ?>
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <tbody>
                <?php foreach ($customer['orders'] as $order): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5"><a class="font-medium text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/orders/' . $order['id'])) ?>"><?= e($order['reference']) ?></a></td>
                        <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $order['channel']]) ?></td>
                        <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $order['status']]) ?></td>
                        <td class="px-4 py-2.5"><?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?></td>
                        <td class="px-4 py-2.5 text-slate-500"><?= e(local_datetime($order['created_at'])) ?></td>
                        <?php if ($canSeeFinance): ?>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900"><?= e(money($order['total'])) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- Staff notes (append-only) -->
<section id="notes" class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 px-5 py-4">
        <h2 class="text-sm font-semibold text-slate-900">Staff notes (<?= count($customer['staff_notes']) ?>)</h2>
        <p class="mt-0.5 text-xs text-slate-500">Append-only log — entries cannot be edited or removed.</p>
    </header>

    <?php if ($customer['staff_notes'] === []): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">No staff notes recorded.</p>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($customer['staff_notes'] as $note): ?>
                <li class="px-5 py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                        <span class="font-medium text-slate-700"><?= e($note['author_name'] ?? 'Staff') ?></span>
                        <time class="text-slate-400" datetime="<?= e($note['created_at']) ?>"><?= e(local_datetime($note['created_at'])) ?></time>
                    </div>
                    <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700"><?= e($note['body']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($canManage && !$isDeleted): ?>
        <form method="post" action="<?= e(url('/admin/customers/' . $customer['id'] . '/notes')) ?>" class="border-t border-slate-100 px-5 py-4 space-y-2" data-loading-form>
            <?= csrf_field() ?>
            <label for="note-body" class="block text-xs font-medium text-slate-500">Add a note</label>
            <textarea id="note-body" name="body" rows="2" class="input" required maxlength="2000" placeholder="Customer called about warranty on RTP-…  Offered pickup tomorrow morning."></textarea>
            <button type="submit" class="btn-secondary">Add note</button>
        </form>
    <?php endif; ?>
</section>
