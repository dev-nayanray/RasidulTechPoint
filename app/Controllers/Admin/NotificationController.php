<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * In-app notifications + template management + delivery log viewer.
 *
 * Permission map:
 *  - notifications.view   : own notifications (index, markRead, markAllRead)
 *  - notifications.manage : templates CRUD, delivery log viewer, retry
 *
 * Privacy:
 *  - The own-notifications endpoints scope reads/markRead by the
 *    signed-in user's id; another user's notifications are never
 *    exposed and a forged notification id from another user is
 *    silently rejected (rowCount stays 0).
 *  - The delivery log viewer is admin-only (notifications.manage)
 *    and never surfaces notification body content — only event_key,
 *    status, recipient name, and timestamps.
 */
final class NotificationController
{
    // ---------------------------------------------------- OWN NOTIFICATIONS --

    public function index(): Response
    {
        return Response::html(View::render('admin/notifications/index', [
            'pageTitle' => 'Notifications',
            'notifications' => NotificationService::forUser(current_user_id(), 50),
        ]));
    }

    public function markRead(string $id): Response
    {
        NotificationService::markRead(current_user_id(), (int) $id);
        flash_success('Notification marked as read.');
        return Response::redirect(url('/admin/notifications'));
    }

    public function markAllRead(): Response
    {
        NotificationService::markAllRead(current_user_id());
        flash_success('All notifications marked as read.');
        return Response::redirect(url('/admin/notifications'));
    }

    // ---------------------------------------------------- TEMPLATES --

    public function templateIndex(): Response
    {
        return Response::html(View::render('admin/notifications/templates/index', [
            'pageTitle' => 'Notification Templates',
            'templates' => NotificationService::allTemplates(),
            'stats' => NotificationService::deliveryStats(),
        ]));
    }

    public function templateCreate(): Response
    {
        return Response::html(View::render('admin/notifications/templates/form', [
            'pageTitle' => 'New notification template',
            'template' => null,
            'errors' => [],
            'modes' => NotificationService::RECIPIENT_MODES,
        ]));
    }

    public function templateStore(Request $request): Response
    {
        try {
            $data = $this->templateInput($request);
        } catch (ValidationException $e) {
            return Response::html(View::render('admin/notifications/templates/form', [
                'pageTitle' => 'New notification template',
                'template' => $request->post(),
                'errors' => $e->errors,
                'modes' => NotificationService::RECIPIENT_MODES,
            ]), 422);
        }

        try {
            $id = NotificationService::saveTemplate($data, null, current_user_id());
            flash_success('Template created.');
            AuditService::record('notification.template_created', 'notification_template', $id, [
                'event_key' => $data['event_key'],
            ], $request);
            return Response::redirect(url('/admin/notifications/templates/' . $id));
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/notifications/templates/create'));
        }
    }

    public function templateShow(string $id): Response
    {
        $template = NotificationService::findTemplate((int) $id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        $deliveries = NotificationService::deliveries(eventKey: (string) $template['event_key'], page: 1, perPage: 100);
        return Response::html(View::render('admin/notifications/templates/show', [
            'pageTitle' => 'Template: ' . $template['event_key'],
            'template' => $template,
            'deliveries' => $deliveries,
        ]));
    }

    public function templateEdit(string $id): Response
    {
        $template = NotificationService::findTemplate((int) $id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        return Response::html(View::render('admin/notifications/templates/form', [
            'pageTitle' => 'Edit template',
            'template' => $template,
            'errors' => [],
            'modes' => NotificationService::RECIPIENT_MODES,
        ]));
    }

    public function templateUpdate(string $id, Request $request): Response
    {
        $template = NotificationService::findTemplate((int) $id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        try {
            $data = $this->templateInput($request);
        } catch (ValidationException $e) {
            return Response::html(View::render('admin/notifications/templates/form', [
                'pageTitle' => 'Edit template',
                'template' => array_merge($template, $request->post()),
                'errors' => $e->errors,
                'modes' => NotificationService::RECIPIENT_MODES,
            ]), 422);
        }

        try {
            NotificationService::saveTemplate($data, (int) $id, current_user_id());
            flash_success('Template updated.');
            AuditService::record('notification.template_updated', 'notification_template', (int) $id, [
                'event_key' => $template['event_key'],
            ], $request);
        } catch (ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/notifications/templates/' . $id));
    }

    public function templateToggle(string $id, Request $request): Response
    {
        $template = NotificationService::findTemplate((int) $id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        $enabled = (bool) $request->input('is_enabled', 0);
        NotificationService::toggleTemplate((int) $id, $enabled);
        AuditService::record('notification.template_toggled', 'notification_template', (int) $id, [
            'enabled' => $enabled,
        ], $request);
        flash_success($enabled ? 'Template enabled.' : 'Template disabled.');
        return Response::redirect(url('/admin/notifications/templates/' . $id));
    }

    public function templateDelete(string $id, Request $request): Response
    {
        $template = NotificationService::findTemplate((int) $id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        NotificationService::deleteTemplate((int) $id);
        AuditService::record('notification.template_deleted', 'notification_template', (int) $id, [
            'event_key' => $template['event_key'],
        ], $request);
        flash_success('Template deleted. Delivery history is preserved.');
        return Response::redirect(url('/admin/notifications/templates'));
    }

    // ---------------------------------------------------- DELIVERY LOG --

    public function deliveryIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 50));
        $status = (string) $request->query('status', '');
        $eventKey = (string) $request->query('event_key', '');

        $deliveries = NotificationService::deliveries($status, $eventKey, $page, $perPage);
        $total = NotificationService::deliveriesCount($status, $eventKey);

        return Response::html(View::render('admin/notifications/deliveries/index', [
            'pageTitle' => 'Delivery Log',
            'deliveries' => $deliveries,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'status' => $status,
            'eventKey' => $eventKey,
            'statuses' => NotificationService::DELIVERY_STATUSES,
            'stats' => NotificationService::deliveryStats(),
        ]));
    }

    public function retryFailed(Request $request): Response
    {
        $max = max(1, min(10, (int) $request->input('max_attempts', NotificationService::DEFAULT_MAX_ATTEMPTS)));
        $result = NotificationService::retryFailed($max);
        flash_success(sprintf(
            'Retry run: %d retried, %d succeeded, %d still failing.',
            $result['retried'],
            $result['succeeded'],
            $result['still_failed'],
        ));
        AuditService::record('notification.retry_run', null, null, $result, $request);
        return Response::redirect(url('/admin/notifications/deliveries?status=failed'));
    }

    // ---------------------------------------------------- HELPERS --

    private function templateInput(Request $request): array
    {
        $post = $request->post();
        return [
            'event_key' => (string) ($post['event_key'] ?? ''),
            'title' => (string) ($post['title'] ?? ''),
            'body' => ($post['body'] ?? '') === '' ? null : (string) $post['body'],
            'link_template' => ($post['link_template'] ?? '') === '' ? null : (string) $post['link_template'],
            'recipient_mode' => (string) ($post['recipient_mode'] ?? 'roles'),
            'recipient_value' => (string) ($post['recipient_value'] ?? ''),
            'is_enabled' => !empty($post['is_enabled']),
            'dedupe_window_seconds' => (int) ($post['dedupe_window_seconds'] ?? 0),
        ];
    }
}
