<?php
/**
 * Loading skeleton placeholder (UI kit). Variants:
 *   text   — single text line        <caption>
 *   rows   — N table-ish rows        rows=5
 *   cards  — N stat cards grid       cards=4
 * Used while async content loads; forms already disable + spin via forms.js.
 */
$variant = $variant ?? 'text';
$rows = min(20, max(1, (int) ($rows ?? 5)));
$cards = min(8, max(1, (int) ($cards ?? 4)));
?>
<?php if ($variant === 'text'): ?>
    <div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" role="status" aria-label="Loading"></div>
<?php elseif ($variant === 'rows'): ?>
    <div class="space-y-3" role="status" aria-label="Loading rows">
        <?php for ($i = 0; $i < $rows; $i++): ?>
            <div class="flex items-center gap-4">
                <div class="h-9 w-9 shrink-0 animate-pulse rounded-lg bg-slate-200"></div>
                <div class="h-4 flex-1 animate-pulse rounded bg-slate-200" style="max-width: <?= 40 + ($i * 7) % 40 ?>%"></div>
                <div class="hidden h-4 w-20 animate-pulse rounded bg-slate-200 sm:block"></div>
            </div>
        <?php endfor; ?>
    </div>
<?php elseif ($variant === 'cards'): ?>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" role="status" aria-label="Loading cards">
        <?php for ($i = 0; $i < $cards; $i++): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="h-3 w-1/2 animate-pulse rounded bg-slate-200"></div>
                <div class="mt-3 h-7 w-2/3 animate-pulse rounded bg-slate-200"></div>
            </div>
        <?php endfor; ?>
    </div>
<?php endif; ?>
