<?php
/**
 * Dashboard stat card — semantic tone variant.
 *
 * @var string $label
 * @var string $value
 * @var string $hint   (optional)
 * @var string $icon   (optional, components/icon.php name)
 * @var string $tone   (optional: blue|green|amber|red|navy|slate) — defaults to blue
 * @var string $href   (optional, makes the whole card clickable)
 */
$tone = $tone ?? 'blue';
$href = $href ?? null;
$tag = $href ? 'a' : 'div';
?>
<<?= $tag ?> class="admin-stat-card<?= $href ? ' block no-underline text-inherit' : '' ?>" <?= $href ? 'href="' . e($href) . '"' : '' ?>>
    <p class="admin-stat-card-label"><?= e($label) ?></p>
    <p class="admin-stat-card-value"><?= $value ?></p>
    <?php if (!empty($hint)): ?><p class="admin-stat-card-hint"><?= e($hint) ?></p><?php endif; ?>
    <?php if (!empty($icon)): ?>
        <span class="admin-stat-card-ic admin-stat-ic-<?= e($tone) ?>">
            <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
        </span>
    <?php endif; ?>
</<?= $tag ?>>
