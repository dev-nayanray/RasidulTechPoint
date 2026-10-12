<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database\Connection;
use PDO;

/**
 * Data access for `contact_inquiries` (public contact-form submissions).
 *
 * Read/triage side for the admin Contact Inquiries section; rows are
 * created exclusively by ContactService::submit() on the public side —
 * this repository never inserts, it only reads and updates triage state
 * (status, internal note) or deletes spam on an authorized request.
 *
 * All user-supplied columns (name, email, subject, message…) are bound
 * as parameters and escaped with e() in the admin views.
 */
final class ContactInquiryRepository
{
    /** Status values allowed by the schema enum + UI. */
    public const STATUSES = ['new', 'responded', 'closed', 'spam'];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Connection::pdo();
    }

    /**
     * Paged, filtered inquiry list.
     *
     * @param array{q?:string,status?:string,from?:string,to?:string,sort?:string} $f
     * @return array{rows:list<array>,total:int}
     */
    public function search(array $f, int $page = 1, int $perPage = 20): array
    {
        $where = ' WHERE 1=1';
        $params = [];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (ci.inquiry_number LIKE ? OR ci.full_name LIKE ? OR ci.email LIKE ? OR ci.subject LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $status = (string) ($f['status'] ?? '');
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where .= ' AND ci.status = ?';
            $params[] = $status;
        }

        $from = (string) ($f['from'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $where .= ' AND ci.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = (string) ($f['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $where .= ' AND ci.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $order = ' ORDER BY ci.created_at DESC, ci.id DESC';
        $sort = (string) ($f['sort'] ?? '');
        if ($sort === 'oldest') {
            $order = ' ORDER BY created_at ASC, id ASC';
        }

        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM contact_inquiries ci{$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db()->prepare(
            "SELECT ci.*, ru.name AS responded_by_name
             FROM contact_inquiries ci
             LEFT JOIN users ru ON ru.id = ci.responded_by
             {$where}{$order}
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT ci.*, ru.name AS responded_by_name
             FROM contact_inquiries ci
             LEFT JOIN users ru ON ru.id = ci.responded_by
             WHERE ci.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Move an inquiry to a new status. `$status` must already be
     * allowlisted by the controller (defense in depth: re-checked here).
     * Marking `responded` stamps who/when for the audit trail.
     */
    public function updateStatus(int $id, string $status, int $userId): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = $this->db()->prepare(
            'UPDATE contact_inquiries
             SET status = ?,
                 responded_at = IF(? = "responded", UTC_TIMESTAMP(), responded_at),
                 responded_by = IF(? = "responded", ?, responded_by)
             WHERE id = ?'
        );
        $stmt->execute([$status, $status, $status, $userId, $id]);
        return $stmt->rowCount() >= 0;
    }

    public function updateNote(int $id, string $note): bool
    {
        $note = trim($note);
        if (mb_strlen($note) > 5000) {
            $note = mb_substr($note, 0, 5000);
        }
        $stmt = $this->db()->prepare('UPDATE contact_inquiries SET admin_note = ? WHERE id = ?');
        $stmt->execute([$note === '' ? null : $note, $id]);
        return true;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM contact_inquiries WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{total:int,new:int,responded:int,closed:int,spam:int} */
    public function counts(): array
    {
        $rows = $this->db()->query('SELECT status, COUNT(*) AS c FROM contact_inquiries GROUP BY status')
            ->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['total' => 0, 'new' => 0, 'responded' => 0, 'closed' => 0, 'spam' => 0];
        foreach ($rows as $row) {
            $c = (int) $row['c'];
            $counts['total'] += $c;
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = $c;
            }
        }
        return $counts;
    }
}
