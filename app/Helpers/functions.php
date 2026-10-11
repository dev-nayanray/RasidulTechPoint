<?php

declare(strict_types=1);

/**
 * Global helper functions used across templates, controllers and services.
 */

if (!function_exists('e')) {
    /** HTML-escape a value for safe output. */
    function e(mixed $value): string
    {
        return \App\Support\View::e($value);
    }
}

if (!function_exists('url')) {
    /** Absolute URL for a site path. */
    function url(string $path = '/'): string
    {
        $base = rtrim((string) \App\Support\Config::get('app.url', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to): \App\Support\Response
    {
        return \App\Support\Response::redirect($to);
    }
}

if (!function_exists('back')) {
    /** Redirect back to the referring page (or a fallback). */
    function back(string $fallback = '/'): \App\Support\Response
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        // Only follow same-host referers to avoid open-redirect style surprises.
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? null)) {
            return \App\Support\Response::redirect($ref);
        }
        return \App\Support\Response::redirect($fallback);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return \App\Support\Csrf::field();
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return \App\Support\Session::old($key, $default);
    }
}

if (!function_exists('money')) {
    /** Format a decimal money value in BDT style. */
    function money(mixed $amount, ?string $currency = null): string
    {
        $currency ??= (string) \App\Support\Config::get('app.currency', 'BDT');
        return $currency . ' ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('local_datetime')) {
    /** Convert a stored UTC datetime to the configured presentation timezone. */
    function local_datetime(?string $utcDatetime, string $format = 'd M Y, g:i a'): string
    {
        if ($utcDatetime === null || $utcDatetime === '') {
            return '—';
        }
        try {
            $tz = new \DateTimeZone((string) \App\Support\Config::get('app.timezone', 'UTC'));
            $dt = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            return $dt->format($format);
        } catch (\Exception) {
            return $utcDatetime;
        }
    }
}

if (!function_exists('utc_now')) {
    function utc_now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('str_snake')) {
    function str_snake(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? $value;
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value;
        return strtolower(trim($value, '_'));
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-') ?: bin2hex(random_bytes(4));
    }
}

if (!function_exists('flash_success')) {
    function flash_success(string $message): void
    {
        \App\Support\Session::flash('success', $message);
    }
}

if (!function_exists('flash_error')) {
    function flash_error(string $message): void
    {
        \App\Support\Session::flash('error', $message);
    }
}

if (!function_exists('app_audit')) {
    /**
     * Fire-and-forget audit logging helper (never throws into a response path).
     */
    function app_audit(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $metadata = [],
        ?\App\Support\Request $request = null,
    ): void {
        try {
            \App\Services\AuditService::record($action, $entityType, $entityId, $metadata, $request);
        } catch (\Throwable $e) {
            \App\Support\Logger::error('audit', 'Failed to write audit entry: ' . $e->getMessage(), [
                'action' => $action,
            ]);
        }
    }
}

if (!function_exists('current_user')) {
    /** @return array<string,mixed>|null authenticated user row */
    function current_user(): ?array
    {
        return \App\Services\AuthService::user();
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): int
    {
        return (int) (\App\Services\AuthService::id() ?? 0);
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return \App\Services\AuthService::can($permission);
    }
}

if (!function_exists('admin_breadcrumbs')) {
    /**
     * Auto-derive admin breadcrumbs from the nav config + current path.
     * A controller may pass its own $breadcrumbs array to override.
     *
     * @param string|null $pageTitle current page title appended as the last crumb
     * @return list<array{0:string,1:?string}> [label, url] pairs; url null = current page
     */
    function admin_breadcrumbs(?string $pageTitle = null): array
    {
        $path = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/admin/dashboard', PHP_URL_PATH) ?: '/', '/');
        if (!str_starts_with($path, '/admin')) {
            return [];
        }

        $items = [['Dashboard', '/admin/dashboard']];

        // Longest matching nav item defines section + item.
        $best = null;
        $bestLen = 0;
        foreach (RTP_ADMIN_NAV as $section => $entries) {
            foreach ($entries as $entry) {
                [$permission, $label, $href] = $entry;
                if (str_starts_with($path, $href) && strlen($href) > $bestLen) {
                    $best = [$section, $label, $href];
                    $bestLen = strlen($href);
                }
            }
        }

        if ($best !== null && $best[2] !== '/admin/dashboard') {
            [$section, $label, $href] = $best;
            $items[] = [$section, null];
            if ($href !== $path) {
                $items[] = [$label, $href];
                if ($pageTitle !== null && $pageTitle !== $label) {
                    $items[] = [$pageTitle, null];
                }
            } elseif ($pageTitle !== null && $pageTitle !== $label) {
                $items[] = [$pageTitle, null];
            }
        } elseif ($pageTitle !== null && $pageTitle !== 'Dashboard') {
            $items[] = [$pageTitle, null];
        }

        return $items;
    }
}

if (!function_exists('paginate')) {
    /**
     * Clamp pagination input.
     * @return array{0:int,1:int} [page, perPage]
     */
    function paginate(mixed $page, mixed $perPage, int $defaultPerPage = 20, int $maxPerPage = 100): array
    {
        $page = max(1, (int) $page);
        $perPage = min($maxPerPage, max(1, (int) ($perPage ?: $defaultPerPage)));
        return [$page, $perPage];
    }
}
