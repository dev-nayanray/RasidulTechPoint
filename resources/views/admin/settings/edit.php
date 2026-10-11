<?php /** @var array $profile @var list<array> $locations */ ?>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="max-w-3xl space-y-6" novalidate data-loading-form>
    <?= csrf_field() ?>

    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Business profile</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Business name', 'required' => true, 'value' => $profile['name'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'legal_name', 'label' => 'Legal name', 'value' => $profile['legal_name'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'tagline', 'label' => 'Tagline', 'value' => $profile['tagline'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'email', 'label' => 'Public email', 'type' => 'email', 'value' => $profile['email'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'phone', 'label' => 'Phone', 'value' => $profile['phone'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'default_warranty_days', 'label' => 'Default warranty (days)', 'type' => 'number', 'value' => (string) ($profile['default_warranty_days'] ?? 30), 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'address', 'label' => 'Address', 'value' => $profile['address'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'city', 'label' => 'City', 'value' => $profile['city'] ?? '', 'errors' => $errors ?? []]) ?>
            <?= \App\Support\View::component('field', ['name' => 'country', 'label' => 'Country', 'value' => $profile['country'] ?? '', 'errors' => $errors ?? []]) ?>
        </div>
        <p class="text-xs text-slate-500">Currency is fixed to BDT and storage timezone to UTC at the schema level.</p>
    </section>

    <button type="submit" class="btn-primary">Save settings</button>
</form>

<section class="mt-8 max-w-3xl rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-sm font-semibold text-slate-900">Locations</h2>
    <table class="mt-3 min-w-full divide-y divide-slate-100 text-sm">
        <tbody>
            <?php foreach ($locations as $location): ?>
                <tr>
                    <td class="py-2 font-medium text-slate-900"><?= e($location['name']) ?></td>
                    <td class="py-2 text-slate-500 capitalize"><?= e($location['type']) ?></td>
                    <td class="py-2 text-slate-500"><?= e($location['address'] ?? '—') ?></td>
                    <td class="py-2 text-right"><?= \App\Support\View::component('badge', ['status' => (int) $location['is_active'] === 1 ? 'active' : 'inactive']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
