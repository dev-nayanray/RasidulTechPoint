<?php
/**
 * Dashboard stat card — premium semantic variant with hover lift,
 * gradient top accent, and optional animated count-up.
 *
 * @var string $label
 * @var string $value           displayed verbatim if $animateValue is false; otherwise the raw number is parsed for count-up
 * @var string $hint            (optional) small subtitle
 * @var string $icon            (optional) components/icon.php name
 * @var string $tone             (optional: blue|green|amber|red|navy|slate) — defaults to blue
 * @var string $href             (optional, makes the whole card clickable)
 * @var string $trend            (optional: 'up'|'down' — shows a trend arrow badge)
 * @var string $trendLabel       (optional: text inside the trend badge, e.g. "+12% vs last week")
 * @var bool   $animate          (optional, default true) — animated count-up on first paint
 */
$tone = $tone ?? 'blue';
$href = $href ?? null;
$trend = $trend ?? null;
$trendLabel = $trendLabel ?? null;
$animate = $animate ?? true;
$tag = $href ? 'a' : 'div';

// Extract a numeric value for count-up animation if requested.
$rawNumeric = null;
if ($animate && is_numeric(preg_replace('/[^0-9.\-]/', '', (string) $value))) {
    $rawNumeric = (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
}
?>
<<?= $tag ?> class="admin-stat-card admin-stat-<?= e($tone) ?><?= $href ? ' is-link' : '' ?><?= $rawNumeric !== null ? ' js-count-up' : '' ?>" <?= $href ? 'href="' . e($href) . '"' : '' ?> <?= $rawNumeric !== null ? 'data-count-to="' . e((string) $rawNumeric) . '" data-count-original="' . e((string) $value) . '"' : '' ?>>
    <div class="admin-stat-card-top">
        <span class="admin-stat-card-ic admin-stat-ic-<?= e($tone) ?>">
            <?= \App\Support\View::component('icon', ['name' => $icon ?? 'bar-chart', 'class' => 'h-5 w-5']) ?>
        </span>
        <?php if ($trend && $trendLabel): ?>
            <span class="admin-stat-trend admin-stat-trend-<?= e($trend === 'up' ? 'up' : 'down') ?>">
                <?= \App\Support\View::component('icon', ['name' => $trend === 'up' ? 'trending-up' : 'trending-down', 'class' => 'h-3.5 w-3.5']) ?>
                <?= e($trendLabel) ?>
            </span>
        <?php endif; ?>
    </div>
    <p class="admin-stat-card-value"><?= $value ?></p>
    <p class="admin-stat-card-label"><?= e($label) ?></p>
    <?php if (!empty($hint)): ?><p class="admin-stat-card-hint"><?= e($hint) ?></p><?php endif; ?>
</<?= $tag ?>>
