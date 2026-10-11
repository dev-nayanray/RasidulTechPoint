<?php
/** @var array $profile
 *  @var list<array> $locations
 *  @var list<array> $businessHours
 *  @var array $invoiceSettings
 *  @var array $errors
 */
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<form method="post" action="<?= e(url('/admin/settings')) ?>" enctype="multipart/form-data" class="max-w-3xl space-y-6" novalidate data-loading-form>
    <?= csrf_field() ?>

    <!-- Business profile -->
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
        <div>
            <label for="field-about_text" class="block text-sm font-medium text-slate-700">About text</label>
            <textarea id="field-about_text" name="about_text" rows="4" class="input mt-1.5" placeholder="About your business…"><?= e($profile['about_text'] ?? '') ?></textarea>
        </div>
        <p class="text-xs text-slate-500">Currency is fixed to BDT and storage timezone to UTC at the schema level.</p>
    </section>

    <!-- Logo upload -->
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Business logo</h2>
        <div class="flex items-center gap-4">
            <?php if (!empty($profile['logo_path']) && file_exists(BASE_PATH . '/public/' . $profile['logo_path'])): ?>
                <img src="<?= e(url($profile['logo_path'])) ?>" alt="Current logo" class="h-16 w-16 rounded-lg border border-slate-200 object-contain">
            <?php else: ?>
                <span class="flex h-16 w-16 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs text-slate-400">No logo</span>
            <?php endif; ?>
            <div class="flex-1">
                <label for="field-logo" class="block text-sm font-medium text-slate-700">Upload new logo</label>
                <input id="field-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1.5 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700">
                <p class="mt-1 text-xs text-slate-500">JPEG, PNG, or WebP. Maximum 4 MiB. The file is checked by its real content type, not the browser-reported type.</p>
                <?php if (!empty($errors['logo'])): ?><p class="mt-1 text-xs text-red-600"><?= e($errors['logo']) ?></p><?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Business hours -->
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Business hours</h2>
        <p class="text-xs text-slate-500">Set the weekly schedule. Times are in 24-hour HH:MM format.</p>
        <div class="space-y-2">
            <?php foreach (range(0, 6) as $day): ?>
                <?php $bh = $businessHours[$day] ?? ['is_closed' => 0, 'open_time' => '10:00', 'close_time' => '20:00', 'notes' => '']; ?>
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-100 px-3 py-2">
                    <span class="w-24 text-sm font-medium text-slate-700"><?= e($days[$day]) ?></span>
                    <label class="flex items-center gap-1.5 text-sm text-slate-600">
                        <input type="checkbox" name="business_hours[<?= $day ?>][is_closed]" value="1"
                            <?= (int) $bh['is_closed'] === 1 ? 'checked' : '' ?>
                            class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 business-hours-closed">
                        Closed
                    </label>
                    <div class="business-hours-times <?= (int) $bh['is_closed'] === 1 ? 'hidden' : '' ?>">
                        <input type="text" name="business_hours[<?= $day ?>][open_time]" value="<?= e(substr((string) $bh['open_time'], 0, 5)) ?>" placeholder="10:00" class="input w-20 text-sm" pattern="\d{1,2}:\d{2}">
                        <span class="text-slate-400">to</span>
                        <input type="text" name="business_hours[<?= $day ?>][close_time]" value="<?= e(substr((string) $bh['close_time'], 0, 5)) ?>" placeholder="20:00" class="input w-20 text-sm" pattern="\d{1,2}:\d{2}">
                    </div>
                    <input type="text" name="business_hours[<?= $day ?>][notes]" value="<?= e($bh['notes'] ?? '') ?>" placeholder="Notes (optional)" class="input ml-auto max-w-[12rem] text-sm">
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Invoice settings -->
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">Invoice settings</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label for="field-invoice_prefix" class="block text-sm font-medium text-slate-700">Invoice prefix</label>
                <input id="field-invoice_prefix" name="invoice_prefix" type="text" value="<?= e($invoiceSettings['invoice_prefix'] ?? 'INV') ?>" class="input" maxlength="10">
            </div>
            <div class="space-y-1.5">
                <label for="field-invoice_next_number" class="block text-sm font-medium text-slate-700">Next invoice number</label>
                <input id="field-invoice_next_number" name="invoice_next_number" type="number" value="<?= e((string) ($invoiceSettings['invoice_next_number'] ?? 1)) ?>" class="input" min="1">
            </div>
            <div class="space-y-1.5">
                <label for="field-invoice_tax_name" class="block text-sm font-medium text-slate-700">Tax name</label>
                <input id="field-invoice_tax_name" name="invoice_tax_name" type="text" value="<?= e($invoiceSettings['invoice_tax_name'] ?? 'VAT') ?>" class="input" maxlength="30">
            </div>
            <div class="space-y-1.5">
                <label for="field-invoice_tax_rate" class="block text-sm font-medium text-slate-700">Tax rate (%)</label>
                <input id="field-invoice_tax_rate" name="invoice_tax_rate" type="number" step="0.01" value="<?= e($invoiceSettings['invoice_tax_rate'] ?? '0') ?>" class="input" min="0" max="100">
            </div>
            <div class="space-y-1.5 sm:col-span-2">
                <label for="field-invoice_payment_terms" class="block text-sm font-medium text-slate-700">Payment terms</label>
                <input id="field-invoice_payment_terms" name="invoice_payment_terms" type="text" value="<?= e($invoiceSettings['invoice_payment_terms'] ?? '') ?>" class="input" maxlength="255">
            </div>
            <div class="space-y-1.5 sm:col-span-2">
                <label for="field-invoice_footer_text" class="block text-sm font-medium text-slate-700">Invoice footer text</label>
                <textarea id="field-invoice_footer_text" name="invoice_footer_text" rows="2" class="input" maxlength="500" placeholder="Thank you for your business!"><?= e($invoiceSettings['invoice_footer_text'] ?? '') ?></textarea>
            </div>
        </div>
    </section>

    <button type="submit" class="btn-primary">Save settings</button>
</form>

<!-- Locations (read-only display) -->
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

<script>
// Toggle the open/close time inputs when the "Closed" checkbox changes.
document.querySelectorAll('.business-hours-closed').forEach((cb) => {
    const timesDiv = cb.closest('.flex')?.querySelector('.business-hours-times');
    if (!timesDiv) return;
    const toggle = () => timesDiv.classList.toggle('hidden', cb.checked);
    cb.addEventListener('change', toggle);
    toggle();
});
</script>
