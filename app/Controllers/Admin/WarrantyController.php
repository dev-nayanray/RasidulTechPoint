<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Services\AuditService;
use App\Services\WarrantyService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Warranty management controller.
 *
 * Routes (under /admin/warranty, all warranty.manage):
 *   GET  /admin/warranty                              dashboard
 *   GET  /admin/warranty/policies                     policy list
 *   GET  /admin/warranty/policies/create              create form
 *   POST /admin/warranty/policies                     store
 *   GET  /admin/warranty/policies/{id}/edit           edit form
 *   POST /admin/warranty/policies/{id}/update        update
 *   GET  /admin/warranty/registrations                registration list
 *   GET  /admin/warranty/registrations/{id}           show
 *   POST /admin/warranty/registrations                create
 *   POST /admin/warranty/registrations/{id}/void     void
 *   GET  /admin/warranty/claims                       claim list
 *   GET  /admin/warranty/claims/{id}                  show
 *   POST /admin/warranty/claims                       create
 *   POST /admin/warranty/claims/{id}/approve
 *   POST /admin/warranty/claims/{id}/reject
 *   POST /admin/warranty/claims/{id}/start
 *   POST /admin/warranty/claims/{id}/complete
 *   POST /admin/warranty/claims/{id}/cancel
 */
final class WarrantyController
{
    public function index(): Response
    {
        $svc = new WarrantyService();
        $pdo = \App\Support\Database\Connection::pdo();
        $active = (int) $pdo->query("SELECT COUNT(*) FROM warranty_registrations WHERE status = 'active'")->fetchColumn();
        $expired = (int) $pdo->query("SELECT COUNT(*) FROM warranty_registrations WHERE status = 'expired'")->fetchColumn();
        $voided = (int) $pdo->query("SELECT COUNT(*) FROM warranty_registrations WHERE status = 'voided'")->fetchColumn();
        $pendingClaims = (int) $pdo->query("SELECT COUNT(*) FROM warranty_claims WHERE status IN ('pending','approved','in_progress')")->fetchColumn();
        $recentClaims = $svc->searchClaims('', '', 1, 10)['rows'];

        return Response::html(View::render('admin/warranty/dashboard', [
            'pageTitle' => 'Warranty & Repairs',
            'active' => $active,
            'expired' => $expired,
            'voided' => $voided,
            'pendingClaims' => $pendingClaims,
            'recentClaims' => $recentClaims,
            'policies' => $svc->allPolicies(true),
        ]));
    }

    // ---------------------------------------------------- POLICIES --

    public function policyIndex(): Response
    {
        return Response::html(View::render('admin/warranty/policies/index', [
            'pageTitle' => 'Warranty policies',
            'policies' => (new WarrantyService())->allPolicies(false),
        ]));
    }

    public function policyCreate(): Response
    {
        return Response::html(View::render('admin/warranty/policies/form', [
            'pageTitle' => 'Add warranty policy',
            'policy' => null,
            'errors' => [],
            'coverageTypes' => WarrantyService::COVERAGE_TYPES,
        ]));
    }

    public function policyStore(Request $request): Response
    {
        try {
            $svc = new WarrantyService();
            $id = $svc->createPolicy($request->post(), current_user_id());
            AuditService::record('warranty.policy_created', 'warranty_policy', $id, ['name' => $request->input('name')], $request);
            flash_success('Warranty policy created.');
            return Response::redirect(url('/admin/warranty/policies'));
        } catch (\App\Exceptions\ValidationException $e) {
            Session::flashInput($request->post());
            return Response::html(View::render('admin/warranty/policies/form', [
                'pageTitle' => 'Add warranty policy',
                'policy' => $request->post(),
                'errors' => $e->errors,
                'coverageTypes' => WarrantyService::COVERAGE_TYPES,
            ]), 422);
        }
    }

    public function policyEdit(string $id): Response
    {
        $policy = (new WarrantyService())->findPolicy((int) $id);
        if ($policy === null) {
            throw new NotFoundException('Warranty policy not found.');
        }
        return Response::html(View::render('admin/warranty/policies/form', [
            'pageTitle' => 'Edit: ' . $policy['name'],
            'policy' => $policy,
            'errors' => [],
            'coverageTypes' => WarrantyService::COVERAGE_TYPES,
        ]));
    }

    public function policyUpdate(string $id, Request $request): Response
    {
        $svc = new WarrantyService();
        if ($svc->findPolicy((int) $id) === null) {
            throw new NotFoundException('Warranty policy not found.');
        }
        try {
            $svc->updatePolicy((int) $id, $request->post());
            AuditService::record('warranty.policy_updated', 'warranty_policy', (int) $id, [], $request);
            flash_success('Warranty policy updated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/warranty/policies/' . $id . '/edit'));
    }

