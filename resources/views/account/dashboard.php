<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">My account</h1>
        <p class="mt-1 text-sm text-slate-500">Signed in as <?= e(current_user()['email'] ?? '') ?></p>
    </div>
    <a href="<?= e(url('/account/profile')) ?>" class="btn-secondary">Edit profile</a>
</div>

<div class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Profile</h2>
        <?php if (!empty($customer)): ?>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-slate-500">Name</dt><dd class="font-medium text-slate-900"><?= e($customer['name']) ?></dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($customer['phone']) ?></dd></div>
                <div><dt class="text-slate-500">Address</dt><dd class="font-medium text-slate-900"><?= e($customer['address'] ?? '—') ?><?= !empty($customer['city']) ? ', ' . e($customer['city']) : '' ?></dd></div>
            </dl>
        <?php else: ?>
            <p class="mt-2 text-sm text-slate-500">No customer profile yet — add your details so we can reach you about orders.</p>
            <a href="<?= e(url('/account/profile')) ?>" class="btn-primary mt-3">Add details</a>
        <?php endif; ?>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Recent orders</h2>
        <?php if (($orders ?? []) === []): ?>
            <p class="mt-2 text-sm text-slate-500">You have not placed any orders yet.</p>
            <a href="<?= e(url('/shop')) ?>" class="btn-primary mt-3">Browse laptops</a>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                <?php foreach (array_slice($orders, 0, 5) as $order): ?>
                    <li class="flex items-center justify-between py-2">
                        <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" class="font-medium text-blue-700 hover:text-blue-800"><?= e($order['reference']) ?></a>
                        <span class="text-slate-500"><?= money($order['total']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
