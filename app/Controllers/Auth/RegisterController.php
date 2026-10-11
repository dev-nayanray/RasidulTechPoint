<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Repositories\CustomerRepository;
use App\Services\AuditService;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class RegisterController
{
    public function show(): Response
    {
        return Response::html(View::render('auth/register', [
            'pageTitle' => 'Create account',
        ], layout: 'layouts/guest'));
    }

    /**
     * Storefront customer registration: creates a user with the customer
     * role plus a linked customer profile, then signs the user in.
     */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->post(), [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'phone' => 'required|string|min:6|max:30|unique:customers,phone',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'password' => 'required|string|min:10|confirmed|max:500',
        ], [
            'email' => 'Email address',
            'phone' => 'Phone number',
        ])->validate();

        $userId = Connection::transaction(function ($pdo) use ($data) {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
            $stmt->execute([$data['name'], strtolower(trim($data['email'])), password_hash($data['password'], PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();

            $role = $pdo->prepare("SELECT id FROM roles WHERE slug = 'customer'");
            $role->execute();
            $roleId = $role->fetchColumn();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
                ->execute([$userId, (int) $roleId]);

            (new CustomerRepository())->create($data, $userId);
            return $userId;
        });

        Session::regenerate();
        Session::set('user_id', $userId);
        AuditService::record('auth.registered', 'user', $userId, [], $request);

        flash_success('Welcome to Rasidul Tech Point! Your account is ready.');
        return Response::redirect(url('/account'));
    }
}
