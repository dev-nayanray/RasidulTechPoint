<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Repositories\CourierRepository;
use App\Repositories\OrderRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\DeliveryService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Delivery and courier management.
 *
 * Routes (under /admin prefix, all auth + CSRF gated):
 *   GET  /admin/delivery                                dashboard
 *   GET  /admin/delivery/couriers                       courier list
 *   GET  /admin/delivery/couriers/create                create form
 *   POST /admin/delivery/couriers                       store courier
 *   GET  /admin/delivery/couriers/{id}                  show courier
 *   GET  /admin/delivery/couriers/{id}/edit             edit form
 *   POST /admin/delivery/couriers/{id}/update           update
 *   POST /admin/delivery/couriers/{id}/delete           soft-delete
 *   POST /admin/delivery/couriers/{id}/restore           restore
 *   GET  /admin/delivery/zones                          zone list
 *   POST /admin/delivery/zones                          create zone
 *   POST /admin/delivery/zones/{id}/update              update zone
 *   POST /admin/delivery/zones/{id}/delete              delete zone
 *   GET  /admin/delivery/shipments                      shipment list
 *   GET  /admin/delivery/shipments/{id}                 show shipment
 *   POST /admin/delivery/shipments                      create shipment
 *   POST /admin/delivery/shipments/{id}/dispatch        dispatch
 *   POST /admin/delivery/shipments/{id}/transit         mark in transit
 *   POST /admin/delivery/shipments/{id}/deliver         mark delivered
 *   POST /admin/delivery/shipments/{id}/fail            mark failed
 *   POST /admin/delivery/shipments/{id}/return          return to origin
 *   POST /admin/delivery/shipments/{id}/cancel          cancel
 *
 * Permission: delivery.manage for all routes.
 */
final class DeliveryController
{
    // ---------------------------------------------------- DASHBOARD --

    public function index(): Response
    {
        $svc = new DeliveryService();
        $courierRepo = new CourierRepository();

        // Summary counts for the dashboard.
        $pdo = \App\Support\Database\Connection::pdo();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM shipments WHERE status IN ('pending','dispatched','in_transit')")->fetchColumn();
        $delivered = (int) $pdo->query("SELECT COUNT(*) FROM shipments WHERE status = 'delivered'")->fetchColumn();
        $failed = (int) $pdo->query("SELECT COUNT(*) FROM shipments WHERE status = 'failed'")->fetchColumn();
        $returned = (int) $pdo->query("SELECT COUNT(*) FROM shipments WHERE status = 'returned_to_origin'")->fetchColumn();

        $recent = $svc->searchShipments('', '', null, 1, 10)['rows'];

        return Response::html(View::render('admin/delivery/dashboard', [
            'pageTitle' => 'Courier & Delivery',
            'pending' => $pending,
            'delivered' => $delivered,
            'failed' => $failed,
            'returned' => $returned,
            'recent' => $recent,
            'couriers' => $courierRepo->search('', 1, 5)['rows'],
        ]));
    }

    // ---------------------------------------------------- COURIERS --

