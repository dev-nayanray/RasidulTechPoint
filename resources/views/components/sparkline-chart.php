<?php
/**
 * Lightweight vanilla-JS bar chart for the dashboard sales trend.
 *
 * Renders a row of vertical bars from $data = [{date,total,count}, ...].
 * No external charting library is pulled in — the chart is pure SVG
 * generated client-side from the data attributes, so it works without
 * a network round-trip and degrades to a plain table when JS is off.
 *
 * The chart only renders when $data is non-empty (the dashboard hides
 * it entirely when there are no orders, so no fake numbers are ever
 * shown).
 *
 * @var list<array{date:string,total:float,count:int}> $data
 * @var string $format  'money' to render currency, 'count' to render counts
 */
$data = $data ?? [];
$format = $format ?? 'money';
$chartId = $chartId ?? 'sales-trend-chart';
$height = (int) ($height ?? 160);
?>
<?php if ($data !== []): ?>
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
    <header class="flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-slate-900"><?= e($title ?? 'Sales trend') ?></h2>
            <p class="mt-0.5 text-xs text-slate-500"><?= e($subtitle ?? 'Completed order revenue, last 7 UTC days') ?></p>
        </div>
        <span class="text-xs font-medium text-slate-400" data-chart-total-label></span>
    </header>
    <div class="mt-4">
        <svg id="<?= e($chartId) ?>" class="w-full" style="height: <?= $height ?>px" role="img"
             aria-label="Bar chart of daily completed sales for the last 7 UTC days"
             data-chart data-chart-format="<?= e($format) ?>"
             data-chart-data="<?= e(json_encode($data, JSON_UNESCAPED_SLASHES)) ?>">
            <noscript>
                <!-- No-JS fallback: a plain table of the same data -->
                <foreignObject width="100%" height="100%">
                    <table xmlns="http://www.w3.org/1999/xhtml" class="min-w-full text-xs">
                        <thead><tr><th>Date</th><th>Total</th><th>Orders</th></tr></thead>
                        <tbody>
                            <?php foreach ($data as $row): ?>
                                <tr><td><?= e($row['date'] ?? '') ?></td><td><?= e((string) ($row['total'] ?? 0)) ?></td><td><?= e((string) ($row['count'] ?? 0)) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </foreignObject>
            </noscript>
        </svg>
    </div>
    <div class="mt-2 flex justify-between text-[10px] text-slate-400" data-chart-xaxis>
        <?php foreach ($data as $row): ?>
            <span><?= e(isset($row['date']) ? gmdate('D', strtotime($row['date'])) : '—') ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
