<div class="mx-auto max-w-3xl">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">About Rasidul Tech Point</h1>
    <p class="mt-4 text-slate-600 leading-relaxed">
        Rasidul Tech Point imports authentic used laptops from Dubai and sells them online and from our retail shop.
        Every unit is individually tested, quality checked and graded before sale — so you know exactly what you are buying.
    </p>

    <div class="mt-8 grid gap-4 sm:grid-cols-3">
        <?php foreach ([
            ['check-circle', 'Individual testing', 'Each laptop is inspected on arrival: battery health, display, keyboard, ports, and full diagnostics.'],
            ['layers', 'Honest grading', 'Units are graded A–D with real photos and honest descriptions. No surprises.'],
            ['shield', 'Warranty & service', 'Warranty is included and our technicians provide after-sale service.'],
        ] as [$icon, $title, $text]): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                    <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-5 w-5']) ?>
                </span>
                <p class="mt-3 font-semibold text-slate-900"><?= e($title) ?></p>
                <p class="mt-1 text-sm text-slate-500"><?= e($text) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>