    public function courierIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $result = (new CourierRepository())->search(
            trim((string) $request->query('q', '')),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/delivery/couriers/index', [
            'pageTitle' => 'Couriers',
            'couriers' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
        ]));
    }

    public function courierCreate(): Response
    {
        return Response::html(View::render('admin/delivery/couriers/form', [
            'pageTitle' => 'Add courier',
            'courier' => null,
            'errors' => [],
        ]));
    }

    public function courierStore(Request $request): Response
    {
        $errors = $this->validateCourier($request, null);
        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/delivery/couriers/form', [
                'pageTitle' => 'Add courier',
                'courier' => $request->post(),
                'errors' => $errors,
            ]), 422);
        }

        $data = $request->post();
        $repo = new CourierRepository();
        $slug = str_slug($data['name']);
        if ($repo->slugExists($slug)) {
            $slug = $slug . '-' . bin2hex(random_bytes(2));
        }
        $data['slug'] = $slug;
        $id = $repo->create($data);
        AuditService::record('courier.created', 'courier', $id, ['name' => $data['name']], $request);
        flash_success('Courier created.');
        return Response::redirect(url('/admin/delivery/couriers/' . $id));
    }

    public function courierShow(string $id): Response
    {
        $repo = new CourierRepository();
        $courier = $repo->findWithTrashed((int) $id);
        if ($courier === null) {
            throw new NotFoundException('Courier not found.');
        }
        $zones = \App\Support\Database\Connection::pdo()->prepare(
            'SELECT * FROM delivery_zones WHERE courier_id = ? ORDER BY name'
        );
        $zones->execute([(int) $id]);

        // Recent shipments for this courier.
        $shipments = \App\Support\Database\Connection::pdo()->prepare(
            'SELECT s.*, o.reference AS order_reference
             FROM shipments s JOIN orders o ON o.id = s.order_id
             WHERE s.courier_id = ?
             ORDER BY s.id DESC LIMIT 20'
        );
        $shipments->execute([(int) $id]);

        return Response::html(View::render('admin/delivery/couriers/show', [
            'pageTitle' => $courier['name'],
            'courier' => $courier,
            'zones' => $zones->fetchAll(\PDO::FETCH_ASSOC),
            'shipments' => $shipments->fetchAll(\PDO::FETCH_ASSOC),
        ]));
    }

    public function courierEdit(string $id): Response
    {
        $courier = (new CourierRepository())->find((int) $id);
        if ($courier === null) {
            throw new NotFoundException('Courier not found.');
        }
        return Response::html(View::render('admin/delivery/couriers/form', [
            'pageTitle' => 'Edit: ' . $courier['name'],
            'courier' => $courier,
            'errors' => [],
        ]));
    }

    public function courierUpdate(string $id, Request $request): Response
    {
        $repo = new CourierRepository();
        $courier = $repo->find((int) $id);
        if ($courier === null) {
            throw new NotFoundException('Courier not found.');
        }
        $errors = $this->validateCourier($request, (int) $id);
        if ($errors !== []) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/delivery/couriers/form', [
                'pageTitle' => 'Edit: ' . $courier['name'],
                'courier' => array_merge($courier, $request->post()),
                'errors' => $errors,
            ]), 422);
        }
        $repo->update((int) $id, $request->post());
        AuditService::record('courier.updated', 'courier', (int) $id, ['name' => $request->post()['name']], $request);
        flash_success('Courier updated.');
        return Response::redirect(url('/admin/delivery/couriers/' . $id));
    }

    public function courierDelete(string $id, Request $request): Response
    {
        $repo = new CourierRepository();
        $courier = $repo->find((int) $id);
        if ($courier === null) {
            throw new NotFoundException('Courier not found.');
        }
        $repo->softDelete((int) $id);
        AuditService::record('courier.deleted', 'courier', (int) $id, ['name' => $courier['name']], $request);
        flash_success('Courier deleted (soft delete — history preserved).');
        return Response::redirect(url('/admin/delivery/couriers'));
    }

    public function courierRestore(string $id, Request $request): Response
    {
        $repo = new CourierRepository();
        $courier = $repo->findWithTrashed((int) $id);
        if ($courier === null) {
            throw new NotFoundException('Courier not found.');
        }
        $repo->restore((int) $id);
        AuditService::record('courier.restored', 'courier', (int) $id, ['name' => $courier['name']], $request);
        flash_success('Courier restored.');
        return Response::redirect(url('/admin/delivery/couriers/' . $id));
    }

    /** @return array<string,string> */
    private function validateCourier(Request $request, ?int $excludeId): array
    {
        $errors = [];
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) < 2) {
            $errors['name'] = 'Name is required (min 2 characters).';
        }
        $email = trim((string) $request->input('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        return $errors;
    }

    // ---------------------------------------------------- ZONES --

    public function zoneIndex(): Response
    {
        $repo = new CourierRepository();
        return Response::html(View::render('admin/delivery/zones/index', [
            'pageTitle' => 'Delivery zones',
            'zones' => $repo->allZones(),
            'couriers' => $repo->all(),
        ]));
    }

    public function zoneStore(Request $request): Response
    {
        $data = $request->post();
        $repo = new CourierRepository();
        $id = $repo->createZone($data);
        AuditService::record('delivery.zone_created', 'delivery_zone', $id, ['name' => $data['name'] ?? ''], $request);
        flash_success('Delivery zone created.');
        return Response::redirect(url('/admin/delivery/zones'));
    }

    public function zoneUpdate(string $id, Request $request): Response
    {
        $repo = new CourierRepository();
        if ($repo->findZone((int) $id) === null) {
            throw new NotFoundException('Delivery zone not found.');
        }
        $repo->updateZone((int) $id, $request->post());
        AuditService::record('delivery.zone_updated', 'delivery_zone', (int) $id, [], $request);
        flash_success('Delivery zone updated.');
        return Response::redirect(url('/admin/delivery/zones'));
    }

    public function zoneDelete(string $id, Request $request): Response
    {
        $repo = new CourierRepository();
        $repo->deleteZone((int) $id);
        AuditService::record('delivery.zone_deleted', 'delivery_zone', (int) $id, [], $request);
        flash_success('Delivery zone deleted.');
        return Response::redirect(url('/admin/delivery/zones'));
    }

    // ---------------------------------------------------- SHIPMENTS --

    public function shipmentIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new DeliveryService();
        $courierId = $request->query('courier', 0) !== null ? (int) $request->query('courier', 0) : null;
        $result = $svc->searchShipments(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $courierId,
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/delivery/shipments/index', [
            'pageTitle' => 'Shipments',
            'shipments' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'courierId' => $courierId ?? 0,
            'statuses' => DeliveryService::SHIPMENT_STATUSES,
            'couriers' => (new CourierRepository())->all(),
        ]));
    }

    public function shipmentShow(string $id): Response
    {
        $svc = new DeliveryService();
        $shipment = $svc->findShipment((int) $id);
        if ($shipment === null) {
            throw new NotFoundException('Shipment not found.');
        }
        return Response::html(View::render('admin/delivery/shipments/show', [
            'pageTitle' => 'Shipment ' . $shipment['shipment_number'],
            'shipment' => $shipment,
            'couriers' => (new CourierRepository())->all(),
        ]));
    }

    public function shipmentCreateForm(string $orderId): Response
    {
        $order = (new OrderRepository())->find((int) $orderId);
        if ($order === null) {
            throw new NotFoundException('Order not found.');
        }
        $repo = new CourierRepository();
        return Response::html(View::render('admin/delivery/shipments/create', [
            'pageTitle' => 'Create shipment for ' . $order['reference'],
            'order' => $order,
            'couriers' => $repo->all(),
            'zones' => $repo->allZones(),
            'errors' => [],
        ]));
    }

    public function shipmentStore(Request $request): Response
    {
        $data = $request->post();
        try {
            $svc = new DeliveryService();
            $result = $svc->createShipment(
                (int) $data['order_id'],
                !empty($data['courier_id']) ? (int) $data['courier_id'] : null,
                !empty($data['delivery_zone_id']) ? (int) $data['delivery_zone_id'] : null,
                (float) ($data['fee'] ?? 0),
                (string) ($data['recipient_name'] ?? ''),
                (string) ($data['recipient_phone'] ?? ''),
                (string) ($data['delivery_address'] ?? ''),
                $data['notes'] ?? null,
                current_user_id(),
            );
            flash_success('Shipment ' . $result['shipment_number'] . ' created.');
            return Response::redirect(url('/admin/delivery/shipments/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            $order = (new OrderRepository())->find((int) $data['order_id']);
            $repo = new CourierRepository();
            Session::flashInput($data);
            return Response::html(View::render('admin/delivery/shipments/create', [
                'pageTitle' => 'Create shipment',
                'order' => $order,
                'couriers' => $repo->all(),
                'zones' => $repo->allZones(),
                'errors' => $e->errors,
            ]), 422);
        }
    }

    public function shipmentDispatch(string $id, Request $request): Response
    {
        try {
            $svc = new DeliveryService();
            $result = $svc->dispatch(
                (int) $id,
                (int) $request->input('courier_id', 0),
                $request->input('tracking_reference') ?: null,
                $request->input('tracking_url') ?: null,
                current_user_id(),
            );
            flash_success('Shipment dispatched.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/delivery/shipments/' . $id));
    }

    public function shipmentTransit(string $id): Response
    {
        try {
            (new DeliveryService())->markInTransit((int) $id);
            flash_success('Shipment marked as in transit.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/delivery/shipments/' . $id));
    }

    public function shipmentDeliver(string $id, Request $request): Response
    {
        try {
            (new DeliveryService())->markDelivered(
                (int) $id,
                $request->input('proof_of_delivery') ?: null,
            );
            flash_success('Shipment marked as delivered.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/delivery/shipments/' . $id));
    }

    public function shipmentFail(string $id, Request $request): Response
    {
        try {
            (new DeliveryService())->markFailed(
                (int) $id,
                (string) $request->input('failure_reason', ''),
            );
            flash_success('Shipment marked as failed.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/delivery/shipments/' . $id));
    }

    public function shipmentReturn(string $id, Request $request): Response
    {
        try {
            (new DeliveryService())->returnToOrigin(
                (int) $id,
                $request->input('reason') ?: null,
            );
            flash_success('Shipment marked as returned to origin.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/delivery/shipments/' . $id));
    }

    public function shipmentCancel(string $id): Response
    {
        try {
            (new DeliveryService())->cancel((int) $id);
            flash_success('Shipment cancelled.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/delivery/shipments/' . $id));
    }
}
