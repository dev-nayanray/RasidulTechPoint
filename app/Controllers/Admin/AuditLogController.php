<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use PDO;

final class AuditLogController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 50));
        $pdo = Connection::pdo();

        $where = ' WHERE 1=1';
        $params = [];
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $where .= ' AND (a.action LIKE ? OR a.entity_type LIKE ? OR u.name LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            "SELECT a.*, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             {$where}
             ORDER BY a.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return Response::html(View::render('admin/audit-logs/index', [
            'pageTitle' => 'Audit logs',
            'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'q' => $q,
        ]));
    }
}
