<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\CustomerRepository;
use App\Repositories\OrderRepository;
use App\Services\AuditService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;
use App\Support\View;

/**
 * Customer self-service area. Customers only ever see their own data —
 * orders are fetched scoped by the authenticated user's customer record.
 */
final class AccountController
{
    public function index(): Response
    {
        $customer = (new CustomerRepository())->findByUserId(current_user_id());

        return Response::html(View::render('account/dashboard', [
            'pageTitle' => 'My account',
            'customer' => $customer,
            'orders' => $customer ? (new OrderRepository())->forCustomer((int) $customer['id'], 10) : [],
        ]));
    }

    public function orders(): Response
    {
        $customer = (new CustomerRepository())->findByUserId(current_user_id());
        $orders = $customer ? (new OrderRepository())->forCustomer((int) $customer['id'], 50) : [];

        return Response::html(View::render('account/orders', [
            'pageTitle' => 'My orders',
            'orders' => $orders,
        ]));
    }

    public function order(string $reference): Response
    {
        $customer = (new CustomerRepository())->findByUserId(current_user_id());
        if ($customer === null) {
            throw new NotFoundException('Order not found.');
        }

        // IDOR guard: lookup is scoped to the signed-in customer's own id.
        $order = (new OrderRepository())->findByReferenceForCustomer($reference, (int) $customer['id']);
        if ($order === null) {
            throw new NotFoundException('Order not found.');
        }

        return Response::html(View::render('account/order-show', [
            'pageTitle' => 'Order ' . $order['reference'],
            'order' => $order,
        ]));
    }

    public function profile(): Response
    {
        return Response::html(View::render('account/profile', [
            'pageTitle' => 'Profile',
            'customer' => (new CustomerRepository())->findByUserId(current_user_id()),
        ]));
    }

    public function updateProfile(Request $request): Response
    {
        $data = Validator::make($request->post(), [
            'name' => 'required|string|min:2|max:120',
            'phone' => 'required|string|min:6|max:30',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
        ], ['phone' => 'Phone number'])->validate();

        $repo = new CustomerRepository();
        $customer = $repo->findByUserId(current_user_id());

        if ($customer !== null) {
            $repo->update((int) $customer['id'], $data);
        } else {
            $repo->create($data + ['email' => current_user()['email']], current_user_id());
        }

        AuditService::record('account.profile_updated', 'user', current_user_id(), [], $request);
        flash_success('Profile updated.');
        return Response::redirect(url('/account/profile'));
    }
}
