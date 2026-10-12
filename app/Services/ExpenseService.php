<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Expense management: categories, entries, approval workflow, reports.
 *
 * Status lifecycle:
 *   pending → approved → paid (terminal)
 *                    ↘ rejected (terminal)
 *
 * Critical: approved expenses cannot be edited. updateEntry() throws
 * if the status is 'approved' or 'paid'. This prevents unauthorized
 * edits to approved expenses and ensures the audit trail is trustworthy.
 *
 * Financial integrity:
 *  - Expense amounts are separate from sale revenue and payment
 *    reconciliation. They live on expense_entries, never in orders,
 *    payments, or reconciliation_batches.
 *  - All financial changes are audited via app_audit().
 */
final class ExpenseService
{
    public const ENTRY_STATUSES = ['pending', 'approved', 'rejected', 'paid'];
    public const PAYMENT_ACCOUNTS = ['cash', 'bank', 'bkash', 'nagad', 'card', 'other'];

    private const UPLOAD_DIR = 'uploads/expenses';
    private const MAX_FILE_SIZE = 4 * 1024 * 1024; // 4 MiB
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    // ---------------------------------------------------- CATEGORIES --

    public function createCategory(string $name, ?string $description = null, int $displayOrder = 0): int
    {
        if (trim($name) === '') {
            throw new ValidationException(['name' => 'Category name is required.']);
        }
        $slug = str_slug($name);
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO expense_categories (name, slug, description, display_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $slug . '-' . bin2hex(random_bytes(2)), $description, $displayOrder]);
        return (int) Connection::pdo()->lastInsertId();
    }

    public function updateCategory(int $id, string $name, ?string $description, int $displayOrder, bool $isActive): void
    {
        $stmt = Connection::pdo()->prepare(
            'UPDATE expense_categories SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$name, $description, $displayOrder, $isActive ? 1 : 0, $id]);
    }

    /** @return list<array<string,mixed>> */
    public function allCategories(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM expense_categories';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_order ASC, name ASC';
        return Connection::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCategory(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare('SELECT * FROM expense_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // ---------------------------------------------------- ENTRIES --

    public function createEntry(array $d, int $createdBy): array
    {
        if (trim($d['description'] ?? '') === '') {
            throw new ValidationException(['description' => 'Description is required.']);
        }
        if ((float) ($d['amount'] ?? 0) <= 0) {
            throw new ValidationException(['amount' => 'Amount must be positive.']);
        }
        if (trim($d['expense_date'] ?? '') === '') {
            throw new ValidationException(['expense_date' => 'Expense date is required.']);
        }
        if (!in_array($d['payment_account'] ?? 'cash', self::PAYMENT_ACCOUNTS, true)) {
            throw new ValidationException(['payment_account' => 'Invalid payment account.']);
        }
        $categoryId = (int) ($d['category_id'] ?? 0);
        if ($this->findCategory($categoryId) === null) {
            throw new ValidationException(['category_id' => 'Category does not exist.']);
        }

        $number = $this->generateNumber();
        $stmt = Connection::pdo()->prepare(
            'INSERT INTO expense_entries
                (expense_number, category_id, description, amount, expense_date, payment_account,
                 payment_reference, receipt_path, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?)'
        );
        $stmt->execute([
            $number,
            $categoryId,
            $d['description'],
            number_format((float) $d['amount'], 2, '.', ''),
            $d['expense_date'],
            $d['payment_account'],
            $d['payment_reference'] ?? null,
            $d['receipt_path'] ?? null,
            $d['notes'] ?? null,
            $createdBy,
        ]);
        $id = (int) Connection::pdo()->lastInsertId();
        app_audit('expense.created', 'expense_entry', $id, [
            'expense_number' => $number,
            'amount' => number_format((float) $d['amount'], 2, '.', ''),
            'category_id' => $categoryId,
        ]);
        return ['id' => $id, 'expense_number' => $number, 'status' => 'pending'];
    }

    /**
     * Update an entry. CRITICAL: only pending entries can be edited.
     * Approved/paid entries are locked — throws if the status is not
     * 'pending'.
     */
    public function updateEntry(int $id, array $d): void
    {
        $entry = $this->findEntry($id);
        if ($entry === null) {
            throw new ValidationException(['id' => 'Expense entry does not exist.']);
        }
        if (in_array($entry['status'], ['approved', 'paid'], true)) {
            throw new ValidationException([
                'status' => 'Cannot edit an approved or paid expense. This entry is locked (status: ' . $entry['status'] . ').',
            ]);
        }

        $updates = [];
        $params = [];
        if (isset($d['description'])) {
            $updates[] = 'description = ?';
            $params[] = $d['description'];
        }
        if (isset($d['amount'])) {
            if ((float) $d['amount'] <= 0) {
                throw new ValidationException(['amount' => 'Amount must be positive.']);
            }
            $updates[] = 'amount = ?';
            $params[] = number_format((float) $d['amount'], 2, '.', '');
        }
        if (isset($d['expense_date'])) {
            $updates[] = 'expense_date = ?';
            $params[] = $d['expense_date'];
        }
        if (isset($d['payment_account'])) {
            $updates[] = 'payment_account = ?';
            $params[] = $d['payment_account'];
        }
        if (isset($d['payment_reference'])) {
            $updates[] = 'payment_reference = ?';
            $params[] = $d['payment_reference'];
        }
        if (isset($d['receipt_path'])) {
            $updates[] = 'receipt_path = ?';
            $params[] = $d['receipt_path'];
        }
        if (isset($d['notes'])) {
            $updates[] = 'notes = ?';
            $params[] = $d['notes'];
        }
        if ($updates === []) {
            return;
        }
        $params[] = $id;
        $stmt = Connection::pdo()->prepare('UPDATE expense_entries SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
        app_audit('expense.updated', 'expense_entry', $id, []);
    }

    /**
     * Approve an entry. Locks the entry from further edits.
     */
    public function approveEntry(int $id, int $approvedBy): array
    {
        $entry = $this->findEntry($id);
        if ($entry === null) {
            throw new ValidationException(['id' => 'Expense entry does not exist.']);
        }
        if ($entry['status'] !== 'pending') {
            throw new ValidationException(['status' => 'Only pending expenses can be approved (current: ' . $entry['status'] . ').']);
        }
        $stmt = Connection::pdo()->prepare(
            'UPDATE expense_entries SET status = "approved", approved_by = ?, approved_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$approvedBy, $id]);
        app_audit('expense.approved', 'expense_entry', $id, ['amount' => $entry['amount']]);
        return ['id' => $id, 'status' => 'approved'];
    }

    /**
     * Reject an entry.
     */
    public function rejectEntry(int $id, int $rejectedBy, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A rejection reason is required.']);
        }
        $entry = $this->findEntry($id);
        if ($entry === null) {
            throw new ValidationException(['id' => 'Expense entry does not exist.']);
        }
        if (!in_array($entry['status'], ['pending', 'approved'], true)) {
            throw new ValidationException(['status' => 'Cannot reject an expense in status: ' . $entry['status'] . '.']);
        }
        $stmt = Connection::pdo()->prepare(
            'UPDATE expense_entries SET status = "rejected", rejection_reason = ? WHERE id = ?'
        );
        $stmt->execute([mb_substr($reason, 0, 255), $id]);
        app_audit('expense.rejected', 'expense_entry', $id, ['reason' => mb_substr($reason, 0, 80)]);
        return ['id' => $id, 'status' => 'rejected'];
    }

    /**
     * Mark an approved entry as paid.
     */
    public function markPaid(int $id): array
    {
        $entry = $this->findEntry($id);
        if ($entry === null) {
            throw new ValidationException(['id' => 'Expense entry does not exist.']);
        }
        if ($entry['status'] !== 'approved') {
            throw new ValidationException(['status' => 'Only approved expenses can be marked paid (current: ' . $entry['status'] . ').']);
        }
        $stmt = Connection::pdo()->prepare('UPDATE expense_entries SET status = "paid", paid_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$id]);
        app_audit('expense.paid', 'expense_entry', $id, ['amount' => $entry['amount']]);
        return ['id' => $id, 'status' => 'paid'];
    }

    public function findEntry(int $id): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT e.*, ec.name AS category_name,
                    ab.name AS approved_by_name,
                    cb.name AS created_by_name
             FROM expense_entries e
             JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN users ab ON ab.id = e.approved_by
             LEFT JOIN users cb ON cb.id = e.created_by
             WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', ?int $categoryId = null, string $from = '', string $to = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (e.expense_number LIKE ? OR e.description LIKE ? OR e.payment_reference LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::ENTRY_STATUSES, true)) {
            $where .= ' AND e.status = ?';
            $params[] = $status;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $where .= ' AND e.category_id = ?';
            $params[] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND e.expense_date >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $where .= ' AND e.expense_date <= ?';
            $params[] = $to;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM expense_entries e{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT e.id, e.expense_number, e.description, e.amount, e.expense_date,
                    e.payment_account, e.payment_reference, e.receipt_path, e.status, e.created_at,
                    ec.name AS category_name,
                    ab.name AS approved_by_name
             FROM expense_entries e
             JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN users ab ON ab.id = e.approved_by
             {$where}
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ---------------------------------------------------- REPORTS --

    /**
     * Report by category for a date range.
     * @return array{categories:list<array<string,mixed>>,total:float,pending:float,approved:float,paid:float}
     */
    public function reportByCategory(string $from, string $to): array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT ec.id, ec.name,
                    COUNT(e.id) AS entry_count,
                    COALESCE(SUM(CASE WHEN e.status IN ("approved","paid") THEN e.amount ELSE 0 END), 0) AS approved_total,
                    COALESCE(SUM(CASE WHEN e.status = "pending" THEN e.amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN e.status = "paid" THEN e.amount ELSE 0 END), 0) AS paid_total,
                    COALESCE(SUM(CASE WHEN e.status IN ("pending","approved","paid") THEN e.amount ELSE 0 END), 0) AS all_total
             FROM expense_categories ec
             LEFT JOIN expense_entries e ON e.category_id = ec.id
                AND e.expense_date >= ? AND e.expense_date <= ?
             WHERE ec.is_active = 1
             GROUP BY ec.id, ec.name
             ORDER BY ec.display_order ASC'
        );
        $stmt->execute([$from, $to]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0; $pending = 0.0; $approved = 0.0; $paid = 0.0;
        foreach ($categories as $cat) {
            $total += (float) $cat['all_total'];
            $pending += (float) $cat['pending_total'];
            $approved += (float) $cat['approved_total'];
            $paid += (float) $cat['paid_total'];
        }

        return [
            'categories' => $categories,
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'paid' => $paid,
        ];
    }

    // ---------------------------------------------------- RECEIPT UPLOAD --

    /**
     * Save a receipt attachment. Validates MIME via finfo, generates
     * a random filename, stores in public/uploads/expenses/ with
     * .htaccess protection. Returns the relative path.
     */
    public function saveReceipt(?array $file): ?string
    {
        if ($file === null || !isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['receipt' => 'Upload failed with error code ' . $file['error'] . '.']);
        }
        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new ValidationException(['receipt' => 'File exceeds the 4 MiB limit.']);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException(['receipt' => 'Invalid file type. Allowed: JPEG, PNG, WebP, PDF.']);
        }

        $ext = match ($realMime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
        $destDir = BASE_PATH . '/public/' . self::UPLOAD_DIR;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0770, true);
        }
        $destPath = $destDir . '/' . $safeName;
        if (is_uploaded_file($file['tmp_name'])) {
            move_uploaded_file($file['tmp_name'], $destPath);
        } else {
            rename($file['tmp_name'], $destPath);
        }

        return self::UPLOAD_DIR . '/' . $safeName;
    }

    // ---------------------------------------------------- HELPERS --

    private function generateNumber(): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'EXP-' . $year . '-';
        $stmt = Connection::pdo()->prepare('SELECT COUNT(*) FROM expense_entries WHERE expense_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