    // ---------------------------------------------------- REGISTRATIONS --

    public function registrationIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new WarrantyService();
        $result = $svc->searchRegistrations(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/warranty/registrations/index', [
            'pageTitle' => 'Warranty registrations',
            'registrations' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => WarrantyService::REGISTRATION_STATUSES,
        ]));
    }

    public function registrationShow(string $id): Response
    {
        $reg = (new WarrantyService())->findRegistration((int) $id);
        if ($reg === null) {
            throw new NotFoundException('Warranty registration not found.');
        }
        return Response::html(View::render('admin/warranty/registrations/show', [
            'pageTitle' => 'Warranty ' . $reg['registration_number'],
            'reg' => $reg,
            'claimTypes' => WarrantyService::CLAIM_TYPES,
        ]));
    }

    public function registrationStore(Request $request): Response
    {
        $unitId = (int) $request->input('inventory_unit_id', 0);
        $policyId = $request->input('warranty_policy_id');
        $policyId = $policyId !== '' && $policyId !== null ? (int) $policyId : null;
        $notes = trim((string) $request->input('notes', '')) ?: null;
        try {
            $svc = new WarrantyService();
            $result = $svc->createRegistration($unitId, $policyId, current_user_id(), $notes);
            flash_success('Warranty ' . $result['registration_number'] . ' registered. Valid ' . $result['start_date'] . ' → ' . $result['end_date'] . '.');
            return Response::redirect(url('/admin/warranty/registrations/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/warranty/registrations'));
        }
    }

    public function registrationVoid(string $id, Request $request): Response
    {
        $reason = trim((string) $request->input('reason', ''));
        try {
            (new WarrantyService())->voidRegistration((int) $id, current_user_id(), $reason);
            flash_success('Warranty voided.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/warranty/registrations/' . $id));
    }

    // ---------------------------------------------------- CLAIMS --

    public function claimIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new WarrantyService();
        $result = $svc->searchClaims(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/warranty/claims/index', [
            'pageTitle' => 'Warranty claims',
            'claims' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'statuses' => WarrantyService::CLAIM_STATUSES,
        ]));
    }

    public function claimShow(string $id): Response
    {
        $claim = (new WarrantyService())->findClaim((int) $id);
        if ($claim === null) {
            throw new NotFoundException('Warranty claim not found.');
        }
        return Response::html(View::render('admin/warranty/claims/show', [
            'pageTitle' => 'Claim ' . $claim['claim_number'],
            'claim' => $claim,
        ]));
    }

    public function claimStore(Request $request): Response
    {
        $regId = (int) $request->input('warranty_registration_id', 0);
        $claimType = (string) $request->input('claim_type', 'repair');
        $description = trim((string) $request->input('description', '')) ?: null;
        $issue = trim((string) $request->input('customer_reported_issue', ''));
        try {
            $svc = new WarrantyService();
            $result = $svc->createClaim($regId, $claimType, $description, $issue, current_user_id());
            flash_success('Claim ' . $result['claim_number'] . ' filed.');
            return Response::redirect(url('/admin/warranty/claims/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
            return Response::redirect(url('/admin/warranty/registrations/' . $regId));
        }
    }

    public function claimApprove(string $id, Request $request): Response
    {
        return $this->claimDecide($id, 'approve', $request);
    }

    public function claimReject(string $id, Request $request): Response
    {
        return $this->claimDecide($id, 'reject', $request);
    }

    public function claimStart(string $id): Response
    {
        try {
            (new WarrantyService())->startClaim((int) $id, current_user_id());
            flash_success('Claim marked as in progress.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/warranty/claims/' . $id));
    }

    public function claimComplete(string $id, Request $request): Response
    {
        $notes = trim((string) $request->input('resolution_notes', '')) ?: null;
        try {
            (new WarrantyService())->completeClaim((int) $id, current_user_id(), $notes);
            flash_success('Claim completed.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/warranty/claims/' . $id));
    }

    public function claimCancel(string $id, Request $request): Response
    {
        $reason = trim((string) $request->input('reason', '')) ?: null;
        try {
            (new WarrantyService())->cancelClaim((int) $id, current_user_id(), $reason);
            flash_success('Claim cancelled.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/warranty/claims/' . $id));
    }

    private function claimDecide(string $id, string $action, Request $request): Response
    {
        $reason = trim((string) $request->input('reason', ''));
        try {
            $svc = new WarrantyService();
            if ($action === 'approve') {
                $result = $svc->approveClaim((int) $id, current_user_id(), $reason ?: null);
            } else {
                $result = $svc->rejectClaim((int) $id, current_user_id(), $reason);
            }
            flash_success('Claim ' . $result['status'] . '.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/warranty/claims/' . $id));
    }
}
