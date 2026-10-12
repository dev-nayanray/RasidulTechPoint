<?php /** @var array|null $coupon
 *  @var array $errors
 *  @var list<array> $products
 *  @var list<array> $categories
 *  @var list<string> $discountTypes
 *  @var list<string> $appliesTo
 *  @var list<string> $eligibilityModes
 */
$isEdit = $coupon !== null && !empty($coupon['id']);
$code = $isEdit ? ($coupon['code'] ?? '') : ($coupon['code'] ?? old('code', ''));
$description = $isEdit ? ($coupon['description'] ?? '') : ($coupon['description'] ?? old('description', ''));
$discountType = $isEdit ? ($coupon['discount_type'] ?? 'fixed') : ($coupon['discount_type'] ?? old('discount_type', 'fixed'));
$discountValue = $isEdit ? ($coupon['discount_value'] ?? '') : ($coupon['discount_value'] ?? old('discount_value', ''));
$minSubtotal = $isEdit ? ($coupon['min_subtotal'] ?? '0') : ($coupon['min_subtotal'] ?? old('min_subtotal', '0'));
$maxDiscount = $isEdit ? ($coupon['max_discount'] ?? '') : ($coupon['max_discount'] ?? old('max_discount', ''));
$startsAt = $isEdit ? (substr((string) ($coupon['starts_at'] ?? ''), 0, 16)) : ($coupon['starts_at'] ?? old('starts_at', ''));
$endsAt = $isEdit ? (substr((string) ($coupon['ends_at'] ?? ''), 0, 16)) : ($coupon['ends_at'] ?? old('ends_at', ''));
$usageLimit = $isEdit ? ($coupon['usage_limit'] ?? '') : ($coupon['usage_limit'] ?? old('usage_limit', ''));
$usagePerCustomer = $isEdit ? ($coupon['usage_limit_per_customer'] ?? 1) : ($coupon['usage_limit_per_customer'] ?? old('usage_limit_per_customer', 1));
// IMPORTANT: do not name these "appliesTo" / "eligibilityMode" — those names
// already hold the full option arrays passed from the controller. Renaming
// to *Value avoids clobbering the foreach loops below.
$appliesToValue = $isEdit ? ($coupon['applies_to'] ?? 'all') : ($coupon['applies_to'] ?? old('applies_to', 'all'));
$eligibilityModeValue = $isEdit ? ($coupon['eligibility_mode'] ?? 'include') : ($coupon['eligibility_mode'] ?? old('eligibility_mode', 'include'));

$selectedProductIds = $isEdit ? array_column($coupon['target_products'] ?? [], 'id') : (array) (old('target_product_ids', []));
$selectedCategoryIds = $isEdit ? array_column($coupon['target_categories'] ?? [], 'id') : (array) (old('target_category_ids', []));
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/coupons')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Coupons</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= $isEdit ? 'Edit coupon ' . e($coupon['code']) : 'New coupon' ?></h1>

