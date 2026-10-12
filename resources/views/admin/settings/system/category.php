<?php /** @var string $category
 *  @var string $categoryLabel
 *  @var array<string,string> $categories
 *  @var array<string,mixed> $values
 *  @var array<string,bool> $secretStatus  // key => is_set
 *  @var array $errors
 *  @var bool $cryptoAvailable
 */
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/settings/system')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← System settings</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900"><?= e($categoryLabel) ?></h1>

<?php if (!$cryptoAvailable && !empty($secretStatus)): ?>
    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <strong>APP_KEY is not set.</strong> Secret fields cannot be saved or updated. Plaintext fields below still work normally.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/settings/system/' . $category)) ?>" class="mt-4 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2" data-loading-form>
    <?= csrf_field() ?>

    <?php
    // Dispatch to the right field set based on $category.
    $v = $values; $errs = $errors; $ss = $secretStatus;
    $field = function (string $name, string $label, string $type = 'text', array $opts = []) use ($v, $errs): void {
        $value = $v[$name] ?? '';
        $error = $errs[$name] ?? null;
        $required = !empty($opts['required']);
        $help = $opts['help'] ?? null;
        $id = 'field-' . $name;
        ?>
        <div class="space-y-1.5">
            <label for="<?= e($id) ?>" class="block text-sm font-medium text-slate-700"><?= e($label) ?><?php if ($required): ?> <span class="text-red-600">*</span><?php endif; ?></label>
            <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" value="<?= e((string) $value) ?>" <?= $required ? 'required' : '' ?> <?= !empty($opts['attrs']) ? $opts['attrs'] : '' ?> class="input <?= $error ? 'border-red-400' : '' ?>">
            <?php if ($error): ?><p class="text-xs text-red-600"><?= e($error) ?></p><?php elseif ($help): ?><p class="text-xs text-slate-500"><?= e($help) ?></p><?php endif; ?>
        </div>
        <?php
    };
    $select = function (string $name, string $label, array $options, array $opts = []) use ($v, $errs): void {
        $value = $v[$name] ?? '';
        $error = $errs[$name] ?? null;
        $required = !empty($opts['required']);
        $help = $opts['help'] ?? null;
        $id = 'field-' . $name;
        ?>
        <div class="space-y-1.5">
            <label for="<?= e($id) ?>" class="block text-sm font-medium text-slate-700"><?= e($label) ?><?php if ($required): ?> <span class="text-red-600">*</span><?php endif; ?></label>
            <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="input <?= $error ? 'border-red-400' : '' ?>">
                <?php foreach ($options as $optValue => $optLabel): ?>
                    <option value="<?= e($optValue) ?>" <?= (string) $value === (string) $optValue ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($error): ?><p class="text-xs text-red-600"><?= e($error) ?></p><?php elseif ($help): ?><p class="text-xs text-slate-500"><?= e($help) ?></p><?php endif; ?>
        </div>
        <?php
    };
    $checkbox = function (string $name, string $label, array $opts = []) use ($v): void {
        $value = (bool) ($v[$name] ?? false);
        ?>
        <div class="space-y-1.5">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="<?= e($name) ?>" value="1" <?= $value ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <?= e($label) ?>
            </label>
            <?php if (!empty($opts['help'])): ?><p class="text-xs text-slate-500"><?= e($opts['help']) ?></p><?php endif; ?>
        </div>
        <?php
    };
    $secret = function (string $name, string $label, array $opts = []) use ($ss, $cryptoAvailable): void {
        $isSet = $ss[$name] ?? false;
        $id = 'field-' . $name;
        ?>
        <div class="space-y-1.5">
            <label for="<?= e($id) ?>" class="block text-sm font-medium text-slate-700"><?= e($label) ?> <span class="text-amber-600" title="Secret — encrypted at rest">🔒</span></label>
            <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="password" autocomplete="new-password" placeholder="<?= $isSet ? '•••••••• (configured — leave blank to keep)' : 'Not yet configured' ?>" class="input" <?= !empty($opts['attrs']) ? $opts['attrs'] : '' ?>>
            <div class="flex items-center gap-3 text-xs">
                <span class="<?= $isSet ? 'text-green-700' : 'text-slate-400' ?>"><?= $isSet ? '✓ Configured' : 'Not configured' ?></span>
                <label class="text-slate-500"><input type="checkbox" name="clear_<?= e($name) ?>" value="1" <?= !$cryptoAvailable ? 'disabled' : '' ?>> Clear stored value</label>
            </div>
            <?php if (!empty($opts['help'])): ?><p class="text-xs text-slate-500"><?= e($opts['help']) ?></p><?php endif; ?>
        </div>
        <?php
    };
    ?>

    <?php switch ($category):
        case 'currency': ?>
            <?= $field('currency_code', 'ISO currency code', 'text', ['required' => true, 'help' => '3-letter ISO 4217 code, e.g. BDT, USD, EUR.']) ?>
            <?= $field('currency_symbol', 'Currency symbol', 'text', ['required' => true, 'help' => 'UTF-8 symbol, e.g. ৳, $, €.']) ?>
            <?= $select('currency_position', 'Symbol position', ['before' => 'Before amount (৳ 1,000)', 'after' => 'After amount (1,000 ৳)'], ['required' => true]) ?>
            <?= $field('currency_decimal_places', 'Decimal places', 'number', ['required' => true, 'attrs' => 'min="0" max="4"']) ?>
        <?php break; case 'dates': ?>
            <?= $field('date_format', 'Date format', 'text', ['required' => true, 'help' => 'PHP date() format, e.g. d M Y.']) ?>
            <?= $field('datetime_format', 'Date+time format', 'text', ['required' => true, 'help' => 'PHP date() format, e.g. d M Y, g:i a.']) ?>
            <?= $field('time_format', 'Time format', 'text', ['required' => true]) ?>
            <?= $select('first_day_of_week', 'First day of week', ['sun' => 'Sunday', 'mon' => 'Monday'], ['required' => true]) ?>
        <?php break; case 'invoice': ?>
            <?= $field('invoice_prefix', 'Invoice prefix', 'text', ['required' => true, 'help' => 'Prepended to invoice numbers, e.g. INV → INV-0001.']) ?>
            <?= $field('invoice_next_number', 'Next invoice number', 'number', ['required' => true, 'attrs' => 'min="1"']) ?>
            <?= $field('invoice_tax_name', 'Tax name (optional)', 'text', ['help' => 'e.g. VAT, GST.']) ?>
            <?= $field('invoice_tax_rate', 'Tax rate %', 'number', ['attrs' => 'step="0.01" min="0" max="100"']) ?>
            <div class="sm:col-span-2"><?= $field('invoice_footer_text', 'Footer text', 'text') ?></div>
            <div class="sm:col-span-2"><?= $field('invoice_payment_terms', 'Payment terms', 'text') ?></div>
            <div class="sm:col-span-2"><?= $checkbox('invoice_reset_yearly', 'Reset invoice counter at the start of each calendar year') ?></div>
        <?php break; case 'inventory': ?>
            <?= $field('inventory_low_stock_threshold', 'Low-stock threshold', 'number', ['required' => true, 'attrs' => 'min="0" max="10000"', 'help' => 'Number of available units at or below which a low-stock alert fires.']) ?>
            <?= $field('inventory_default_markup_percent', 'Default markup %', 'number', ['required' => true, 'attrs' => 'min="0" max="500"']) ?>
            <div class="sm:col-span-2"><?= $checkbox('inventory_allow_negative_stock', 'Allow negative stock (not enforced at the data layer — informational)', ['help' => 'The inventory_units table structurally forbids negative stock regardless of this setting.']) ?></div>
            <div class="sm:col-span-2"><?= $checkbox('inventory_track_serial_numbers', 'Track per-unit serial numbers') ?></div>
        <?php break; case 'warranty': ?>
            <?= $field('warranty_default_days', 'Default warranty days', 'number', ['required' => true, 'attrs' => 'min="0" max="1825"', 'help' => 'Applied to new warranty policies when no override is supplied.']) ?>
            <?= $field('warranty_claim_window_days', 'Claim window days', 'number', ['required' => true, 'attrs' => 'min="0" max="365"', 'help' => 'Days after purchase during which a customer can file a claim.']) ?>
            <div class="sm:col-span-2"><?= $checkbox('warranty_requires_serial', 'Require serial number to register a warranty') ?></div>
        <?php break; case 'delivery': ?>
            <?= $field('delivery_default_carrier', 'Default carrier (optional)', 'text') ?>
            <?= $field('delivery_default_shipping_days', 'Default shipping days', 'number', ['required' => true, 'attrs' => 'min="0" max="60"']) ?>
            <?= $field('delivery_default_shipping_fee', 'Default shipping fee (BDT)', 'number', ['attrs' => 'step="0.01" min="0"']) ?>
            <?= $field('delivery_free_shipping_threshold', 'Free shipping threshold (BDT, optional)', 'number', ['help' => 'Orders at or above this subtotal get free shipping. Leave blank to disable.']) ?>
        <?php break; case 'email': ?>
            <?= $select('mail_driver', 'Mail driver', ['log' => 'Log (writes to storage/logs/mail.log)', 'smtp' => 'SMTP', 'sendmail' => 'Sendmail'], ['required' => true]) ?>
            <?= $field('mail_from_address', 'From address', 'email', ['required' => true]) ?>
            <?= $field('mail_from_name', 'From name', 'text', ['required' => true]) ?>
            <?= $field('mail_smtp_host', 'SMTP host', 'text', ['help' => 'e.g. smtp.gmail.com.']) ?>
            <?= $field('mail_smtp_port', 'SMTP port', 'number', ['required' => true, 'attrs' => 'min="1" max="65535"']) ?>
            <?= $select('mail_smtp_encryption', 'SMTP encryption', ['none' => 'None', 'tls' => 'TLS', 'ssl' => 'SSL']) ?>
            <?= $field('mail_smtp_username', 'SMTP username', 'text') ?>
            <?= $secret('mail_smtp_password', 'SMTP password') ?>
            <div class="sm:col-span-2"><?= $field('mail_bcc_address', 'BCC address (optional)', 'email') ?></div>
        <?php break; case 'payments': ?>
            <div class="sm:col-span-2 border-t border-slate-100 pt-3 text-xs font-semibold uppercase text-slate-400">Stripe</div>
            <div class="sm:col-span-2"><?= $checkbox('payment_provider_stripe_enabled', 'Enable Stripe as a payment method at checkout') ?></div>
            <?= $field('payment_provider_stripe_publishable_key', 'Publishable key (pk_…)', 'text') ?>
            <?= $secret('payment_provider_stripe_secret_key', 'Secret key (sk_…)') ?>
            <div class="sm:col-span-2"><?= $secret('payment_provider_stripe_webhook_secret', 'Webhook signing secret (whsec_…)') ?></div>
            <div class="sm:col-span-2 border-t border-slate-100 pt-3 text-xs font-semibold uppercase text-slate-400">bKash</div>
            <div class="sm:col-span-2"><?= $checkbox('payment_provider_bkash_enabled', 'Enable bKash as a payment method at checkout') ?></div>
            <?= $field('payment_provider_bkash_app_key', 'App key', 'text') ?>
            <?= $field('payment_provider_bkash_username', 'Username', 'text') ?>
            <div class="sm:col-span-2"><?= $secret('payment_provider_bkash_app_secret', 'App secret') ?></div>
            <div class="sm:col-span-2"><?= $secret('payment_provider_bkash_password', 'Password') ?></div>
            <div class="sm:col-span-2 border-t border-slate-100 pt-3 text-xs font-semibold uppercase text-slate-400">Nagad</div>
            <div class="sm:col-span-2"><?= $checkbox('payment_provider_nagad_enabled', 'Enable Nagad as a payment method at checkout') ?></div>
            <?= $field('payment_provider_nagad_merchant_id', 'Merchant ID', 'text') ?>
            <?= $field('payment_provider_nagad_public_key', 'Public key', 'text') ?>
            <div class="sm:col-span-2"><?= $secret('payment_provider_nagad_secret_key', 'Secret key') ?></div>
        <?php break; case 'integrations': ?>
            <div class="sm:col-span-2 border-t border-slate-100 pt-3 text-xs font-semibold uppercase text-slate-400">SMS provider</div>
            <div class="sm:col-span-2"><?= $checkbox('integration_sms_enabled', 'Enable outbound SMS notifications') ?></div>
            <?= $select('integration_sms_provider', 'SMS provider', ['none' => 'None', 'twilio' => 'Twilio', 'vonage' => 'Vonage (Nexmo)', 'bulksms' => 'BulkSMS'], ['required' => true]) ?>
            <?= $field('integration_sms_api_key', 'API key / SID', 'text') ?>
            <?= $field('integration_sms_sender_id', 'Sender ID', 'text') ?>
            <div class="sm:col-span-2"><?= $secret('integration_sms_api_secret', 'API secret / token') ?></div>
            <div class="sm:col-span-2 border-t border-slate-100 pt-3 text-xs font-semibold uppercase text-slate-400">Analytics</div>
            <?= $field('integration_google_analytics_id', 'Google Analytics ID (optional)', 'text', ['help' => 'e.g. G-XXXXXXX.']) ?>
            <?= $field('integration_facebook_pixel_id', 'Facebook Pixel ID (optional)', 'text', ['help' => 'e.g. 123456789012345.']) ?>
            <div class="sm:col-span-2 border-t border-slate-100 pt-3 text-xs font-semibold uppercase text-slate-400">Webhooks</div>
            <div class="sm:col-span-2"><?= $field('integration_webhook_url', 'Webhook URL (optional)', 'url', ['help' => 'Outbound POST on selected order events. Signed with the webhook secret.']) ?></div>
            <div class="sm:col-span-2"><?= $secret('integration_webhook_secret', 'Webhook signing secret') ?></div>
        <?php break; endswitch; ?>

    <div class="sm:col-span-2 flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
        <a href="<?= e(url('/admin/settings/system')) ?>" class="btn-secondary">Cancel</a>
        <button type="submit" class="btn-primary">Save <?= e($categoryLabel) ?> settings</button>
    </div>
</form>

<div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">
    <p class="font-semibold text-slate-700">Audit trail</p>
    <p class="mt-1">Every save fires <code>settings.<?= e($category) ?>_updated</code> into <code>audit_logs</code> with the changed plaintext keys + a list of secret keys that changed (no values). Cleared secrets are marked <code>(cleared)</code>.</p>
</div>
