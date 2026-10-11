<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Database\Connection;
use PDO;

/**
 * Invoices: formal printable invoices with sequential per-year invoice
 * numbers, billing details, tax calculation, and a status lifecycle.
 *
 * Invoices snapshot the order's line items at generation time. A revised
 * invoice voids the old one (preserved for audit) and issues a new one
 * with the next sequential number. One 'active' invoice per order at
 * a time, linked via orders.invoice_id.
 *
 * Tax calculation: tax_amount = (subtotal - discount) * tax_rate.
 * Total = (subtotal - discount) + tax_amount.
 */
final class InvoiceService
{
    public const INVOICE_STATUSES = ['draft', 'issued', 'paid', 'void'];

    /**
     * Generate a new invoice for an order. Snapshots the order's line
     * items (product_name, sku, unit_price) at generation time so the
     * invoice is immutable even if the order is later corrected.
     *
     * @param array{billing_name:string,billing_address?:?string,billing_phone?:?string,billing_email?:?string,tax_name?:?string,tax_rate?:float,notes?:?string,due_date?:?string} $billing
     */
    public function generateForOrder(int $orderId, array $billing, int $generatedBy): array
    {
        if (trim($billing['billing_name'] ?? '') === '') {
            throw new ValidationException(['billing_name' => 'Billing name is required.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($orderId, $billing, $generatedBy) {
            // Lock the order and read its current totals + line items.
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order === false) {
                throw new ValidationException(['order_id' => 'Order does not exist.']);
            }

            $stmt = $pdo->prepare(
                'SELECT product_name, sku, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id'
            );
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($items === []) {
                throw new ValidationException(['order_id' => 'Cannot invoice an order with no items.']);
            }

            $subtotal = (float) $order['subtotal'];
            $discount = (float) $order['discount'];
            $taxRate = (float) ($billing['tax_rate'] ?? 0);
            $taxName = (string) ($billing['tax_name'] ?? SettingService::get('invoice_tax_name', 'VAT'));
            $taxable = max(0.0, $subtotal - $discount);
            $taxAmount = round($taxable * $taxRate, 2);
            $total = round($taxable + $taxAmount, 2);

            $invoiceNumber = $this->nextInvoiceNumber($pdo);
            $issueDate = gmdate('Y-m-d');

            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (order_id, invoice_number, issue_date, due_date, billing_name, billing_address,
                     billing_phone, billing_email, tax_name, tax_rate, tax_amount,
                     subtotal, discount, total, currency, notes, status, created_by, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued", ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $orderId,
                $invoiceNumber,
                $issueDate,
                $billing['due_date'] ?? null,
                $billing['billing_name'],
                $billing['billing_address'] ?? null,
                $billing['billing_phone'] ?? null,
                $billing['billing_email'] ?? null,
                $taxName,
                number_format($taxRate, 4, '.', ''),
                number_format($taxAmount, 2, '.', ''),
                number_format($subtotal, 2, '.', ''),
                number_format($discount, 2, '.', ''),
                number_format($total, 2, '.', ''),
                $order['currency'],
                $billing['notes'] ?? null,
                $generatedBy,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            // Link the order to this active invoice. Any previous
            // invoice linked to this order stays in the invoices table
            // for audit but is no longer the 'active' one.
            $stmt = $pdo->prepare('UPDATE orders SET invoice_id = ? WHERE id = ?');
            $stmt->execute([$invoiceId, $orderId]);

            app_audit('invoice.issued', 'invoice', $invoiceId, [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'total' => number_format($total, 2, '.', ''),
                'tax_amount' => number_format($taxAmount, 2, '.', ''),
            ]);

            return [
                'id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total' => number_format($total, 2, '.', ''),
                'tax_amount' => number_format($taxAmount, 2, '.', ''),
            ];
        });
    }

    /**
     * Void an invoice. The row is preserved for audit; a new invoice
     * can be issued with the next sequential number.
     */
    public function void(int $invoiceId, int $voidedBy, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => 'A void reason is required.']);
        }

        return Connection::transaction(function (PDO $pdo) use ($invoiceId, $voidedBy, $reason) {
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice === false) {
                throw new ValidationException(['invoice_id' => 'Invoice does not exist.']);
            }
            if ($invoice['status'] === 'void') {
                throw new ValidationException(['invoice_id' => 'Invoice is already void.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE invoices SET status = "void", voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?'
            );
            $stmt->execute([$voidedBy, mb_substr($reason, 0, 255), $invoiceId]);

            // Unlink the order if this was its active invoice.
            $stmt = $pdo->prepare('UPDATE orders SET invoice_id = NULL WHERE invoice_id = ?');
            $stmt->execute([$invoiceId]);

            app_audit('invoice.voided', 'invoice', $invoiceId, [
                'invoice_number' => $invoice['invoice_number'],
                'reason' => mb_substr($reason, 0, 80),
            ]);

            return ['id' => $invoiceId, 'status' => 'void'];
        });
    }

    /**
     * Mark an invoice as paid. Does NOT record a payment — that flows
     * through OrderService.recordPayment. This is a label flip for the
     * invoice's own lifecycle so the printable invoice shows 'PAID'.
     */
    public function markPaid(int $invoiceId): array
    {
        Connection::transaction(function (PDO $pdo) use ($invoiceId) {
            $stmt = $pdo->prepare('SELECT status FROM invoices WHERE id = ? FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new ValidationException(['invoice_id' => 'Invoice does not exist.']);
            }
            if ($row['status'] === 'void') {
                throw new ValidationException(['invoice_id' => 'Cannot mark a void invoice as paid.']);
            }
            $stmt = $pdo->prepare('UPDATE invoices SET status = "paid" WHERE id = ?');
            $stmt->execute([$invoiceId]);

            app_audit('invoice.marked_paid', 'invoice', $invoiceId, []);
        });
        return ['id' => $invoiceId, 'status' => 'paid'];
    }

    /** Find an invoice by id, with its order + line items snapshot. */
    public function find(int $invoiceId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT i.*, o.reference AS order_reference, o.customer_id,
                    u.name AS created_by_name, v.name AS voided_by_name
             FROM invoices i
             JOIN orders o ON o.id = i.order_id
             LEFT JOIN users u ON u.id = i.created_by
             LEFT JOIN users v ON v.id = i.voided_by
             WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) {
            return null;
        }

        // Pull the order_items snapshot — these are immutable line
        // items captured at sale time; corrections to the order do
        // not retroactively change the invoice unless a new invoice
        // is issued (which voids this one).
        $stmt = Connection::pdo()->prepare(
            'SELECT product_name, sku, unit_price, line_total
             FROM order_items WHERE order_id = ? ORDER BY id'
        );
        $stmt->execute([$invoice['order_id']]);
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $invoice;
    }

    public function findByOrder(int $orderId): ?array
    {
        $stmt = Connection::pdo()->prepare(
            'SELECT id FROM invoices WHERE order_id = ? AND status != "void" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->find((int) $id);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (i.invoice_number LIKE ? OR o.reference LIKE ? OR i.billing_name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($status !== '' && in_array($status, self::INVOICE_STATUSES, true)) {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }

        $countStmt = Connection::pdo()->prepare(
            "SELECT COUNT(*) FROM invoices i JOIN orders o ON o.id = i.order_id{$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Connection::pdo()->prepare(
            "SELECT i.id, i.invoice_number, i.issue_date, i.status, i.total, i.billing_name,
                    o.reference AS order_reference
             FROM invoices i JOIN orders o ON o.id = i.order_id
             {$where}
             ORDER BY i.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Generate the next sequential invoice number. Format: INV-YYYY-NNNNN
     * where NNNNN is a per-year zero-padded counter.
     */
    private function nextInvoiceNumber(PDO $pdo): string
    {
        $year = (int) gmdate('Y');
        $prefix = 'INV-' . $year . '-';
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ?"
        );
        $stmt->execute([$prefix . '%']);
        $next = (int) $stmt->fetchColumn() + 1;

        // Fall back to a configured starting number if the per-year
        // counter is empty (the SettingService default is 1).
        $configuredNext = (int) SettingService::get('invoice_next_number', 1);
        if ($next < $configuredNext) {
            $next = $configuredNext;
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
