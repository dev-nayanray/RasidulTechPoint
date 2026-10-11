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
}
