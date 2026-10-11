<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Support\Database\Connection;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use PDO;

/**
 * Audit log viewer.
 *
 * The audit_logs table is append-only. This controller only ever
 * SELECTs — there are no INSERT, UPDATE, or DELETE routes here.
 * New events are written by AuditService::record() / app_audit()
 * from inside services and controllers, never from this controller.
 *
 * Features:
 *  - Full-text search (action, entity_type, user name, IP)
 *  - Category filter (auth, user, role, settings, inventory, ...)
 *  - Entity type filter
 *  - Actor (user) filter
 *  - Date range filter
 *  - Pagination
 *  - JSON export of filtered results
 */
final class AuditLogController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 50));

        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $entityType = trim((string) $request->query('entity_type', ''));
        $userId = (int) $request->query('user_id', 0);
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        $result = AuditService::search(
            q: $q,
            category: $category,
            entityType: $entityType,
            userId: $userId > 0 ? $userId : null,
            from: $from !== '' ? $from : null,
            to: $to !== '' ? $to : null,
            page: $page,
            perPage: $perPage,
        );

        return Response::html(View::render('admin/audit-logs/index', [
            'pageTitle' => 'Audit logs',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => $q,
            'category' => $category,
            'entityType' => $entityType,
            'userId' => $userId,
            'from' => $from,
            'to' => $to,
            'categories' => AuditService::categories(),
            'entityTypes' => $this->distinctEntityTypes(),
            'users' => (new UserRepository())->search('', 1, 200)['rows'],
        ]));
    }

    /**
     * Export filtered audit logs as JSON. Same filters as index().
     */
    public function export(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $entityType = trim((string) $request->query('entity_type', ''));
        $userId = (int) $request->query('user_id', 0);
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        $result = AuditService::search(
            q: $q,
            category: $category,
            entityType: $entityType,
            userId: $userId > 0 ? $userId : null,
            from: $from !== '' ? $from : null,
            to: $to !== '' ? $to : null,
            page: 1,
            perPage: 1000,
        );

        $payload = [
            'exported_at' => gmdate('c'),
            'filters' => array_filter([
                'q' => $q,
                'category' => $category,
                'entity_type' => $entityType,
                'user_id' => $userId > 0 ? $userId : null,
                'from' => $from,
                'to' => $to,
            ], fn ($v) => $v !== '' && $v !== null && $v !== 0),
            'total' => $result['total'],
            'events' => array_map(function (array $row) {
                return [
                    'id' => (int) $row['id'],
                    'when' => $row['created_at'],
                    'actor' => $row['user_name'] ?? 'system',
                    'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                    'action' => $row['action'],
                    'category' => AuditService::categoryForAction($row['action']),
                    'entity_type' => $row['entity_type'],
                    'entity_id' => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
                    'metadata' => $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null,
                    'ip' => $row['ip'],
                    'correlation_id' => $row['correlation_id'],
                ];
            }, $result['rows']),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return Response::text($json)->header('Content-Type', 'application/json; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="audit-logs-' . gmdate('Y-m-d') . '.json"');
    }

    /** @return list<string> */
    private function distinctEntityTypes(): array
    {
        $rows = Connection::pdo()->query(
            'SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'
        )->fetchAll(PDO::FETCH_COLUMN);
        return $rows;
    }
}
