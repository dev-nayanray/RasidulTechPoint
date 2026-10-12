<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\ContactInquiryRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Contact inquiry triage (admin side of the public /contact form).
 *
 * Permission map (enforced by route middleware + re-checked here on
 * every mutating action — hiding menu links is never the gate):
 *  - contact_inquiries.view   : list + detail
 *  - contact_inquiries.manage : status changes, notes, deletion
 *
 * Privacy: ip_address / user_agent are shown only to viewers of this
 * section (staff with the view permission) and never rendered on any
 * customer-facing page. All inquiry text is escaped with e() in views.
 */
final class ContactInquiryController
{
    public function index(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 20));
        $repo = new ContactInquiryRepository();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'sort' => (string) $request->query('sort', 'newest'),
        ];
        $result = $repo->search($filters, $page, $perPage);

        return Response::html(View::render('admin/contact-inquiries/index', [
            'pageTitle' => 'Contact Inquiries',
            'inquiries' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'filters' => $filters,
            'statuses' => ContactInquiryRepository::STATUSES,
            'counts' => $repo->counts(),
            'canManage' => AuthService::can('contact_inquiries.manage'),
        ]));
    }

    public function show(string $id): Response
    {
        $inquiry = $this->findOrFail($id);
        return Response::html(View::render('admin/contact-inquiries/show', [
            'pageTitle' => 'Inquiry ' . $inquiry['inquiry_number'],
            'inquiry' => $inquiry,
            'statuses' => ContactInquiryRepository::STATUSES,
            'canManage' => AuthService::can('contact_inquiries.manage'),
        ]));
    }

    public function updateStatus(string $id, Request $request): Response
    {
        $this->requireManage();
        $inquiry = $this->findOrFail($id);
        $status = (string) $request->input('status', '');

        if (!in_array($status, ContactInquiryRepository::STATUSES, true)) {
            throw new ValidationException(['status' => 'Invalid status. Allowed: ' . implode(', ', ContactInquiryRepository::STATUSES)]);
        }

        (new ContactInquiryRepository())->updateStatus((int) $inquiry['id'], $status, (int) AuthService::id());
        AuditService::record('contact_inquiry.status_changed', 'contact_inquiry', (int) $inquiry['id'], [
            'from' => $inquiry['status'],
            'to' => $status,
        ], $request);

        flash_success('Inquiry ' . $inquiry['inquiry_number'] . ' marked as ' . $status . '.');
        return Response::redirect(url('/admin/contact-inquiries/' . $inquiry['id']));
    }

    public function updateNote(string $id, Request $request): Response
    {
        $this->requireManage();
        $inquiry = $this->findOrFail($id);

        (new ContactInquiryRepository())->updateNote((int) $inquiry['id'], (string) $request->input('admin_note', ''));
        AuditService::record('contact_inquiry.note_updated', 'contact_inquiry', (int) $inquiry['id'], [], $request);

        flash_success('Internal note saved.');
        return Response::redirect(url('/admin/contact-inquiries/' . $inquiry['id']));
    }

    public function destroy(string $id, Request $request): Response
    {
        $this->requireManage();
        $inquiry = $this->findOrFail($id);
        $confirm = trim((string) $request->input('confirm', ''));
        if ($confirm !== $inquiry['inquiry_number']) {
            flash_error('Deletion requires typing the inquiry number to confirm. Nothing was deleted.');
            return Response::redirect(url('/admin/contact-inquiries/' . $inquiry['id']));
        }

        (new ContactInquiryRepository())->delete((int) $inquiry['id']);
        AuditService::record('contact_inquiry.deleted', 'contact_inquiry', (int) $inquiry['id'], [
            'inquiry_number' => $inquiry['inquiry_number'],
            'email' => $inquiry['email'],
        ], $request);

        flash_success('Inquiry ' . $inquiry['inquiry_number'] . ' deleted.');
        return Response::redirect(url('/admin/contact-inquiries'));
    }

    private function findOrFail(string $id): array
    {
        $inquiry = (new ContactInquiryRepository())->find((int) $id);
        if ($inquiry === null) {
            throw new NotFoundException('Contact inquiry not found.');
        }
        return $inquiry;
    }

    private function requireManage(): void
    {
        if (!AuthService::can('contact_inquiries.manage')) {
            throw new ForbiddenException('You do not have permission to manage contact inquiries.');
        }
    }
}
