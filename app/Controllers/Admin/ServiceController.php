<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Exceptions\NotFoundException;
use App\Services\AuditService;
use App\Services\ServiceJobService;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Repairs and service jobs controller.
 *
 * Routes (under /admin/service, all service.manage):
 *   GET  /admin/service                  dashboard
 *   GET  /admin/service/jobs             list
 *   GET  /admin/service/jobs/{id}        show
 *   POST /admin/service/jobs             create
 *   POST /admin/service/jobs/{id}/assign
 *   POST /admin/service/jobs/{id}/diagnose
 *   POST /admin/service/jobs/{id}/notes
 *   POST /admin/service/jobs/{id}/parts
 *   POST /admin/service/jobs/{id}/labor
 *   POST /admin/service/jobs/{id}/transition
 *   POST /admin/service/jobs/{id}/quality-check
 *   POST /admin/service/jobs/{id}/close
 *   POST /admin/service/jobs/{id}/cancel
 */
final class ServiceController
{
    public function index(): Response
    {
        $svc = new ServiceJobService();
        $pdo = \App\Support\Database\Connection::pdo();
        $open = (int) $pdo->query("SELECT COUNT(*) FROM service_jobs WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
        $completed = (int) $pdo->query("SELECT COUNT(*) FROM service_jobs WHERE status = 'completed'")->fetchColumn();
        $cancelled = (int) $pdo->query("SELECT COUNT(*) FROM service_jobs WHERE status = 'cancelled'")->fetchColumn();
        $recent = $svc->search('', '', '', 1, 10)['rows'];

        return Response::html(View::render('admin/service/dashboard', [
            'pageTitle' => 'Service Jobs',
            'open' => $open,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'recent' => $recent,
        ]));
    }

    public function jobIndex(Request $request): Response
    {
        [$page, $perPage] = paginate($request->query('page', 1), $request->query('per_page', 25));
        $svc = new ServiceJobService();
        $result = $svc->search(
            trim((string) $request->query('q', '')),
            (string) $request->query('status', ''),
            (string) $request->query('type', ''),
            $page,
            $perPage,
        );
        return Response::html(View::render('admin/service/jobs/index', [
            'pageTitle' => 'Service Jobs',
            'jobs' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'serviceType' => (string) $request->query('type', ''),
            'statuses' => ServiceJobService::JOB_STATUSES,
            'serviceTypes' => ServiceJobService::SERVICE_TYPES,
        ]));
    }

    public function jobShow(string $id): Response
    {
        $svc = new ServiceJobService();
        $job = $svc->findJob((int) $id);
        if ($job === null) {
            throw new NotFoundException('Service job not found.');
        }
        return Response::html(View::render('admin/service/jobs/show', [
            'pageTitle' => 'Job ' . $job['job_number'],
            'job' => $job,
            'noteTypes' => ServiceJobService::NOTE_TYPES,
            'statuses' => ServiceJobService::JOB_STATUSES,
        ]));
    }

    public function jobCreateForm(Request $request): Response
    {
        return Response::html(View::render('admin/service/jobs/create', [
            'pageTitle' => 'New service job',
            'customerId' => (string) $request->query('customer_id', ''),
            'unitId' => (string) $request->query('unit_id', ''),
            'serviceTypes' => ServiceJobService::SERVICE_TYPES,
            'priorities' => ServiceJobService::PRIORITIES,
            'errors' => [],
        ]));
    }

    public function jobStore(Request $request): Response
    {
        $data = $request->post();
        try {
            $svc = new ServiceJobService();
            $result = $svc->createJob(
                !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                !empty($data['inventory_unit_id']) ? (int) $data['inventory_unit_id'] : null,
                !empty($data['warranty_claim_id']) ? (int) $data['warranty_claim_id'] : null,
                !empty($data['order_id']) ? (int) $data['order_id'] : null,
                (string) ($data['service_type'] ?? 'paid'),
                (string) ($data['reported_issue'] ?? ''),
                (string) ($data['priority'] ?? 'normal'),
                current_user_id(),
                !empty($data['estimated_cost']) ? (float) $data['estimated_cost'] : null,
            );
            flash_success('Service job ' . $result['job_number'] . ' created.');
            return Response::redirect(url('/admin/service/jobs/' . $result['id']));
        } catch (\App\Exceptions\ValidationException $e) {
            return Response::html(View::render('admin/service/jobs/create', [
                'pageTitle' => 'New service job',
                'customerId' => (string) ($data['customer_id'] ?? ''),
                'unitId' => (string) ($data['inventory_unit_id'] ?? ''),
                'serviceTypes' => ServiceJobService::SERVICE_TYPES,
                'priorities' => ServiceJobService::PRIORITIES,
                'errors' => $e->errors,
            ]), 422);
        }
    }

    public function jobAssign(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->assignTechnician(
                (int) $id,
                (int) $request->input('technician_id', 0),
                current_user_id(),
            );
            flash_success('Technician assigned.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id));
    }

    public function jobDiagnose(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->addDiagnosis(
                (int) $id,
                (string) $request->input('diagnosis', ''),
                current_user_id(),
            );
            flash_success('Diagnosis recorded.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id));
    }

    public function jobAddNote(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->addNote(
                (int) $id,
                current_user_id(),
                (string) $request->input('body', ''),
                (string) $request->input('note_type', 'internal'),
            );
            flash_success('Note added.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id) . '#notes');
    }

    public function jobAddPart(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->addPart(
                (int) $id,
                (string) $request->input('part_name', ''),
                $request->input('part_code') ?: null,
                (int) $request->input('quantity', 1),
                (float) $request->input('unit_cost', 0),
                (float) $request->input('unit_price', 0),
            );
            flash_success('Part added. Charges recalculated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id) . '#parts');
    }

    public function jobSetLabor(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->setLaborCharge(
                (int) $id,
                (float) $request->input('labor_charge', 0),
            );
            flash_success('Labor charge set.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id) . '#parts');
    }

    public function jobTransition(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->transition(
                (int) $id,
                (string) $request->input('status', ''),
                current_user_id(),
                $request->input('reason') ?: null,
            );
            flash_success('Status updated.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id));
    }

    public function jobQualityCheck(string $id, Request $request): Response
    {
        try {
            $result = (new ServiceJobService())->qualityCheck(
                (int) $id,
                current_user_id(),
                !empty($request->input('passed')),
                $request->input('notes') ?: null,
            );
            flash_success($result['passed'] ? 'Quality check passed — job completed.' : 'Quality check failed — back to repair.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id));
    }

    public function jobClose(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->closeJob(
                (int) $id,
                current_user_id(),
                $request->input('resolution_notes') ?: null,
                !empty($request->input('actual_cost')) ? (float) $request->input('actual_cost') : null,
            );
            flash_success('Job closed.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id));
    }

    public function jobCancel(string $id, Request $request): Response
    {
        try {
            (new ServiceJobService())->cancelJob(
                (int) $id,
                current_user_id(),
                (string) $request->input('reason', ''),
            );
            flash_success('Job cancelled.');
        } catch (\App\Exceptions\ValidationException $e) {
            flash_error(implode(' ', $e->errors));
        }
        return Response::redirect(url('/admin/service/jobs/' . $id));
    }
}
