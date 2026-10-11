<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuditService;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class SettingsController
{
    public function edit(): Response
    {
        $pdo = Connection::pdo();
        $profile = $pdo->query('SELECT * FROM business_profiles ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $locations = $pdo->query('SELECT * FROM locations ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);

        return Response::html(View::render('admin/settings/edit', [
            'pageTitle' => 'Business settings',
            'profile' => $profile,
            'locations' => $locations,
            'errors' => [],
        ]));
    }

    public function update(Request $request): Response
    {
        try {
            $data = Validator::make($request->post(), [
                'name' => 'required|string|min:2|max:150',
                'legal_name' => 'nullable|string|max:190',
                'tagline' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:190',
                'phone' => 'nullable|string|max:30',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:100',
                'default_warranty_days' => 'nullable|integer|min:0|max:1825',
            ], ['default_warranty_days' => 'Default warranty days'])->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/settings/edit', [
                'pageTitle' => 'Business settings',
                'profile' => $request->post(),
                'locations' => Connection::pdo()->query('SELECT * FROM locations ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
                'errors' => $e->errors,
            ]), 422);
        }

        $pdo = Connection::pdo();
        $exists = $pdo->query('SELECT id FROM business_profiles ORDER BY id LIMIT 1')->fetchColumn();
        if ($exists === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO business_profiles
                    (name, legal_name, tagline, email, phone, address, city, country, default_warranty_days)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
        } else {
            $stmt = $pdo->prepare(
                'UPDATE business_profiles SET name=?, legal_name=?, tagline=?, email=?, phone=?,
                        address=?, city=?, country=?, default_warranty_days=? WHERE id=?'
            );
        }
        $params = [
            $data['name'], $data['legal_name'] ?? null, $data['tagline'] ?? null,
            $data['email'] ?? null, $data['phone'] ?? null, $data['address'] ?? null,
            $data['city'] ?? null, $data['country'] ?? null,
            (int) ($data['default_warranty_days'] ?? 30),
        ];
        $exists === false ? $stmt->execute($params) : $stmt->execute([...$params, (int) $exists]);

        AuditService::record('settings.business_profile_updated', 'business_profile', $exists === false ? null : (int) $exists, [
            'name' => $data['name'],
        ], $request);

        flash_success('Business settings saved.');
        return Response::redirect(url('/admin/settings'));
    }
}
