<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\NotificationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

final class NotificationController
{
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
}
