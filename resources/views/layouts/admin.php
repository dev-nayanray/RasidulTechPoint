<?php
/**
 * Administration shell: responsive sidebar (permission-aware), top bar with
 * search entry point + notification indicator + profile menu, breadcrumbs,
 * and the main content area. Mobile uses a slide-in drawer.
 */
$auth = current_user();
$unreadNotifications = $auth ? \App\Services\NotificationService::unreadCount((int) $auth['id']) : 0;
$currentPath = $_SERVER['REQUEST_URI'] ?? '/';
$currentPath = '/' . trim(parse_url($currentPath, PHP_URL_PATH) ?: '/', '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Admin') ?> — <?= e(\App\Support\Config::get('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="flex min-h-screen">
        <!-- Mobile drawer backdrop -->
        <div class="fixed inset-0 z-40 hidden bg-slate-900/50 lg:hidden" data-sidebar-backdrop></div>

        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-50 w-72 -translate-x-full overflow-y-auto bg-navy-700 text-slate-200 transition-transform lg:static lg:translate-x-0 lg:shrink-0" data-sidebar aria-label="Admin navigation">
            <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm font-bold text-white">RTP</span>
                <span class="leading-tight">
                    <span class="block text-sm font-semibold text-white"><?= e(\App\Support\Config::get('app.name')) ?></span>
                    <span class="block text-[11px] text-slate-400">Management console</span>
                </span>
                <button type="button" class="ml-auto lg:hidden rounded-lg p-1.5 hover:bg-white/10" data-nav-toggle aria-label="Close navigation">
                    <?= \App\Support\View::component('icon', ['name' => 'x', 'class' => 'h-5 w-5']) ?>
                </button>
            </div>

            <nav class="px-3 py-4 space-y-4 text-sm" aria-label="Sections">
                <?php foreach (RTP_ADMIN_NAV as $section => $items): ?>
                    <?php
                    $visible = array_filter($items, fn ($item) => \App\Services\AuthService::can($item[0]));
                    if ($visible === []) {
                        continue;
                    }
                    ?>
                    <div>
                        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400"><?= e($section) ?></p>
                        <ul class="space-y-0.5">
                            <?php foreach ($visible as [$permission, $label, $href, $icon, $implemented]): ?>
                                <?php $active = str_starts_with($currentPath, $href); ?>
                                <li>
                                    <?php if ($implemented): ?>
                                        <a href="<?= e(url($href)) ?>"
                                           class="flex items-center gap-3 rounded-lg px-3 py-2 font-medium <?= $active ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' ?>"
                                           <?= $active ? 'aria-current="page"' : '' ?>>
                                            <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-4 w-4 opacity-80']) ?>
                                            <?= e($label) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-500 cursor-not-allowed" title="Coming in a later development phase">
                                            <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-4 w-4 opacity-50']) ?>
                                            <span class="flex-1"><?= e($label) ?></span>
                                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] font-medium tracking-wide text-slate-500">SOON</span>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </nav>

            <div class="border-t border-white/10 px-5 py-4 text-[11px] text-slate-500">
                v<?= e(\App\Support\Config::get('app.version')) ?>
            </div>
        </aside>

        <!-- Main column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 sm:px-6">
                <button type="button" class="lg:hidden inline-flex items-center justify-center rounded-lg p-2 text-slate-600 hover:bg-slate-100" data-nav-toggle aria-label="Open navigation">
                    <?= \App\Support\View::component('icon', ['name' => 'menu', 'class' => 'h-5 w-5']) ?>
                </button>

                <h1 class="text-base font-semibold text-slate-900 truncate"><?= e($pageTitle ?? 'Admin') ?></h1>

                <div class="ml-auto flex items-center gap-2">
                    <form action="<?= e(url('/admin/products')) ?>" method="get" class="hidden md:block" role="search">
                        <label class="sr-only" for="global-search">Search laptop models</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <?= \App\Support\View::component('icon', ['name' => 'search', 'class' => 'h-4 w-4']) ?>
                            </span>
                            <input id="global-search" name="q" value="" placeholder="Search models…" class="input pl-9 w-56">
                        </div>
                    </form>

                    <?php if (\App\Services\AuthService::can('notifications.view')): ?>
                        <a href="<?= e(url('/admin/notifications')) ?>" class="relative inline-flex items-center justify-center rounded-lg p-2 text-slate-600 hover:bg-slate-100" aria-label="Notifications<?= $unreadNotifications > 0 ? " ({$unreadNotifications} unread)" : '' ?>">
                            <?= \App\Support\View::component('icon', ['name' => 'bell', 'class' => 'h-5 w-5']) ?>
                            <?php if ($unreadNotifications > 0): ?>
                                <span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] font-semibold text-white"><?= $unreadNotifications > 9 ? '9+' : $unreadNotifications ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <div class="relative" data-dropdown>
                        <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100" data-dropdown-toggle aria-haspopup="true" aria-expanded="false">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-navy-700 text-xs font-semibold text-white"><?= e(strtoupper(mb_substr($auth['name'] ?? '?', 0, 1))) ?></span>
                            <span class="hidden sm:block max-w-[10rem] truncate"><?= e($auth['name'] ?? '') ?></span>
                            <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 opacity-60']) ?>
                        </button>
                        <div class="hidden absolute right-0 mt-2 w-56 rounded-lg border border-slate-200 bg-white py-1 shadow-lg" data-dropdown-menu>
                            <div class="px-3 py-2 border-b border-slate-100">
                                <p class="truncate text-sm font-medium text-slate-900"><?= e($auth['name'] ?? '') ?></p>
                                <p class="truncate text-xs text-slate-500"><?= e($auth['email'] ?? '') ?></p>
                            </div>
                            <a class="block px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" href="<?= e(url('/account/profile')) ?>">My profile</a>
                            <a class="block px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" href="<?= e(url('/')) ?>">View storefront</a>
                            <form method="post" action="<?= e(url('/logout')) ?>" class="border-t border-slate-100 mt-1 pt-1">
                                <?= csrf_field() ?>
                                <button type="submit" class="block w-full px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">Sign out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6">
                <?= \App\Support\View::component('flash') ?>
                <?= $content ?? '' ?>
            </main>

            <footer class="border-t border-slate-200 px-6 py-3 text-center text-xs text-slate-400">
                <?= e(\App\Support\Config::get('app.name')) ?> — v<?= e(\App\Support\Config::get('app.version')) ?>
            </footer>
        </div>
    </div>

    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
