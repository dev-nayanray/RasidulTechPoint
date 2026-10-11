<?php
/**
 * Breadcrumbs: list of [label, url] pairs; null url = current page.
 * Pass ['items' => [...]] directly; layout falls back to auto-derivation.
 */
$items = $items ?? admin_breadcrumbs($pageTitle ?? null);
?>
<?php if (count($items) > 1): ?>
    <nav class="mb-4 text-sm" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-slate-500">
            <?php foreach ($items as $i => [$label, $href]): ?>
                <li class="flex items-center gap-2">
                    <?php if ($i > 0): ?>
                        <span class="text-slate-300 select-none" aria-hidden="true">/</span>
                    <?php endif; ?>
                    <?php $isLast = $i === count($items) - 1; ?>
                    <?php if ($isLast): ?>
                        <span class="font-medium text-slate-800" aria-current="page"><?= e($label) ?></span>
                    <?php elseif ($href !== null): ?>
                        <a href="<?= e(url($href)) ?>" class="hover:text-blue-700"><?= e($label) ?></a>
                    <?php else: ?>
                        <span class="hidden sm:inline"><?= e($label) ?></span>
                        <span class="sm:hidden"><?= e(rtrim(mb_substr($label, 0, 12))) ?>…</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
<?php endif; ?>
