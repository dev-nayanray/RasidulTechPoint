<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\DashboardService;
use App\Support\Response;
use App\Support\View;

final class DashboardController
{
    public function index(): Response
    {
        $service = new DashboardService();

        return Response::html(View::render('admin/dashboard', [
            'pageTitle' => 'Dashboard',
            'metrics' => $service->metrics(),
            'recentOrders' => $service->recentOrders(),
            'recentMovements' => $service->recentMovements(),
            'recentAudit' => $service->recentAudit(),
            'lowStock' => $service->lowStockAlerts(),
        ]));
    }
}