<form method="post" action="<?= e($isEdit ? url('/admin/coupons/' . $coupon['id'] . '/update') : url('/admin/coupons')) ?>" class="mt-4 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2" data-loading-form>
    <?= csrf_field() ?>

    <?= \App\Support\View::component('field', ['name' => 'code', 'label' => 'Coupon code', 'required' => true, 'value' => $code, 'error' => $errors['code'] ?? null, 'help' => 'Upper-case letters, numbers, dash or underscore. Case-insensitive at checkout.']) ?>
    <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description (optional)', 'value' => $description, 'error' => $errors['description'] ?? null]) ?>

    <div class="space-y-1.5">
        <label for="field-discount_type" class="block text-sm font-medium text-slate-700">Discount type <span class="text-red-600">*</span></label>
        <select id="field-discount_type" name="discount_type" class="input">
            <?php foreach ($discountTypes as $t): ?>
                <option value="<?= e($t) ?>" <?= $t === $discountType ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (($errors['discount_type'] ?? null)): ?><p class="text-xs text-red-600"><?= e($errors['discount_type']) ?></p><?php endif; ?>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'discount_value', 'label' => 'Discount value', 'type' => 'number', 'required' => true, 'value' => $discountValue, 'error' => $errors['discount_value'] ?? null, 'help' => 'Fixed: BDT amount. Percentage: 0–100.']) ?>

    <?= \App\Support\View::component('field', ['name' => 'min_subtotal', 'label' => 'Minimum subtotal (BDT)', 'type' => 'number', 'value' => $minSubtotal, 'error' => $errors['min_subtotal'] ?? null, 'help' => 'Coupon requires cart subtotal ≥ this. 0 = no minimum.']) ?>
    <?= \App\Support\View::component('field', ['name' => 'max_discount', 'label' => 'Max discount cap (BDT, optional)', 'type' => 'number', 'value' => $maxDiscount, 'error' => $errors['max_discount'] ?? null, 'help' => 'Caps percentage coupons. Leave blank for no cap.']) ?>

    <div class="space-y-1.5">
        <label for="field-starts_at" class="block text-sm font-medium text-slate-700">Valid from (UTC)</label>
        <input id="field-starts_at" name="starts_at" type="datetime-local" value="<?= e($startsAt) ?>" class="input">
    </div>
    <div class="space-y-1.5">
        <label for="field-ends_at" class="block text-sm font-medium text-slate-700">Valid until (UTC)</label>
        <input id="field-ends_at" name="ends_at" type="datetime-local" value="<?= e($endsAt) ?>" class="input">
        <?php if (($errors['ends_at'] ?? null)): ?><p class="text-xs text-red-600"><?= e($errors['ends_at']) ?></p><?php endif; ?>
    </div>

    <?= \App\Support\View::component('field', ['name' => 'usage_limit', 'label' => 'Total usage limit (optional)', 'type' => 'number', 'value' => $usageLimit, 'error' => $errors['usage_limit'] ?? null, 'help' => 'Max redemptions overall. Leave blank for unlimited.']) ?>
    <?= \App\Support\View::component('field', ['name' => 'usage_limit_per_customer', 'label' => 'Per-customer limit', 'type' => 'number', 'required' => true, 'value' => $usagePerCustomer, 'error' => $errors['usage_limit_per_customer'] ?? null, 'help' => 'Default 1. Set 0 to disable (only total limit applies).']) ?>

    <div class="space-y-1.5">
        <label for="field-applies_to" class="block text-sm font-medium text-slate-700">Applies to</label>
        <select id="field-applies_to" name="applies_to" class="input" data-coupon-applies>
            <?php foreach ($appliesTo as $a): ?>
                <option value="<?= e($a) ?>" <?= $a === $appliesToValue ? 'selected' : '' ?>><?= e(ucfirst($a)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="space-y-1.5">
        <label for="field-eligibility_mode" class="block text-sm font-medium text-slate-700">Eligibility mode</label>
        <select id="field-eligibility_mode" name="eligibility_mode" class="input">
            <?php foreach ($eligibilityModes as $m): ?>
                <option value="<?= e($m) ?>" <?= $m === $eligibilityModeValue ? 'selected' : '' ?>><?= e(ucfirst($m)) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">Include = coupon applies ONLY to listed items. Exclude = coupon applies to everything EXCEPT listed items.</p>
    </div>

    <div class="sm:col-span-2 space-y-1.5" data-coupon-targets-products>
        <label class="block text-sm font-medium text-slate-700">Target products</label>
        <select name="target_product_ids[]" multiple class="input h-40" size="10">
            <?php foreach ($products as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $selectedProductIds, true) ? 'selected' : '' ?>>
                    <?= e($p['name']) ?> — <?= e($p['brand_name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">Ctrl/Cmd-click to select multiple. Required when "Applies to" = Products.</p>
        <?php if (($errors['target_product_ids'] ?? null)): ?><p class="text-xs text-red-600"><?= e($errors['target_product_ids']) ?></p><?php endif; ?>
    </div>

    <div class="sm:col-span-2 space-y-1.5" data-coupon-targets-categories>
        <label class="block text-sm font-medium text-slate-700">Target categories</label>
        <select name="target_category_ids[]" multiple class="input h-40" size="10">
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $selectedCategoryIds, true) ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">Required when "Applies to" = Categories.</p>
        <?php if (($errors['target_category_ids'] ?? null)): ?><p class="text-xs text-red-600"><?= e($errors['target_category_ids']) ?></p><?php endif; ?>
    </div>

    <div class="sm:col-span-2 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
        <a href="<?= e(url('/admin/coupons')) ?>" class="btn-secondary">Cancel</a>
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Create coupon' ?></button>
    </div>
</form>

<?php if ($isEdit && in_array($coupon['status'] ?? '', ['rejected', 'archived'], true)): ?>
    <p class="mt-3 text-xs text-amber-700">A <?= e($coupon['status']) ?> coupon cannot be edited. Create a new coupon instead.</p>
<?php endif; ?>

<script type="module">
    // Toggle target picker visibility based on applies_to.
    const select = document.querySelector('[data-coupon-applies]');
    const productsBox = document.querySelector('[data-coupon-targets-products]');
    const categoriesBox = document.querySelector('[data-coupon-targets-categories]');
    const sync = () => {
        const v = (select?.value ?? 'all');
        productsBox?.classList.toggle('hidden', v !== 'products');
        categoriesBox?.classList.toggle('hidden', v !== 'categories');
    };
    select?.addEventListener('change', sync);
    sync();
</script>
