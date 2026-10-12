<?php
/** @var string $customerId $unitId
 *  @var list<string> $serviceTypes
 *  @var list<string> $priorities
 *  @var array<string,string> $errors
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/service/jobs')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Jobs</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">New service job</h1>
<form method="post" action="<?= e(url('/admin/service/jobs')) ?>" class="mt-6 max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <div class="space-y-1.5">
        <label for="field-service_type" class="block text-sm font-medium text-slate-700">Service type</label>
        <select id="field-service_type" name="service_type" class="input">
            <?php foreach ($serviceTypes as $t): ?>
                <option value="<?= e($t) ?>"><?= e(ucfirst($t)) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">Warranty = linked to a claim (no charge); Paid = customer pays; Internal = no customer.</p>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'customer_id', 'label' => 'Customer ID', 'type' => 'number', 'value' => $customerId]) ?>
    <?= \App\Support\View::component('field', ['name' => 'inventory_unit_id', 'label' => 'Inventory unit ID (optional)', 'type' => 'number', 'value' => $unitId]) ?>
    <?= \App\Support\View::component('field', ['name' => 'warranty_claim_id', 'label' => 'Warranty claim ID (required for warranty type)', 'type' => 'number']) ?>
    <?= \App\Support\View::component('field', ['name' => 'order_id', 'label' => 'Order ID (optional)', 'type' => 'number']) ?>
    <div class="space-y-1.5">
        <label for="field-priority" class="block text-sm font-medium text-slate-700">Priority</label>
        <select id="field-priority" name="priority" class="input">
            <?php foreach ($priorities as $p): ?>
                <option value="<?= e($p) ?>"><?= e(ucfirst($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="space-y-1.5">
        <label for="field-reported_issue" class="block text-sm font-medium text-slate-700">Reported issue (required)</label>
        <textarea id="field-reported_issue" name="reported_issue" rows="3" required class="input" placeholder="Customer says: laptop won't boot; screen flickers; battery drains fast…"></textarea>
    </div>
    <?= \App\Support\View::component('field', ['name' => 'estimated_cost', 'label' => 'Estimated cost (optional)', 'type' => 'number', 'step' => '0.01', 'min' => '0']) ?>
    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create job</button>
        <a href="<?= e(url('/admin/service/jobs')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
