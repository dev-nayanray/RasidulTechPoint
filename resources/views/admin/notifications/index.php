<?php /** @var list<array> $notifications */ ?>
<div class="flex items-center justify-between">
    <p class="text-sm text-slate-500">Your in-app notifications.</p>
    <form method="post" action="<?= e(url('/admin/notifications/read-all')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn-secondary">Mark all read</button>
    </form>
</div>

<div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <ul class="divide-y divide-slate-100">
        <?php foreach ($notifications as $notification): ?>
            <li class="flex items-start gap-3 px-4 py-3 text-sm <?= (int) $notification['is_read'] === 0 ? 'bg-blue-50/50' : '' ?>">
                <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full <?= (int) $notification['is_read'] === 0 ? 'bg-blue-600' : 'bg-slate-200' ?>" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="font-medium <?= (int) $notification['is_read'] === 0 ? 'text-slate-900' : 'text-slate-500' ?>"><?= e($notification['title']) ?></p>
                    <?php if (!empty($notification['body'])): ?><p class="mt-0.5 text-slate-500"><?= e($notification['body']) ?></p><?php endif; ?>
                    <p class="mt-1 text-xs text-slate-400"><?= e(local_datetime($notification['created_at'])) ?></p>
                </div>
                <?php if (!empty($notification['link'])): ?>
                    <a href="<?= e(url($notification['link'])) ?>" class="shrink-0 font-medium text-blue-700 hover:text-blue-800">Open</a>
                <?php elseif ((int) $notification['is_read'] === 0): ?>
                    <form method="post" action="<?= e(url('/admin/notifications/' . $notification['id'] . '/read')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="shrink-0 font-medium text-slate-500 hover:text-slate-700">Mark read</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($notifications === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No notifications.</p><?php endif; ?>
</div>
