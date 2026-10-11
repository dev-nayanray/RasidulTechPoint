<?php

declare(strict_types=1);

/**
 * Rasidul Tech Point — front controller.
 * This is the ONLY PHP file a web server must expose (DocumentRoot: public/).
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Exceptions\CsrfTokenMismatchException;
use App\Exceptions\HttpException;
use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\AuthService;
use App\Support\Config;
use App\Support\Logger;
use App\Support\MiddlewareResolver;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Session;
use App\Support\View;

$request = Request::capture();

$isDebug = (bool) Config::get('app.debug', false);
$isProd = Config::get('app.env') === 'production';

/** Central exception → response conversion. Never leaks stack traces in production. */
function render_error(Throwable $e, Request $request, bool $isDebug, bool $isProd): Response
{
    $status = 500;
    $message = 'Something went wrong. The problem has been logged.';
    $report = true;

    if ($e instanceof HttpException) {
        $status = $e->status();
        $message = $e->getMessage();
        $report = $e->report;
    } elseif ($e instanceof PDOException) {
        $status = 500;
        $message = 'A database error occurred.';
    }

    if ($report) {
        Logger::error('app', get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'correlation_id' => $request->correlationId(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);
    }

    if ($request->wantsJson()) {
        $payload = ['error' => $message, 'status' => $status];
        if (!$isProd && $isDebug) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ];
        }
        return Response::json($payload, $status);
    }

    try {
        $body = View::render('errors/generic', [
            'pageTitle' => 'Error ' . $status,
            'status' => $status,
            'message' => $message,
            'debug' => $isProd ? null : [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $isDebug ? explode("\n", $e->getTraceAsString()) : [],
                'correlation_id' => $request->correlationId(),
            ],
        ], layout: 'layouts/bare');
        return Response::html($body, $status);
    } catch (Throwable $renderError) {
        // Template engine itself failed — fall back to a plain page.
        return Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Error</title></head><body style="font-family:sans-serif;padding:3rem">'
            . '<h1>Error ' . (int) $status . '</h1><p>'
            . e($isProd ? 'An unexpected error occurred.' : $message)
            . '</p></body></html>',
            $status
        );
    }
}

try {
    Session::start();

    // Refresh the currently authenticated user for this request.
    AuthService::boot();

    $resolver = new MiddlewareResolver();
    $resolver->register('headers', fn () => new SecurityHeadersMiddleware());
    $resolver->register('auth', fn () => new AuthMiddleware(guest: false));
    $resolver->register('guest', fn () => new AuthMiddleware(guest: true));
    $resolver->register('csrf', fn () => new CsrfMiddleware());
    $resolver->register('permission', fn () => new PermissionMiddleware());

    $router = new Router();

    // Global pipeline: security headers on every response.
    $router->group(['middleware' => ['headers']], function (Router $router): void {
        require BASE_PATH . '/routes/web.php';
    });

    $response = $router->dispatch($request, $resolver);
} catch (Throwable $e) {
    try {
        $response = render_error($e, $request, $isDebug, $isProd);
    } catch (Throwable $fatal) {
        http_response_code(500);
        echo 'Fatal application error.';
        exit(1);
    }
}

$response->send();
