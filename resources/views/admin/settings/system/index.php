<?php /** @var list<array> $categories
 *  each row: {key:string, label:string, secret_count:int, secrets_configured:int}
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/settings')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Business settings</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">System settings</h1>
<p class="mt-1 text-sm text-slate-500">Categorized runtime configuration. Secrets (API keys, SMTP password, payment provider tokens) are encrypted at rest with AES-256-CBC under <code>APP_KEY</code> and never displayed back.</p>

<?php if (!\App\Support\Crypto::available()): ?>
    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <strong>APP_KEY is not set.</strong> Secret fields cannot be saved until <code>APP_KEY</code> is configured in <code>.env</code>. Plaintext settings still work.
    </div>
<?php endif; ?>

<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($categories as $c): ?>
        <a href="<?= e(url('/admin/settings/system/' . $c['key'])) ?>" class="block rounded-xl border border-slate-200 bg-white p-5 hover:border-blue-400 hover:bg-blue-50/30">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900"><?= e($c['label']) ?></h2>
                <?php if ((int) $c['secret_count'] > 0): ?>
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                        <?= (int) $c['secrets_configured'] ?>/<?= (int) $c['secret_count'] ?> secrets
                    </span>
                <?php endif; ?>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                <?php
                    $hints = [
                        'currency' => 'ISO code, symbol, position, decimal places',
                        'dates' => 'Display formats + first day of week',
                        'invoice' => 'Numbering, tax, footer, payment terms',
                        'inventory' => 'Low-stock threshold, default markup, serial tracking',
                        'warranty' => 'Default days, serial requirement, claim window',
                        'delivery' => 'Carrier, shipping days + fee, free-ship threshold',
                        'email' => 'SMTP host, port, encryption, password (secret)',
                        'payments' => 'Stripe / bKash / Nagad keys (some secret)',
                        'integrations' => 'SMS, analytics, webhooks (some secret)',
                    ];
                    echo e($hints[$c['key']] ?? '');
                ?>
            </p>
        </a>
    <?php endforeach; ?>
</div>
