<?php /** Dashboard stat card. */ ?>
<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-500"><?= e($label) ?></p>
            <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $value ?></p>
            <?php if (!empty($hint)): ?><p class="mt-1 text-xs text-slate-400"><?= e($hint) ?></p><?php endif; ?>
        </div>
        <?php if (!empty($icon)): ?>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>
