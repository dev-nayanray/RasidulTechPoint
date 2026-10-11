<?php /** @var list<array> $zones @var list<array> $couriers */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/delivery')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Delivery</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Delivery zones</h1>

<details class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
    <summary class="cursor-pointer font-semibold text-slate-900">Add zone</summary>
    <form method="post" action="<?= e(url('/admin/delivery/zones')) ?>" class="mt-4 grid gap-3 sm:grid-cols-2" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Zone name', 'required' => true]) ?>
        <?= \App\Support\View::component('field', ['name' => 'base_fee', 'label' => 'Base fee (BDT)', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => '0.00']) ?>
        <div class="space-y-1.5">
            <label for="zone-courier" class="block text-sm font-medium text-slate-700">Preferred courier</label>
            <select id="zone-courier" name="courier_id" class="input">
                <option value="">— None —</option>
                <?php foreach ($couriers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?= \App\Support\View::component('field', ['name' => 'estimated_delivery_hours', 'label' => 'Est. delivery (hours)', 'type' => 'number', 'min' => '0']) ?>
        <div class="sm:col-span-2"><label for="zone-desc" class="block text-sm font-medium text-slate-700">Description</label><input id="zone-desc" name="description" class="input" placeholder="Dhaka North (Zones 1-12); outside city limits = +50 BDT"></div>
        <div class="sm:col-span-2"><button type="submit" class="btn-primary">Create zone</button></div>
    </form>
</details>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Courier</th>
                <th class="px-4 py-3 text-right font-medium text-slate-500">Base fee</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Est. hours</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($zones as $z): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($z['name']) ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($z['courier_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-right text-slate-700"><?= money($z['base_fee']) ?></td>
                <td class="px-4 py-3 text-slate-500"><?= $z['estimated_delivery_hours'] !== null ? (int) $z['estimated_delivery_hours'] . 'h' : '—' ?></td>
                <td class="px-4 py-3"><?= (int) $z['is_active'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
                <td class="px-4 py-3 text-right">
                    <form method="post" action="<?= e(url('/admin/delivery/zones/' . $z['id'] . '/delete')) ?>" data-loading-form data-confirm="Delete this zone?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-secondary text-xs">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($zones === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No delivery zones yet.</p><?php endif; ?>
</div>
