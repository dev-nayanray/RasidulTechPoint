<?php
/** Pagination: prev/next + numbered window. Expects $page, $pages, $baseUrl (path), $queryParams. */
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$queryParams = $queryParams ?? [];
$baseUrl = $baseUrl ?? '';

$build = function (int $p) use ($baseUrl, $queryParams): string {
    $q = array_merge($queryParams, ['page' => $p]);
    return url($baseUrl . '?' . http_build_query($q));
};

if ($pages <= 1) {
    return;
}

$start = max(1, $page - 2);
$end = min($pages, $page + 2);
?>
<nav class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm" aria-label="Pagination">
    <p class="text-slate-500">Page <span class="font-medium text-slate-700"><?= $page ?></span> of <span class="font-medium text-slate-700"><?= $pages ?></span></p>
    <div class="flex items-center gap-1">
        <?php if ($page > 1): ?>
            <a href="<?= e($build($page - 1)) ?>" class="rounded-lg px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-100" rel="prev">Previous</a>
        <?php else: ?>
            <span class="rounded-lg px-3 py-1.5 text-slate-300 cursor-not-allowed">Previous</span>
        <?php endif; ?>

        <?php if ($start > 1): ?>
            <a href="<?= e($build(1)) ?>" class="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100">1</a>
            <?php if ($start > 2): ?><span class="px-1 text-slate-400">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
            <?php if ($p === $page): ?>
                <span aria-current="page" class="rounded-lg bg-navy-700 px-3 py-1.5 font-semibold text-white"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e($build($p)) ?>" class="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><span class="px-1 text-slate-400">…</span><?php endif; ?>
            <a href="<?= e($build($pages)) ?>" class="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100"><?= $pages ?></a>
        <?php endif; ?>

        <?php if ($page < $pages): ?>
            <a href="<?= e($build($page + 1)) ?>" class="rounded-lg px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-100" rel="next">Next</a>
        <?php else: ?>
            <span class="rounded-lg px-3 py-1.5 text-slate-300 cursor-not-allowed">Next</span>
        <?php endif; ?>
    </div>
</nav>
