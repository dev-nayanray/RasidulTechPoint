<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Services\AuditService;
use App\Services\CrmService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * CRM and follow-ups controller.
 */
final class CrmController
{
    public function index(): Response
    {
        $svc = new CrmService();
        $pdo = \App\Support\Database\Connection::pdo();
        $newLeads = (int) $pdo->query("SELECT COUNT(*) FROM crm_leads WHERE status = 'new'")->fetchColumn();
        $openInquiries = (int) $pdo->query("SELECT COUNT(*) FROM crm_inquiries WHERE status = 'open'")->fetchColumn();
        $pendingFollowups = (int) $pdo->query("SELECT COUNT(*) FROM crm_followups WHERE outcome = 'pending' AND scheduled_at <= UTC_TIMESTAMP()")->fetchColumn();
        $upcoming = $svc->upcomingFollowups(15);
        $recentLeads = $svc->searchLeads('', '', 1, 10)['rows'];

        return Response::html(View::render('admin/crm/dashboard', [
            'pageTitle' => 'Customer CRM',
            'newLeads' => $newLeads,
            'openInquiries' => $openInquiries,
            'pendingFollowups' => $pendingFollowups,
            'upcoming' => $upcoming,
            'recentLeads' => $recentLeads,
        ]));
    }

    // ---------------------------------------------------- LEADS --

    public function leadIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new CrmService();
        $result = $svc->searchLeads(trim((string) $request->query('q', '')), (string) $request->query('status', ''), $page, $perPage);
        return Response::html(View::render('admin/crm/leads/index', [
            'pageTitle' => 'Leads',
            'leads' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => CrmService::LEAD_STATUSES,
            'sources' => CrmService::LEAD_SOURCES,
        ]));
    }

    public function leadCreateForm(): Response
    {
        return Response::html(View::render('admin/crm/leads/create', [
            'pageTitle' => 'New lead',
            'sources' => CrmService::LEAD_SOURCES,
            'errors' => [],
        ]));
    }

    public function leadStore(Request $request): Response
    {
        try {
            $result = (new CrmService())->createLead($request->post(), current_user_id());
            flash_success('Lead ' . $result['lead_number'] . ' created.');
            return Response::redirect(url('/admin/crm/leads/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::html(View::render('admin/crm/leads/create', [
                'pageTitle' => 'New lead',
                'sources' => CrmService::LEAD_SOURCES,
                'errors' => $e->errors,
            ]), 422);
        }
    }

    public function leadShow(string $id): Response
    {
        $lead = (new CrmService())->findLead((int) $id);
        if ($lead === null) {
            throw new NotFoundException('Lead not found.');
        }
        return Response::html(View::render('admin/crm/leads/show', [
            'pageTitle' => 'Lead ' . $lead['lead_number'],
            'lead' => $lead,
            'statuses' => CrmService::LEAD_STATUSES,
            'followupMethods' => CrmService::FOLLOWUP_METHODS,
            'followupOutcomes' => CrmService::FOLLOWUP_OUTCOMES,
            'commChannels' => CrmService::COMM_CHANNELS,
        ]));
    }

    public function leadUpdateStatus(string $id, Request $request): Response
    {
        try {
            (new CrmService())->updateLeadStatus((int) $id, (string) $request->input('status', ''), current_user_id(), $request->input('lost_reason') ?: null);
            flash_success('Lead status updated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/crm/leads/' . $id));
    }

    public function leadAssign(string $id, Request $request): Response
    {
        try {
            (new CrmService())->assignLead((int) $id, (int) $request->input('user_id', 0), current_user_id());
            flash_success('Lead assigned.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/crm/leads/' . $id));
    }

    public function leadFollowup(string $id, Request $request): Response
    {
        try {
            (new CrmService())->createFollowup([
                'lead_id' => (int) $id,
                'scheduled_at' => (string) $request->input('scheduled_at', ''),
                'method' => (string) $request->input('method', 'whatsapp'),
                'notes' => $request->input('notes') ?: null,
            ], current_user_id());
            flash_success('Follow-up scheduled.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/crm/leads/' . $id) . '#followups');
    }

    public function followupComplete(string $id, Request $request): Response
    {
        try {
            (new CrmService())->completeFollowup((int) $id, current_user_id(), (string) $request->input('outcome', 'contacted'), $request->input('notes') ?: null);
            flash_success('Follow-up completed.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        // Redirect back to the lead page (extract lead_id from referer).
        return Response::redirect(url('/admin/crm'));
    }

    public function leadLogCommunication(string $id, Request $request): Response
    {
        try {
            (new CrmService())->logCommunication([
                'lead_id' => (int) $id,
                'channel' => (string) $request->input('channel', 'whatsapp'),
                'direction' => (string) $request->input('direction', 'outbound'),
                'subject' => $request->input('subject') ?: null,
                'body' => $request->input('body') ?: null,
            ], current_user_id());
            flash_success('Communication logged.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/crm/leads/' . $id) . '#communications');
    }

    // ---------------------------------------------------- QUOTATIONS --

    public function quotationIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new CrmService();
        $result = $svc->searchQuotations(trim((string) $request->query('q', '')), (string) $request->query('status', ''), $page, $perPage);
        return Response::html(View::render('admin/crm/quotations/index', [
            'pageTitle' => 'Quotations',
            'quotations' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => CrmService::QUOTATION_STATUSES,
        ]));
    }

    public function quotationShow(string $id): Response
    {
        $q = (new CrmService())->findQuotation((int) $id);
        if ($q === null) {
            throw new NotFoundException('Quotation not found.');
        }
        return Response::html(View::render('admin/crm/quotations/show', [
            'pageTitle' => 'Quotation ' . $q['quote_number'],
            'q' => $q,
        ]));
    }

    public function quotationSend(string $id): Response
    {
        (new CrmService())->sendQuotation((int) $id, current_user_id());
        flash_success('Quotation marked as sent. Use the WhatsApp link to share it with the customer.');
        return Response::redirect(url('/admin/crm/quotations/' . $id));
    }

    public function quotationAccept(string $id): Response
    {
        (new CrmService())->acceptQuotation((int) $id);
        flash_success('Quotation accepted.');
        return Response::redirect(url('/admin/crm/quotations/' . $id));
    }

    public function quotationReject(string $id): Response
    {
        (new CrmService())->rejectQuotation((int) $id);
        flash_success('Quotation rejected.');
        return Response::redirect(url('/admin/crm/quotations/' . $id));
    }
}
