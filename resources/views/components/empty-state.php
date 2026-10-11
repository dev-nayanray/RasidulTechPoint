<?php /** Empty state panel. */ ?>
<div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white/60 px-6 py-12 text-center">
    <?= \App\Support\View::component('icon', ['name' => $icon ?? 'box', 'class' => 'h-10 w-10 text-slate-300']) ?>
    <p class="mt-3 text-sm font-medium text-slate-700"><?= e($title ?? 'Nothing here yet') ?></p>
    <?php if (!empty($description)): ?><p class="mt-1 max-w-sm text-sm text-slate-500"><?= e($description) ?></p><?php endif; ?>
    <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
        <a href="<?= e($actionUrl) ?>" class="btn-primary mt-4"><?= e($actionLabel) ?></a>
    <?php endif; ?>
</div>
