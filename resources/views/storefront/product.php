<?php /** @var array $model */ ?>
<nav class="text-sm text-slate-500" aria-label="Breadcrumb">
    <ol class="flex items-center gap-2">
        <li><a href="<?= e(url('/')) ?>" class="hover:text-blue-700">Home</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= e(url('/shop')) ?>" class="hover:text-blue-700">Shop</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page" class="font-medium text-slate-700"><?= e($model['name']) ?></li>
    </ol>
</nav>

<div class="mt-6 grid gap-8 lg:grid-cols-2">
    <div class="rounded-2xl border border-slate-200 bg-white p-8">
        <p class="text-sm font-semibold text-blue-700"><?= e($model['brand_name']) ?> · <?= e($model['category_name']) ?></p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= e($model['name']) ?></h1>
        <p class="mt-4 text-sm leading-relaxed text-slate-600"><?= e($model['description'] ?? 'Dubai imported authentic used laptop, tested and quality checked by our technicians.') ?></p>

        <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <?php
            $specs = [
                'Processor' => $model['processor'],
                'Memory' => $model['ram_gb'] ? $model['ram_gb'] . ' GB' : null,
                'Storage' => $model['storage_capacity_gb'] ? $model['storage_capacity_gb'] . ' GB ' . $model['storage_type'] : null,
                'Graphics' => $model['graphics'],
                'Display' => $model['display_size_inches'] ? $model['display_size_inches'] . '" ' . ($model['resolution'] ?? '') : null,
                'Operating system' => $model['operating_system'],
            ];
            foreach ($specs as $label => $value): ?>
                <div>
                    <dt class="text-slate-500"><?= e($label) ?></dt>
                    <dd class="font-medium text-slate-900"><?= e($value ?? '—') ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>

    <div>
        <h2 class="text-lg font-semibold text-slate-900">Units in stock</h2>
        <?php if (($units ?? []) === []): ?>
            <div class="mt-4"><?= \App\Support\View::component('empty-state', [
                'title' => 'No units currently available',
                'description' => 'This model is listed but every unit is sold or reserved. Contact us about the next shipment.',
                'icon' => 'package-check',
            ]) ?></div>
        <?php else: ?>
            <ul class="mt-4 space-y-3">
                <?php foreach ($units as $unit): ?>
                    <li class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-center gap-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-sm font-bold text-slate-600">G<?= e($unit['condition_grade']) ?></span>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Condition grade <?= e($unit['condition_grade']) ?></p>
                                <p class="text-xs text-slate-500">Unit <?= e($unit['sku']) ?> · inspected &amp; tested</p>
                            </div>
                        </div>
                        <p class="text-lg font-bold text-slate-900"><?= money($unit['selling_price']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <p class="font-medium">Want to buy this laptop?</p>
                <p class="mt-1">Call or message us to reserve a unit, or visit the shop. Payment on delivery and in-store options available.</p>
                <a href="<?= e(url('/contact')) ?>" class="mt-3 inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Contact us</a>
            </div>
        <?php endif; ?>
    </div>
</div>
