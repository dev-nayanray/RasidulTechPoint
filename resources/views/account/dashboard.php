<?php /** @var array $customer
 *  @var list<array> $recentOrders
 *  @var array $orderStats
 *  @var int $warrantyCount
 *  @var int $serviceCount
 *  @var int $unreadNotifications
 *  @var int $wishlistCount
 */
?>
<h1 class="text-2xl font-bold tracking-tight text-navy-700">Hello, <?= e(explode(' ', $customer['name'])[0]) ?>!</h1>
<p class="mt-1 text-sm text-slate-500">Here's a summary of your account activity at Rasidul Tech Point.</p>

<?php if ($orderStats['total'] === 0 && $warrantyCount === 0 && $serviceCount === 0): ?>
    <?php /* New customer empty state */ ?>
    <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-brand-blue">
            <?= \App\Support\View::component('icon', ['name' => 'box', 'class' => 'h-6 w-6']) ?>
        </span>
        <p class="mt-4 font-semibold text-navy-700">Welcome to Rasidul Tech Point!</p>
        <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">You haven't placed any orders yet. Browse our Dubai-imported, quality-checked laptops to get started.</p>
        <a href="<?= e(url('/shop')) ?>" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">Browse Laptops</a>
    </div>
<?php else: ?>

<?php /* Stats cards */ ?>
<div class="mt-6 grid gap-3 grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
        <p class="text-xs uppercase tracking-wider text-slate-400">Total Orders</p>
        <p class="mt-1 text-2xl font-bold text-navy-700"><?= (int) $orderStats['total'] ?></p>
    </div>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
        <p class="text-xs uppercase tracking-wider text-slate-400">Pending / Processing</p>
        <p class="mt-1 text-2xl font-bold text-amber-600"><?= (int) ($orderStats['pending'] + $orderStats['confirmed'] + $orderStats['processing']) ?></p>
    </div>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
        <p class="text-xs uppercase tracking-wider text-slate-400">Warranty Active</p>
        <p class="mt-1 text-2xl font-bold text-brand-green"><?= (int) $warrantyCount ?></p>
    </div>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
        <p class="text-xs uppercase tracking-wider text-slate-400">Service Jobs</p>
        <p class="mt-1 text-2xl font-bold text-brand-blue"><?= (int) $serviceCount ?></p>
    </div>
</div>

<?php /* Quick actions */ ?>
<div class="mt-4 flex flex-wrap gap-2">
    <a href="<?= e(url('/shop')) ?>" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 transition">
        <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-4 w-4']) ?> Shop Laptops
    </a>
    <a href="<?= e(url('/account/orders')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50 transition">
        <?= \App\Support\View::component('icon', ['name' => 'clipboard', 'class' => 'h-4 w-4']) ?> View Orders
    </a>
    <a href="<?= e(url('/account/wishlist')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50 transition">
        <?= \App\Support\View::component('icon', ['name' => 'heart', 'class' => 'h-4 w-4']) ?> Wishlist (<?= (int) $wishlistCount ?>)
    </a>
    <a href="<?= e(url('/account/support')) ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-700 hover:bg-slate-50 transition">
        <?= \App\Support\View::component('icon', ['name' => 'megaphone', 'class' => 'h-4 w-4']) ?> Support
    </a>
</div>

<?php /* Recent orders */ ?>
<div class="mt-6">
    <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-navy-700">Recent orders</h2>
        <a href="<?= e(url('/account/orders')) ?>" class="text-sm font-semibold text-brand-blue hover:underline">View all →</a>
    </div>
    <?php if ($recentOrders === []): ?>
        <p class="mt-3 text-sm text-slate-500">You haven't placed any orders yet.</p>
    <?php else: ?>
        <div class="mt-3 space-y-2">
            <?php foreach ($recentOrders as $order): ?>
                <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" class="block rounded-xl bg-white ring-1 ring-slate-200 p-4 hover:ring-brand-blue transition">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-mono text-sm font-semibold text-navy-700"><?= e($order['reference']) ?></p>
                            <p class="text-xs text-slate-500"><?= e(local_datetime($order['created_at'])) ?></p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-navy-700"><?= money($order['total']) ?></p>
                            <div class="mt-1 flex gap-1.5 justify-end">
                                <?= \App\Support\View::component('badge', ['status' => $order['status']]) ?>
                                <?= \App\Support\View::component('badge', ['status' => $order['payment_status']]) ?>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
