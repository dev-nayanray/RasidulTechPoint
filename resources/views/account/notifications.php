<?php /** @var list<array> $notifications */
?>
<div class="flex items-center justify-between">
    <h1 class="text-xl font-bold tracking-tight text-navy-700">Notifications</h1>
    <?php if ($notifications !== []): ?>
    <form method="post" action="<?= e(url('/account/notifications/read-all')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="text-sm font-semibold text-brand-blue hover:underline">Mark all read</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($notifications === []): ?>
    <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
            <?= \App\Support\View::component('icon', ['name' => 'bell', 'class' => 'h-6 w-6']) ?>
        </span>
        <p class="mt-4 font-semibold text-navy-700">No notifications</p>
        <p class="mt-1 text-sm text-slate-500">You'll see updates about your orders, warranty, and service jobs here.</p>
    </div>
<?php else: ?>
    <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <ul class="divide-y divide-slate-100">
            <?php foreach ($notifications as $n): ?>
                <li class="flex items-start gap-3 px-4 py-3 text-sm <?= (int) $n['is_read'] === 0 ? 'bg-blue-50/50' : '' ?>">
                    <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full <?= (int) $n['is_read'] === 0 ? 'bg-brand-blue' : 'bg-slate-200' ?>" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium <?= (int) $n['is_read'] === 0 ? 'text-navy-700' : 'text-slate-500' ?>"><?= e($n['title']) ?></p>
                        <?php if (!empty($n['body'])): ?><p class="mt-0.5 text-slate-500 text-xs"><?= e($n['body']) ?></p><?php endif; ?>
                        <p class="mt-1 text-xs text-slate-400"><?= e(local_datetime($n['created_at'])) ?></p>
                    </div>
                    <div class="shrink-0 flex gap-2">
                        <?php if (!empty($n['link'])): ?>
                            <a href="<?= e(url($n['link'])) ?>" class="font-medium text-brand-blue hover:text-blue-700 text-xs">Open</a>
                        <?php endif; ?>
                        <?php if ((int) $n['is_read'] === 0): ?>
                            <form method="post" action="<?= e(url('/account/notifications/' . $n['id'] . '/read')) ?>" class="inline">
                                <?= csrf_field() ?><button type="submit" class="font-medium text-slate-500 hover:text-slate-700 text-xs">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
