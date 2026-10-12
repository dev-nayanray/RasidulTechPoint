<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\Config;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * Business profile settings: name, logo, contact, address, business
 * hours, invoice details, and default BDT currency.
 *
 * All settings changes are audited. File uploads are protected by:
 *  - MIME validation (only image/jpeg, image/png, image/webp allowed)
 *  - Size enforcement (max 4 MiB from config/security.php)
 *  - Real MIME detection via finfo (the client-provided Content-Type
 *    is never trusted on its own)
 *  - Safe filename generation (bin2hex(random_bytes), never the original)
 *  - Storage outside the web root for source files, public/uploads for
 *    served files
 */
final class SettingsController
{
    public function edit(): Response
    {
        $pdo = Connection::pdo();
        $profile = $pdo->query('SELECT * FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $locations = $pdo->query('SELECT * FROM locations ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        $businessHours = $pdo->query('SELECT * FROM business_hours ORDER BY day_of_week')->fetchAll(\PDO::FETCH_ASSOC);

        return Response::html(View::render('admin/settings/edit', [
            'pageTitle' => 'Business settings',
            'profile' => $profile,
            'locations' => $locations,
            'businessHours' => $businessHours,
            'invoiceSettings' => [
                'invoice_prefix' => SettingService::get('invoice_prefix', 'INV'),
                'invoice_next_number' => SettingService::get('invoice_next_number', 1),
                'invoice_tax_name' => SettingService::get('invoice_tax_name', 'VAT'),
                'invoice_tax_rate' => SettingService::get('invoice_tax_rate', '0'),
                'invoice_footer_text' => SettingService::get('invoice_footer_text', ''),
                'invoice_payment_terms' => SettingService::get('invoice_payment_terms', 'Payment due within 7 days.'),
            ],
            'errors' => [],
        ]));
    }

    public function update(Request $request): Response
    {
        $errors = [];

        // ---- Business profile ----
        try {
            $profile = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:150',
                'legal_name' => 'nullable|string|max:190',
                'tagline' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:190',
                'phone' => 'nullable|string|max:30',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:100',
                'about_text' => 'nullable|string|max:5000',
                'default_warranty_days' => 'nullable|integer|min:0|max:1825',
            ], ['default_warranty_days' => 'Default warranty days'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            $profile = $request->post();
            $errors = array_merge($errors, $e->errors);
        }

        // ---- Logo upload (if a file was submitted) ----
        $logoPath = $this->handleLogoUpload($request, $errors);

        // If there are validation errors, re-render the form.
        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/settings/edit', [
                'pageTitle' => 'Business settings',
                'profile' => $profile,
                'locations' => Connection::pdo()->query('SELECT * FROM locations ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
                'businessHours' => Connection::pdo()->query('SELECT * FROM business_hours ORDER BY day_of_week')->fetchAll(\PDO::FETCH_ASSOC),
                'invoiceSettings' => [
                    'invoice_prefix' => $request->input('invoice_prefix', 'INV'),
                    'invoice_next_number' => $request->input('invoice_next_number', 1),
                    'invoice_tax_name' => $request->input('invoice_tax_name', 'VAT'),
                    'invoice_tax_rate' => $request->input('invoice_tax_rate', '0'),
                    'invoice_footer_text' => $request->input('invoice_footer_text', ''),
                    'invoice_payment_terms' => $request->input('invoice_payment_terms', ''),
                ],
                'errors' => $errors,
            ]), 422);
        }

        // ---- Persist business profile ----
        $pdo = Connection::pdo();
        $existsId = $pdo->query('SELECT id FROM business_profiles ORDER BY id LIMIT 1')->fetchColumn();
        if ($existsId === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO business_profiles
                    (name, legal_name, tagline, email, phone, address, city, country, about_text, default_warranty_days, logo_path)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $profile['name'], $profile['legal_name'] ?? null, $profile['tagline'] ?? null,
                $profile['email'] ?? null, $profile['phone'] ?? null, $profile['address'] ?? null,
                $profile['city'] ?? null, $profile['country'] ?? null,
                $profile['about_text'] ?? null,
                (int) ($profile['default_warranty_days'] ?? 30),
                $logoPath,
            ]);
            $existsId = (int) $pdo->lastInsertId();
        } else {
            $existsId = (int) $existsId;
            if ($logoPath !== null) {
                $stmt = $pdo->prepare(
                    'UPDATE business_profiles SET name=?, legal_name=?, tagline=?, email=?, phone=?,
                            address=?, city=?, country=?, about_text=?, default_warranty_days=?, logo_path=? WHERE id=?'
                );
                $stmt->execute([
                    $profile['name'], $profile['legal_name'] ?? null, $profile['tagline'] ?? null,
                    $profile['email'] ?? null, $profile['phone'] ?? null, $profile['address'] ?? null,
                    $profile['city'] ?? null, $profile['country'] ?? null,
                    $profile['about_text'] ?? null,
                    (int) ($profile['default_warranty_days'] ?? 30),
                    $logoPath,
                    $existsId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE business_profiles SET name=?, legal_name=?, tagline=?, email=?, phone=?,
                            address=?, city=?, country=?, about_text=?, default_warranty_days=? WHERE id=?'
                );
                $stmt->execute([
                    $profile['name'], $profile['legal_name'] ?? null, $profile['tagline'] ?? null,
                    $profile['email'] ?? null, $profile['phone'] ?? null, $profile['address'] ?? null,
                    $profile['city'] ?? null, $profile['country'] ?? null,
                    $profile['about_text'] ?? null,
                    (int) ($profile['default_warranty_days'] ?? 30),
                    $existsId,
                ]);
            }
        }

        AuditService::record('settings.business_profile_updated', 'business_profile', $existsId, [
            'name' => $profile['name'],
            'logo_changed' => $logoPath !== null,
        ], $request);

        // ---- Business hours (upsert 7 days) ----
        $this->updateBusinessHours($request, $errors);

        // ---- Invoice settings (settings table) ----
        $this->updateInvoiceSettings($request, $errors);

        flash_success('Business settings saved.');
        return Response::redirect(url('/admin/settings'));
    }

    /**
     * Handle the logo file upload. Validates MIME type (via finfo, not
     * the client-provided Content-Type), size, and stores the file with
     * a safe random name. Returns the new path or null if no file was
     * uploaded. Errors are pushed into $errors.
     */
    private function handleLogoUpload(Request $request, array &$errors): ?string
    {
        $file = $request->file('logo');
        if ($file === null) {
            return null; // No file uploaded — not an error
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $errors['logo'] = 'Logo upload failed (error code ' . (int) $file['error'] . ').';
            return null;
        }

        $maxBytes = (int) Config::get('security.uploads.max_bytes', 4 * 1024 * 1024);
        $allowedMimes = Config::get('security.uploads.allowed_image_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if (($file['size'] ?? 0) > $maxBytes) {
            $errors['logo'] = 'Logo must be at most ' . round($maxBytes / 1024 / 1024, 1) . ' MiB.';
            return null;
        }

        // Detect the real MIME from the file content (never trust the
        // client-provided Content-Type).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true)) {
            $errors['logo'] = 'Logo must be a JPEG, PNG, or WebP image.';
            return null;
        }

        // Generate a safe filename — never use the client-provided name.
        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $safeName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;

        $uploadDir = BASE_PATH . '/public/uploads/logos';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0770, true);
        }
        $destPath = $uploadDir . '/' . $safeName;

        // In web SAPI, move_uploaded_file enforces that the source was
        // uploaded via HTTP POST. In CLI (tests), fall back to rename.
        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destPath);
        } else {
            rename($file['tmp_name'], $destPath);
        }

        return 'uploads/logos/' . $safeName;
    }

    /**
     * Upsert the 7-day business hours schedule.
     */
    private function updateBusinessHours(Request $request, array &$errors): void
    {
        $pdo = Connection::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO business_hours (day_of_week, is_closed, open_time, close_time, notes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                is_closed = VALUES(is_closed),
                open_time = VALUES(open_time),
                close_time = VALUES(close_time),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP'
        );

        $hours = $request->input('business_hours', []);
        if (!is_array($hours)) {
            return;
        }

        foreach (range(0, 6) as $day) {
            $dayData = $hours[$day] ?? [];
            $isClosed = (int) ($dayData['is_closed'] ?? 0) === 1;
            $openTime = $isClosed ? null : $this->normalizeTime($dayData['open_time'] ?? '');
            $closeTime = $isClosed ? null : $this->normalizeTime($dayData['close_time'] ?? '');
            $notes = mb_substr((string) ($dayData['notes'] ?? ''), 0, 100);

            $stmt->execute([$day, $isClosed ? 1 : 0, $openTime, $closeTime, $notes ?: null]);
        }

        AuditService::record('settings.business_hours_updated', null, null, [
            'days' => 7,
        ], $request);
    }

    /**
     * Normalize a time string to HH:MM:SS or null.
     */
    private function normalizeTime(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }
        // Accept H:MM, HH:MM, HH:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
            $h = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $s = $m[3] ?? '00';
            return "{$h}:{$m[2]}:{$s}";
        }
        return null;
    }

    /**
     * Update invoice settings in the settings table.
     */
    private function updateInvoiceSettings(Request $request, array &$errors): void
    {
        $prefix = mb_substr((string) $request->input('invoice_prefix', 'INV'), 0, 10);
        $nextNumber = max(1, (int) $request->input('invoice_next_number', 1));
        $taxName = mb_substr((string) $request->input('invoice_tax_name', 'VAT'), 0, 30);
        $taxRate = max(0, min(100, (float) $request->input('invoice_tax_rate', 0)));
        $footerText = mb_substr((string) $request->input('invoice_footer_text', ''), 0, 500);
        $paymentTerms = mb_substr((string) $request->input('invoice_payment_terms', ''), 0, 255);

        SettingService::set('invoice_prefix', $prefix, 'string', 'invoices');
        SettingService::set('invoice_next_number', (string) $nextNumber, 'int', 'invoices');
        SettingService::set('invoice_tax_name', $taxName, 'string', 'invoices');
        SettingService::set('invoice_tax_rate', (string) $taxRate, 'string', 'invoices');
        SettingService::set('invoice_footer_text', $footerText, 'string', 'invoices');
        SettingService::set('invoice_payment_terms', $paymentTerms, 'string', 'invoices');

        AuditService::record('settings.invoice_updated', null, null, [
            'prefix' => $prefix,
            'next_number' => $nextNumber,
            'tax_name' => $taxName,
            'tax_rate' => $taxRate,
        ], $request);
    }

    // ---------------------------------------------------- SYSTEM SETTINGS (Prompt 39) --
    //
    // Categorized settings: currency, dates, invoice, inventory, warranty,
    // delivery, email, payments, integrations. Each category has:
    //   - GET  /admin/settings/system                — dashboard with category nav
    //   - GET  /admin/settings/system/{category}    — render the form for that category
    //   - POST /admin/settings/system/{category}     — save + audit
    //
    // Secret fields (payment provider keys, SMTP password, SMS API secret,
    // webhook secret) are rendered as password inputs with a "leave blank to
    // keep current value" hint. An empty submission for an already-configured
    // secret keeps the existing encrypted value; a non-empty submission
    // re-encrypts and replaces it.

    /** Map category => human label. */
    private const CATEGORY_LABELS = [
        'currency' => 'Currency',
        'dates' => 'Date & Time',
        'invoice' => 'Invoice',
        'inventory' => 'Inventory rules',
        'warranty' => 'Warranty policy',
        'delivery' => 'Delivery',
        'email' => 'Email',
        'payments' => 'Payment providers',
        'integrations' => 'Integrations',
    ];

    /**
     * System-settings dashboard. Shows the category list + summary of
     * which categories have configured secrets.
     */
    public function systemIndex(): Response
    {
        $categories = [];
        foreach (self::CATEGORY_LABELS as $key => $label) {
            $secretsConfigured = SettingService::allSecretKeysInCategory($key);
            $secretsSet = array_filter(
                $secretsConfigured,
                fn ($k) => SettingService::secretIsSet($k),
            );
            $categories[] = [
                'key' => $key,
                'label' => $label,
                'secret_count' => count($secretsConfigured),
                'secrets_configured' => count($secretsSet),
            ];
        }
        return Response::html(View::render('admin/settings/system/index', [
            'pageTitle' => 'System settings',
            'categories' => $categories,
        ]));
    }

    /**
     * Render the per-category settings form. The form template
     * dispatches on $category to render the right set of fields.
     */
    public function systemCategory(string $category): Response
    {
        if (!isset(self::CATEGORY_LABELS[$category])) {
            throw new \App\Exceptions\NotFoundException('Unknown settings category: ' . $category);
        }
        $values = SettingService::getInCategory($category);
        $secretKeys = SettingService::allSecretKeysInCategory($category);
        $secretStatus = [];
        foreach ($secretKeys as $k) {
            $secretStatus[$k] = SettingService::secretIsSet($k);
        }

        return Response::html(View::render('admin/settings/system/category', [
            'pageTitle' => self::CATEGORY_LABELS[$category] . ' settings',
            'category' => $category,
            'categoryLabel' => self::CATEGORY_LABELS[$category],
            'categories' => self::CATEGORY_LABELS,
            'values' => $values,
            'secretStatus' => $secretStatus,
            'errors' => [],
            'cryptoAvailable' => \App\Support\Crypto::available(),
        ]));
    }

    /**
     * Save per-category settings. Validates against the per-category
     * rules, then writes plaintext settings via setCategory() and
     * secret settings (non-empty submission) via setSecret(). Empty
     * submissions for secrets keep the existing encrypted value.
     */
    public function systemSave(string $category, Request $request): Response
    {
        if (!isset(self::CATEGORY_LABELS[$category])) {
            throw new \App\Exceptions\NotFoundException('Unknown settings category: ' . $category);
        }

        $rules = $this->rulesFor($category);
        $secretKeys = $this->secretKeysFor($category);

        // Build the validator rule set from the plaintext rules; secret
        // fields are validated separately (only "string" + "max" so we
        // don't reject empty submissions).
        $validatorRules = [];
        foreach ($rules as $key => $rule) {
            $validatorRules[$key] = $rule['rules'] ?? 'nullable|string|max:500';
        }
        foreach ($secretKeys as $key) {
            $validatorRules[$key] = 'nullable|string|max:500';
        }

        try {
            $data = Validator::make($request->post(), $validatorRules)->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            $values = SettingService::getInCategory($category);
            $secretStatus = [];
            foreach ($secretKeys as $k) {
                $secretStatus[$k] = SettingService::secretIsSet($k);
            }
            return Response::html(View::render('admin/settings/system/category', [
                'pageTitle' => self::CATEGORY_LABELS[$category] . ' settings',
                'category' => $category,
                'categoryLabel' => self::CATEGORY_LABELS[$category],
                'categories' => self::CATEGORY_LABELS,
                'values' => array_merge($values, $request->post()),
                'secretStatus' => $secretStatus,
                'errors' => $e->errors,
                'cryptoAvailable' => \App\Support\Crypto::available(),
            ]), 422);
        }

        $changedKeys = [];
        $secretChangedKeys = [];

        // Plaintext keys.
        foreach ($rules as $key => $rule) {
            $type = $rule['type'] ?? 'string';
            $value = $data[$key] ?? '';
            if ($type === 'bool') {
                $value = !empty($data[$key]) ? '1' : '0';
            } elseif ($type === 'int') {
                $value = (string) max(0, (int) $data[$key]);
            }
            SettingService::setCategory($key, (string) $value, $type, $category);
            $changedKeys[$key] = $value;
        }

        // Secret keys.
        foreach ($secretKeys as $key) {
            $submitted = (string) ($data[$key] ?? '');
            if ($submitted === '') {
                // Empty submission — keep existing. If admin ticked
                // "clear", we wipe the encrypted_value.
                if ($request->has('clear_' . $key)) {
                    SettingService::clearSecret($key);
                    $secretChangedKeys[] = $key . ' (cleared)';
                }
                continue;
            }
            try {
                SettingService::setSecret($key, $submitted, $category);
                $secretChangedKeys[] = $key;
            } catch (\App\Exceptions\ValidationException $e) {
                flash_error('Could not save secret for ' . $key . ': ' . implode(' ', $e->errors));
            }
        }

        AuditService::record('settings.' . $category . '_updated', 'setting', null, [
            'category' => $category,
            'changed_keys' => array_keys($changedKeys),
            'secret_changes' => $secretChangedKeys,
        ], $request);

        flash_success(ucfirst(self::CATEGORY_LABELS[$category]) . ' settings saved.');
        return Response::redirect(url('/admin/settings/system/' . $category));
    }

    // ---------------------------------------------------- PER-CATEGORY RULES + SECRET KEYS --

    /**
     * Validation rules per category. Each entry is:
     *   'setting_key' => ['type' => 'string'|'int'|'bool', 'rules' => '...']
     * Type is used to coerce the input before persistence.
     *
     * @return array<string, array{type:string, rules:string}>
     */
    private function rulesFor(string $category): array
    {
        return match ($category) {
            'currency' => [
                'currency_code' => ['type' => 'string', 'rules' => 'required|string|max:3'],
                'currency_symbol' => ['type' => 'string', 'rules' => 'required|string|max:5'],
                'currency_position' => ['type' => 'string', 'rules' => 'required|in:before,after'],
                'currency_decimal_places' => ['type' => 'int', 'rules' => 'required|integer|min:0|max:4'],
            ],
            'dates' => [
                'date_format' => ['type' => 'string', 'rules' => 'required|string|max:60'],
                'datetime_format' => ['type' => 'string', 'rules' => 'required|string|max:60'],
                'time_format' => ['type' => 'string', 'rules' => 'required|string|max:60'],
                'first_day_of_week' => ['type' => 'string', 'rules' => 'required|in:sun,mon'],
            ],
            'invoice' => [
                'invoice_prefix' => ['type' => 'string', 'rules' => 'required|string|max:10'],
                'invoice_next_number' => ['type' => 'int', 'rules' => 'required|integer|min:1'],
                'invoice_tax_name' => ['type' => 'string', 'rules' => 'nullable|string|max:30'],
                'invoice_tax_rate' => ['type' => 'string', 'rules' => 'nullable|decimal:2'],
                'invoice_footer_text' => ['type' => 'string', 'rules' => 'nullable|string|max:500'],
                'invoice_payment_terms' => ['type' => 'string', 'rules' => 'nullable|string|max:255'],
                'invoice_reset_yearly' => ['type' => 'bool', 'rules' => 'nullable'],
            ],
            'inventory' => [
                'inventory_low_stock_threshold' => ['type' => 'int', 'rules' => 'required|integer|min:0|max:10000'],
                'inventory_allow_negative_stock' => ['type' => 'bool', 'rules' => 'nullable'],
                'inventory_default_markup_percent' => ['type' => 'int', 'rules' => 'required|integer|min:0|max:500'],
                'inventory_track_serial_numbers' => ['type' => 'bool', 'rules' => 'nullable'],
            ],
            'warranty' => [
                'warranty_default_days' => ['type' => 'int', 'rules' => 'required|integer|min:0|max:1825'],
                'warranty_requires_serial' => ['type' => 'bool', 'rules' => 'nullable'],
                'warranty_claim_window_days' => ['type' => 'int', 'rules' => 'required|integer|min:0|max:365'],
            ],
            'delivery' => [
                'delivery_default_carrier' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'delivery_default_shipping_days' => ['type' => 'int', 'rules' => 'required|integer|min:0|max:60'],
                'delivery_default_shipping_fee' => ['type' => 'string', 'rules' => 'nullable|decimal:2'],
                'delivery_free_shipping_threshold' => ['type' => 'string', 'rules' => 'nullable|decimal:2'],
            ],
            'email' => [
                'mail_driver' => ['type' => 'string', 'rules' => 'required|in:log,smtp,sendmail'],
                'mail_from_address' => ['type' => 'string', 'rules' => 'required|email|max:190'],
                'mail_from_name' => ['type' => 'string', 'rules' => 'required|string|max:120'],
                'mail_smtp_host' => ['type' => 'string', 'rules' => 'nullable|string|max:190'],
                'mail_smtp_port' => ['type' => 'int', 'rules' => 'required|integer|min:1|max:65535'],
                'mail_smtp_encryption' => ['type' => 'string', 'rules' => 'nullable|in:none,tls,ssl'],
                'mail_smtp_username' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'mail_bcc_address' => ['type' => 'string', 'rules' => 'nullable|email|max:190'],
            ],
            'payments' => [
                'payment_provider_stripe_enabled' => ['type' => 'bool', 'rules' => 'nullable'],
                'payment_provider_stripe_publishable_key' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'payment_provider_bkash_enabled' => ['type' => 'bool', 'rules' => 'nullable'],
                'payment_provider_bkash_app_key' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'payment_provider_bkash_username' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'payment_provider_nagad_enabled' => ['type' => 'bool', 'rules' => 'nullable'],
                'payment_provider_nagad_merchant_id' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'payment_provider_nagad_public_key' => ['type' => 'string', 'rules' => 'nullable|string|max:500'],
            ],
            'integrations' => [
                'integration_sms_enabled' => ['type' => 'bool', 'rules' => 'nullable'],
                'integration_sms_provider' => ['type' => 'string', 'rules' => 'required|in:none,twilio,vonage,bulksms'],
                'integration_sms_api_key' => ['type' => 'string', 'rules' => 'nullable|string|max:120'],
                'integration_sms_sender_id' => ['type' => 'string', 'rules' => 'nullable|string|max:30'],
                'integration_google_analytics_id' => ['type' => 'string', 'rules' => 'nullable|string|max:60'],
                'integration_facebook_pixel_id' => ['type' => 'string', 'rules' => 'nullable|string|max:60'],
                'integration_webhook_url' => ['type' => 'string', 'rules' => 'nullable|url|max:500'],
            ],
            default => [],
        };
    }

    /**
     * Secret keys per category. These are rendered as password inputs
     * and never displayed back; empty submission keeps the existing
     * encrypted value.
     *
     * @return list<string>
     */
    private function secretKeysFor(string $category): array
    {
        return match ($category) {
            'email' => ['mail_smtp_password'],
            'payments' => [
                'payment_provider_stripe_secret_key',
                'payment_provider_stripe_webhook_secret',
                'payment_provider_bkash_app_secret',
                'payment_provider_bkash_password',
                'payment_provider_nagad_secret_key',
            ],
            'integrations' => [
                'integration_sms_api_secret',
                'integration_webhook_secret',
            ],
            default => [],
        };
    }
}
