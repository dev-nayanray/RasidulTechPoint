<?php /** @var list<array> $addresses @var array $errors */
?>
<h1 class="text-xl font-bold tracking-tight text-navy-700">Address Book</h1>
<p class="mt-1 text-sm text-slate-500">Manage delivery addresses. Changing the address book does not alter a historical order's delivery details.</p>

<div class="mt-4 grid gap-6 lg:grid-cols-3">
    <?php /* Add form */ ?>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Add address</h2>
        <form method="post" action="<?= e(url('/account/addresses')) ?>" class="mt-3 space-y-3" data-loading-form>
            <?= csrf_field() ?>
            <input name="label" type="text" placeholder="Label (e.g. Home, Office)" class="input text-sm" maxlength="60">
            <input name="recipient_name" type="text" placeholder="Recipient name *" required class="input text-sm" maxlength="120">
            <input name="recipient_phone" type="tel" placeholder="Phone *" required class="input text-sm" maxlength="30">
            <input name="address_line" type="text" placeholder="Street address *" required class="input text-sm" maxlength="255">
            <input name="area" type="text" placeholder="Area / Thana" class="input text-sm" maxlength="120">
            <input name="city" type="text" placeholder="City / District *" required class="input text-sm" maxlength="100">
            <input name="postal_code" type="text" placeholder="Postal code" class="input text-sm" maxlength="20">
            <textarea name="delivery_instructions" rows="2" placeholder="Delivery instructions (optional)" class="input text-sm" maxlength="500"></textarea>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="is_default" value="1" class="text-brand-blue"> Set as default</label>
            <button type="submit" class="btn-primary w-full text-sm">Add address</button>
        </form>
    </div>

    <?php /* Address list */ ?>
    <div class="lg:col-span-2 space-y-3">
        <?php if ($addresses === []): ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <p class="text-sm text-slate-500">No saved addresses yet. Add one to speed up checkout.</p>
            </div>
        <?php else: ?>
            <?php foreach ($addresses as $addr): ?>
                <div class="rounded-xl bg-white ring-1 ring-slate-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-navy-700 text-sm"><?= e($addr['label']) ?> <?php if ((int) $addr['is_default'] === 1): ?><span class="ml-1 inline-flex items-center rounded-full bg-brand-green/10 text-brand-green text-[10px] font-semibold px-2 py-0.5">Default</span><?php endif; ?></p>
                            <dl class="mt-2 space-y-0.5 text-xs text-slate-600">
                                <div><dt class="inline text-slate-400">Name:</dt> <dd class="inline text-navy-700"><?= e($addr['recipient_name']) ?></dd></div>
                                <div><dt class="inline text-slate-400">Phone:</dt> <dd class="inline text-navy-700"><?= e($addr['recipient_phone']) ?></dd></div>
                                <div><dt class="inline text-slate-400">Address:</dt> <dd class="inline text-navy-700"><?= e($addr['address_line']) ?>, <?= e($addr['area'] ?? '') ?> <?= e($addr['city']) ?> <?= e($addr['postal_code'] ?? '') ?></dd></div>
                            </dl>
                        </div>
                        <div class="flex flex-col gap-1.5 shrink-0">
                            <?php if ((int) $addr['is_default'] !== 1): ?>
                            <form method="post" action="<?= e(url('/account/addresses/' . $addr['id'] . '/default')) ?>" class="inline">
                                <?= csrf_field() ?><button type="submit" class="text-xs text-brand-blue hover:underline">Set default</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/account/addresses/' . $addr['id'] . '/delete')) ?>" class="inline" data-confirm="Delete this address?">
                                <?= csrf_field() ?><button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
