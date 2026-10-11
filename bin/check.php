<?php

declare(strict_types=1);

/**
 * Structural self-check for the application foundation.
 *
 *   php bin/check.php
 *
 * Verifies — without needing the database:
 *   1. every route handler maps to an existing controller method;
 *   2. every View::render/partial/component target exists on disk;
 *   3. every relative JS module import resolves;
 *   4. compiled CSS + JS entry assets exist;
 *   5. critical foundation files are present.
 *
 * Exits non-zero when anything is missing. Safe to run in CI before tests.
 */

require dirname(__DIR__) . '/bootstrap/app.php';

$issues = [];

// ---- 1. Routes → controller methods -----------------------------------------
$router = new App\Support\Router();
$router->group(['middleware' => ['headers']], function (App\Support\Router $router): void {
    require BASE_PATH . '/routes/web.php';
});
$routes = (new ReflectionProperty($router, 'routes'))->getValue($router);
$routeCount = count($routes);

foreach ($routes as $route) {
    $handler = $route['handler'];
    if (!is_array($handler)) {
        continue; // closures carry no external dependencies to verify
    }
    [$class, $method] = $handler;
    if (!class_exists($class)) {
        $issues[] = "ROUTE: controller class missing {$class}";
        continue;
    }
    if (!method_exists($class, $method)) {
        $issues[] = "ROUTE: method missing {$class}::{$method}";
    }
}

// ---- 1b. Every IMPLEMENTED sidebar nav href must match a registered route ----
$regexes = array_map(fn ($r) => $r['regex'], $routes);
foreach (RTP_ADMIN_NAV as $section => $entries) {
    foreach ($entries as $entry) {
        [$permission, $label, $href, $icon, $implemented] = $entry;
        if (!$implemented) {
            continue;
        }
        $matched = false;
        foreach ($regexes as $regex) {
            if (preg_match($regex, $href)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $issues[] = "NAV: implemented item '{$label}' → {$href} has no matching route";
        }
    }
}

// ---- 2. View targets ----------------------------------------------------------
$checkView = function (string $template, string $dir, string $source) use (&$issues): void {
    if (!is_file(BASE_PATH . "/resources/views/{$dir}{$template}.php")) {
        $issues[] = "VIEW missing: {$dir}{$template} (referenced in {$source})";
    }
};

$scan = function (string $dir) use (&$issues, $checkView): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $dir));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        $source = str_replace(BASE_PATH . '/', '', $file->getPathname());

        preg_match_all("/View::render\(\s*'([^']+)'/", $src, $m);
        foreach ($m[1] as $tpl) {
            $checkView($tpl, '', $source);
        }
        preg_match_all("/View::partial\(\s*'([^']+)'/", $src, $m);
        foreach ($m[1] as $tpl) {
            $checkView($tpl, '', $source);
        }
        preg_match_all("/View::component\(\s*'([^']+)'/", $src, $m);
        foreach ($m[1] as $tpl) {
            $checkView($tpl, 'components/', $source);
        }
        preg_match_all("/layout:\s*'([^']+)'/", $src, $m);
        foreach ($m[1] as $tpl) {
            $checkView($tpl, '', $source);
        }
    }
};
$scan('app/Controllers');
$scan('resources/views');

$viewCount = count(glob(BASE_PATH . '/resources/views/**/*.php', GLOB_BRACE) ?: []);

// ---- 3. JS module imports ------------------------------------------------------
foreach (glob(BASE_PATH . '/public/assets/js/*.js') as $file) {
    $src = (string) file_get_contents($file);
    preg_match_all("/from\s+'(\.\/[^']+)'/", $src, $m);
    foreach ($m[1] as $import) {
        if (!is_file(BASE_PATH . '/public/assets/js/' . substr($import, 2))) {
            $issues[] = 'JS import missing: ' . basename($file) . " → {$import}";
        }
    }
}

// ---- 4. Built assets -----------------------------------------------------------
$css = BASE_PATH . '/public/assets/css/app.css';
if (!is_file($css) || filesize($css) < 10000) {
    $issues[] = 'ASSET: compiled public/assets/css/app.css missing or suspiciously small — run `npm run build`';
}
if (!is_file(BASE_PATH . '/public/assets/js/app.js')) {
    $issues[] = 'ASSET: JS entry public/assets/js/app.js missing';
}

// ---- 5. Critical foundation files ------------------------------------------------
foreach ([
    'public/index.php', 'public/router.php', 'public/.htaccess',
    'bootstrap/app.php', 'bootstrap/database.php',
    'config/app.php', 'config/database.php', 'config/security.php', 'config/permissions.php', 'config/mail.php',
    'app/Support/Router.php', 'app/Support/Request.php', 'app/Support/Response.php', 'app/Support/Session.php',
    'app/Support/Csrf.php', 'app/Support/View.php', 'app/Support/Validator.php', 'app/Support/Logger.php',
    'app/Support/Database/Connection.php', 'app/Support/Database/Migrator.php',
    'app/Middleware/AuthMiddleware.php', 'app/Middleware/PermissionMiddleware.php',
    'app/Middleware/CsrfMiddleware.php', 'app/Middleware/SecurityHeadersMiddleware.php',
    'routes/web.php', '.env.example', '.gitignore', 'composer.json', 'package.json', 'tailwind.config.js',
    'README.md', 'AGENTS.md', 'docs/SECURITY.md',
    'bin/migrate.php', 'bin/seed.php', 'bin/create-admin.php', 'bin/test.php',
] as $file) {
    if (!is_file(BASE_PATH . '/' . $file)) {
        $issues[] = "FILE missing: {$file}";
    }
}

// ---- Report --------------------------------------------------------------------
printf('ROUTES: %d | VIEWS+COMPONENTS: %d | LAYOUTS: %d' . PHP_EOL, $routeCount, $viewCount, count(glob(BASE_PATH . '/resources/views/layouts/*.php') ?: []));

if ($issues === []) {
    print('STRUCTURE: OK — foundation integrity verified.' . PHP_EOL);
    exit(0);
}
print('ISSUES:' . PHP_EOL . '- ' . implode(PHP_EOL . '- ', $issues) . PHP_EOL);
exit(1);
