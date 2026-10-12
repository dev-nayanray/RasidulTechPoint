<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * CRM and follow-ups: leads, inquiries, quotations, follow-ups,
 * communications, and WhatsApp click-to-chat links.
 *
 * Lead lifecycle: new → contacted → qualified → quotation_sent → won | lost
 *
 * WhatsApp integration:
 *  - generateWhatsAppLink() returns a wa.me/<phone>?text=<message> URL.
 *    This is a click-to-chat link — it opens WhatsApp with a pre-
 *    filled message for the staff member to review and manually send.
 *  - NO automated messages are sent. The system does not call any
 *    WhatsApp API, webhook, or gateway. All communication is manual
 *    — the staff member clicks the link, sends the message, then
 *    logs the communication in the system.
 *
 * Financial integrity:
 *  - Quotation totals are separate from order totals. A quotation
 *    is a pre-sale estimate. Only the convertToOrder() flow creates
 *    a new order (via OrderService), and that's an explicit manual
 *    action.
 */
final class CrmService
{
    public const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'quotation_sent', 'won', 'lost'];
    public const LEAD_SOURCES = ['walk_in', 'whatsapp', 'phone_call', 'facebook', 'website', 'referral', 'other'];
    public const INQUIRY_STATUSES = ['open', 'responded', 'closed'];
    public const QUOTATION_STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];
    public const FOLLOWUP_METHODS = ['whatsapp', 'phone_call', 'email', 'in_person'];
    public const FOLLOWUP_OUTCOMES = ['pending', 'contacted', 'no_answer', 'not_interested', 'converted'];
    public const COMM_CHANNELS = ['whatsapp', 'phone_call', 'email', 'in_person', 'sms'];

    // ---------------------------------------------------- LEADS --

    public function createLead(array $d, int $createdBy): array
    {
        if (trim($d['name'] ?? '') === '') {
            throw new ValidationException(['name' => 'Name is required.']);
        }
        if (trim($d['phone'] ?? '') === '') {
            throw new ValidationException(['phone' => 'Phone is required.']);
        }
        if (!in_array($d['source'] ?? 'walk_in', self::LEAD_SOURCES, true)) {
            throw new ValidationException(['source' => 'Invalid lead source.']);
        }

        $leadNumber = $this->generateNumber('crm_leads', 'lead_number', 'LD');
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO crm_leads (lead_number, customer_id, name, phone, email, source, status, interest, budget, assigned_to, next_followup_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, "new", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leadNumber,
            $d['customer_id'] ?? null,
            $d['name'],
            $d['phone'],
            $d['email'] ?? null,
            $d['source'] ?? 'walk_in',
            $d['interest'] ?? null,
            isset($d['budget']) ? number_format((float) $d['budget'], 2, '.', '') : null,
            $d['assigned_to'] ?? null,
            $d['next_followup_at'] ?? null,
            $createdBy,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();
        app_audit('crm.lead_created', 'crm_lead', $id, ['lead_number' => $leadNumber, 'name' => $d['name']]);
        return ['id' => $id, 'lead_number' => $leadNumber, 'status' => 'new'];
    }

    public function updateLeadStatus(int $leadId, string $status, int $actorId, ?string $lostReason = null): array
    {
        if (!in_array($status, self::LEAD_STATUSES, true)) {
            throw new ValidationException(['status' => 'Invalid lead status.']);
        }
        $updates = ['status = ?'];
        $params = [$status];
        if ($status === 'lost' && $lostReason !== null) {
            $updates[] = 'lost_reason = ?';
            $params[] = mb_substr($lostReason, 0, 255);
        }
        $params[] = $leadId;
        $stmt = Connection::pdo()->prepare('UPDATE crm_leads SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
        app_audit('crm.lead_status_updated', 'crm_lead', $leadId, ['to' => $status]);
        return ['id' => $leadId, 'status' => $status];
    }

    public function assignLead(int $leadId, int $userId, int $assignedBy): array
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_leads SET assigned_to = ? WHERE id = ?');
        $stmt->execute([$userId, $leadId]);
        app_audit('crm.lead_assigned', 'crm_lead', $leadId, ['assigned_to' => $userId]);
        return ['id' => $leadId, 'assigned_to' => $userId];
    }

    public function setFollowupDate(int $leadId, string $datetime): void
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_leads SET next_followup_at = ? WHERE id = ?');
        $stmt->execute([$datetime, $leadId]);
    }

    public function findLead(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT l.*, c.name AS customer_name, c.phone AS customer_phone,
                    u.name AS assigned_to_name, cb.name AS created_by_name
             FROM crm_leads l
             LEFT JOIN customers c ON c.id = l.customer_id
             LEFT JOIN users u ON u.id = l.assigned_to
             LEFT JOIN users cb ON cb.id = l.created_by
             WHERE l.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['whatsapp_link'] = $this->whatsappLink($row['phone'], 'Hello ' . $row['name'] . ',');
        $row['followups'] = $this->followupsForLead($id);
        $row['communications'] = $this->communicationsFor('lead', $id);
        $row['quotations'] = $this->quotationsForLead($id);
        return $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchLeads(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (l.lead_number LIKE ? OR l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::LEAD_STATUSES, true)) {
            $where .= ' AND l.status = ?';
            $params[] = $status;
        }
        $countStmt = Connection::pdo()->prepare("SELECT COUNT(*) FROM crm_leads l{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT l.*, u.name AS assigned_to_name
             FROM crm_leads l
             LEFT JOIN users u ON u.id = l.assigned_to
             {$where}
             ORDER BY l.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- INQUIRIES --

    public function createInquiry(array $d, int $createdBy): array
    {
        if (trim($d['subject'] ?? '') === '') {
            throw new ValidationException(['subject' => 'Subject is required.']);
        }
        if (trim($d['message'] ?? '') === '') {
            throw new ValidationException(['message' => 'Message is required.']);
        }
        $number = $this->generateNumber('crm_inquiries', 'inquiry_number', 'INQ');
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO crm_inquiries (inquiry_number, lead_id, customer_id, product_model_id, subject, message, channel, status, assigned_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, "open", ?)'
        );
        $stmt->execute([
            $number,
            $d['lead_id'] ?? null,
            $d['customer_id'] ?? null,
            $d['product_model_id'] ?? null,
            $d['subject'],
            $d['message'],
            $d['channel'] ?? 'whatsapp',
            $d['assigned_to'] ?? null,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();
        app_audit('crm.inquiry_created', 'crm_inquiry', $id, ['inquiry_number' => $number]);
        return ['id' => $id, 'inquiry_number' => $number, 'status' => 'open'];
    }

    public function respondToInquiry(int $id): void
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_inquiries SET status = "responded" WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function closeInquiry(int $id): void
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_inquiries SET status = "closed" WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ---------------------------------------------------- QUOTATIONS --

    public function createQuotation(array $d, int $createdBy): array
    {
        if (!isset($d['customer_id']) || (int) $d['customer_id'] <= 0) {
            throw new ValidationException(['customer_id' => 'Customer is required.']);
        }
        $items = $d['items'] ?? [];
        if ($items === []) {
            throw new ValidationException(['items' => 'At least one item is required.']);
        }
        $subtotal = 0.0;
        foreach ($items as &$item) {
            $item['line_total'] = round((float) $item['unit_price'] * (int) $item['qty'], 2);
            $subtotal += $item['line_total'];
        }
        unset($item);
        $discount = (float) ($d['discount'] ?? 0);
        $total = round($subtotal - $discount, 2);
        $number = $this->generateNumber('crm_quotations', 'quote_number', 'QUO');
        $payload = json_encode($items, JSON_UNESCAPED_UNICODE);

        $stmt = Connection::pdo()->prepare(
            'INSERT INTO crm_quotations (quote_number, lead_id, customer_id, product_model_id, items_payload, subtotal, discount, total, valid_until, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $number,
            $d['lead_id'] ?? null,
            (int) $d['customer_id'],
            $d['product_model_id'] ?? null,
            $payload,
            number_format($subtotal, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($total, 2, '.', ''),
            $d['valid_until'] ?? null,
            $d['notes'] ?? null,
            $createdBy,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();
        app_audit('crm.quotation_created', 'crm_quotation', $id, ['quote_number' => $number, 'total' => number_format($total, 2, '.', '')]);
        return ['id' => $id, 'quote_number' => $number, 'total' => number_format($total, 2, '.', '')];
    }

    public function sendQuotation(int $id, int $sentBy): array
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_quotations SET status = "sent", sent_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$id]);
        app_audit('crm.quotation_sent', 'crm_quotation', $id, []);
        return ['id' => $id, 'status' => 'sent'];
    }

    public function acceptQuotation(int $id): array
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_quotations SET status = "accepted", accepted_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$id]);
        return ['id' => $id, 'status' => 'accepted'];
    }

    public function rejectQuotation(int $id): array
    {
        $stmt = Connection::pdo()->prepare('UPDATE crm_quotations SET status = "rejected" WHERE id = ?');
        $stmt->execute([$id]);
        return ['id' => $id, 'status' => 'rejected'];
    }

    public function findQuotation(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT q.*, c.name AS customer_name, c.phone AS customer_phone,
                    pm.name AS product_name,
                    l.lead_number AS lead_number
             FROM crm_quotations q
             JOIN customers c ON c.id = q.customer_id
             LEFT JOIN product_models pm ON pm.id = q.product_model_id
             LEFT JOIN crm_leads l ON l.id = q.lead_id
             WHERE q.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['items'] = json_decode((string) $row['items_payload'], true) ?: [];
        $row['whatsapp_link'] = $this->whatsappLink($row['customer_phone'], 'Quotation ' . $row['quote_number'] . ' for ' . $row['customer_name']);
        return $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function searchQuotations(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (q.quote_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::QUOTATION_STATUSES, true)) {
            $where .= ' AND q.status = ?';
            $params[] = $status;
        }
        $countStmt = Connection::pdo()->prepare("SELECT COUNT(*) FROM crm_quotations q JOIN customers c ON c.id = q.customer_id{$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT q.id, q.quote_number, q.status, q.total, q.valid_until, q.created_at,
                    c.name AS customer_name, c.phone AS customer_phone
             FROM crm_quotations q JOIN customers c ON c.id = q.customer_id
             {$where}
             ORDER BY q.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- FOLLOWUPS --

    public function createFollowup(array $d, int $createdBy): int
    {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO crm_followups (lead_id, customer_id, inquiry_id, scheduled_at, method, notes, outcome, created_by)
             VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
        );
        $stmt->execute([
            $d['lead_id'] ?? null,
            $d['customer_id'] ?? null,
            $d['inquiry_id'] ?? null,
            $d['scheduled_at'],
            $d['method'] ?? 'whatsapp',
            $d['notes'] ?? null,
            $createdBy,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();
        // If linked to a lead, update its next_followup_at.
        if (!empty($d['lead_id'])) {
            $this->setFollowupDate((int) $d['lead_id'], $d['scheduled_at']);
        }
        return $id;
    }

    public function completeFollowup(int $id, int $completedBy, string $outcome, ?string $notes = null): void
    {
        if (!in_array($outcome, self::FOLLOWUP_OUTCOMES, true)) {
            throw new ValidationException(['outcome' => 'Invalid outcome.']);
        }
        $stmt = Connection::pdo()->prepare(
            'UPDATE crm_followups SET outcome = ?, completed_at = UTC_TIMESTAMP(), completed_by = ?, notes = CONCAT(IFNULL(notes, ""), ?) WHERE id = ?'
        );
        $stmt->execute([$outcome, $completedBy, $notes !== null ? "\n" . $notes : '', $id]);
    }

    /** @return list<array<string,mixed>> upcoming (not completed, scheduled <= now + 7 days) */
    public function upcomingFollowups(int $limit = 50): array
    {
        $stmt = Connection::pdo()->prepare(
            "SELECT f.*, l.name AS lead_name, l.phone AS lead_phone,
                    c.name AS customer_name, c.phone AS customer_phone,
                    u.name AS assigned_to_name
             FROM crm_followups f
             LEFT JOIN crm_leads l ON l.id = f.lead_id
             LEFT JOIN customers c ON c.id = f.customer_id
             LEFT JOIN crm_leads al ON al.id = f.lead_id
             LEFT JOIN users u ON u.id = al.assigned_to
             WHERE f.outcome = 'pending'
               AND f.scheduled_at <= UTC_TIMESTAMP() + INTERVAL 7 DAY
             ORDER BY f.scheduled_at ASC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Add WhatsApp links.
        foreach ($rows as &$row) {
            $phone = $row['lead_phone'] ?? $row['customer_phone'] ?? '';
            $name = $row['lead_name'] ?? $row['customer_name'] ?? '';
            $row['whatsapp_link'] = $this->whatsappLink($phone, 'Hello ' . $name . ', following up on your inquiry.');
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function followupsForLead(int $leadId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT f.*, u.name AS completed_by_name
             FROM crm_followups f
             LEFT JOIN users u ON u.id = f.completed_by
             WHERE f.lead_id = ?
             ORDER BY f.scheduled_at DESC'
        );
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- COMMUNICATIONS --

    public function logCommunication(array $d, int $initiatedBy): int
    {
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO crm_communications (lead_id, customer_id, inquiry_id, channel, direction, subject, body, initiated_by, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $d['lead_id'] ?? null,
            $d['customer_id'] ?? null,
            $d['inquiry_id'] ?? null,
            $d['channel'] ?? 'whatsapp',
            $d['direction'] ?? 'outbound',
            $d['subject'] ?? null,
            $d['body'] ?? null,
            $initiatedBy,
        ]);
        return (int) Connection::pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function communicationsFor(string $type, int $id): array
    {
        $column = match ($type) {
            'lead' => 'lead_id',
            'customer' => 'customer_id',
            'inquiry' => 'inquiry_id',
            default => 'lead_id',
        };
        $stmt = Connection::pdo()->prepare(
            "SELECT c.*, u.name AS initiated_by_name
             FROM crm_communications c
             LEFT JOIN users u ON u.id = c.initiated_by
             WHERE c.{$column} = ?
             ORDER BY c.occurred_at DESC"
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------- WHATSAPP --

    /**
     * Generate a click-to-chat WhatsApp link. Opens WhatsApp with a
     * pre-filled message for the staff member to review and manually
     * send. NO automated messages are sent — the system does not call
     * any WhatsApp API or gateway.
     */
    public function whatsappLink(string $phone, string $message = ''): string
    {
        // Strip non-digits from the phone number.
        $clean = preg_replace('/[^0-9]/', '', $phone);
        // If the number doesn't start with a country code, assume
        // Bangladesh (+880) and strip the leading 0.
        if (strlen($clean) > 0 && !str_starts_with($clean, '880') && str_starts_with($clean, '0')) {
            $clean = '880' . substr($clean, 1);
        }
        $url = 'https://wa.me/' . $clean;
        if ($message !== '') {
            $url .= '?text=' . rawurlencode($message);
        }
        return $url;
    }

    // ---------------------------------------------------- HELPERS --

    /** @return list<array<string,mixed>> */
    private function quotationsForLead(int $leadId): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT id, quote_number, status, total, created_at FROM crm_quotations WHERE lead_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function generateNumber(string $table, string $column, string $prefix): string
    {
        $year = (int) gmdate('Y');
        $fullPrefix = $prefix . '-' . $year . '-';
        $stmt = Connection::pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE ?");
        $stmt->execute([$fullPrefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $fullPrefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
