<?php
/**
 * Administration shell — shared Super Admin interface.
 *
 * - Collapsible sidebar (desktop icon-rail, persisted per browser; mobile drawer).
 * - Permission-aware navigation from RTP_ADMIN_NAV; unimplemented modules are
 *   clearly disabled ("SOON") and never render as fake pages.
 * - Top bar: page title, global search, notification dropdown (live data),
 *   account menu. Breadcrumbs auto-derive from the current route.
 */
$auth = current_user();
$unreadNotifications = $auth ? \App\Services\NotificationService::unreadCount((int) $auth['id']) : 0;
$menuNotifications = $auth ? \App\Services\NotificationService::menuItems((int) $auth['id']) : [];
$currentPath = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14243A">
    <title><?= e($pageTitle ?? 'Admin') ?> — <?= e(\App\Support\Config::get('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <?php include BASE_PATH . '/resources/views/admin/_design_css.php'; ?>
    <script>
        // Apply the persisted sidebar state before first paint to avoid a flash.
        try {
            if (localStorage.getItem('rtp-sidebar-collapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (e) { /* storage unavailable */ }
    </script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:m-2 focus:rounded-lg focus:bg-navy-700 focus:px-4 focus:py-2 focus:text-white">Skip to content</a>

    <div class="flex min-h-screen">
        <!-- Mobile drawer backdrop -->
        <div class="fixed inset-0 z-40 hidden bg-slate-900/50 lg:hidden" data-sidebar-backdrop></div>

        <!-- Sidebar — sticky on desktop, drawer on mobile. Internal scroll for long nav. -->
        <aside class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-navy-700 text-slate-200 transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0" data-sidebar aria-label="Admin navigation">
            <!-- Brand header — sticky at top of sidebar -->
            <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5 bg-navy-800/40 backdrop-blur-sm">
                <a href="<?= e(url('/admin/dashboard')) ?>" class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/10 hover:bg-white/20 transition-colors" title="Dashboard" aria-label="RTP home">
                    <img src="<?= e(url('assets/img/logo-mark.svg')) ?>" width="100%" height="100%" alt="RTP" style="display:block;border-radius:inherit">
                </a>
                <span class="min-w-0 leading-tight" data-sidebar-label>
                    <span class="block truncate text-sm font-semibold text-white"><?= e(\App\Support\Config::get('app.name')) ?></span>
                    <span class="block text-[11px] text-slate-400">Management console</span>
                </span>
                <button type="button"
                        class="ml-auto hidden rounded-lg p-1.5 text-slate-400 hover:bg-white/10 hover:text-white lg:block"
                        data-sidebar-collapse
                        aria-label="Collapse sidebar"
                        aria-expanded="true"
                        title="Collapse sidebar">
                    <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?>
                </button>
                <button type="button" class="ml-auto rounded-lg p-1.5 hover:bg-white/10 lg:hidden" data-nav-toggle aria-label="Close navigation">
                    <?= \App\Support\View::component('icon', ['name' => 'x', 'class' => 'h-5 w-5']) ?>
                </button>
            </div>

            <!-- Scrollable nav area — independent vertical scroll when nav is long -->
            <nav class="rtp-admin-nav flex-1 overflow-y-auto px-3 py-4 space-y-5 text-sm" aria-label="Sections">
                <?php foreach (RTP_ADMIN_NAV as $section => $items): ?>
                    <?php
                    $visible = array_filter($items, fn ($item) => \App\Services\AuthService::can($item[0]));
                    if ($visible === []) {
                        continue;
                    }
                    ?>
                    <div class="rtp-nav-section">
                        <p class="rtp-nav-section-label px-3 pb-1.5 text-[10px] font-bold uppercase tracking-[.12em] text-slate-500" data-sidebar-section><?= e($section) ?></p>
                        <ul class="space-y-0.5">
                            <?php foreach ($visible as [$permission, $label, $href, $icon, $implemented]): ?>
                                <?php $active = str_starts_with($currentPath, $href); ?>
                                <li>
                                    <?php if ($implemented): ?>
                                        <a href="<?= e(url($href)) ?>"
                                           class="rtp-nav-link group flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition-all duration-150 <?= $active ? 'rtp-nav-active is-active' : 'text-slate-300 hover:bg-white/5 hover:text-white' ?>"
                                           title="<?= e($label) ?>"
                                           <?= $active ? 'aria-current="page"' : '' ?>>
                                            <span class="rtp-nav-icon shrink-0 opacity-80 transition-colors">
                                                <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-4 w-4']) ?>
                                            </span>
                                            <span class="truncate" data-sidebar-label><?= e($label) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-500 cursor-not-allowed" title="Coming in a later development phase">
                                            <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-4 w-4 shrink-0 opacity-50']) ?>
                                            <span class="flex-1 truncate" data-sidebar-label><?= e($label) ?></span>
                                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] font-medium tracking-wide text-slate-500" data-sidebar-label>SOON</span>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </nav>

            <!-- Footer — pinned at bottom of sidebar -->
            <div class="rtp-admin-foot shrink-0 border-t border-white/10 px-5 py-3 text-[11px] text-slate-500" data-sidebar-label>
                <span class="flex items-center gap-2">
                    <span class="h-1.5 w-1.5 rounded-full bg-green-400"></span>
                    v<?= e(\App\Support\Config::get('app.version')) ?>
                </span>
            </div>
        </aside>

        <!-- Expand handle (desktop, collapsed state only) -->
        <button type="button"
                class="fixed left-0 top-20 z-30 hidden rounded-r-lg border border-l-0 border-slate-300 bg-white px-1 py-4 text-slate-500 shadow-sm hover:text-navy-700 lg:block"
                data-sidebar-expand
                aria-label="Expand sidebar"
                title="Expand sidebar">
            <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?>
        </button>

        <!-- Main column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 sm:px-6">
                <button type="button" class="lg:hidden inline-flex items-center justify-center rounded-lg p-2 text-slate-600 hover:bg-slate-100" data-nav-toggle aria-label="Open navigation">
                    <?= \App\Support\View::component('icon', ['name' => 'menu', 'class' => 'h-5 w-5']) ?>
                </button>

                <h1 class="min-w-0 truncate text-base font-semibold text-slate-900"><?= e($pageTitle ?? 'Admin') ?></h1>

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

                    <!-- Mobile search toggle: reveals an inline search panel below the header -->
                    <button type="button"
                            class="md:hidden inline-flex items-center justify-center rounded-lg p-2 text-slate-600 hover:bg-slate-100"
                            data-mobile-search-toggle
                            aria-label="Search laptop models"
                            aria-expanded="false"
                            aria-controls="mobile-search-panel">
                        <?= \App\Support\View::component('icon', ['name' => 'search', 'class' => 'h-5 w-5']) ?>
                    </button>

                    <?php if (\App\Services\AuthService::can('notifications.view')): ?>
                        <div class="relative" data-dropdown>
                            <button type="button" class="relative inline-flex items-center justify-center rounded-lg p-2 text-slate-600 hover:bg-slate-100" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-label="Notifications<?= $unreadNotifications > 0 ? " ({$unreadNotifications} unread)" : '' ?>">
                                <?= \App\Support\View::component('icon', ['name' => 'bell', 'class' => 'h-5 w-5']) ?>
                                <?php if ($unreadNotifications > 0): ?>
                                    <span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] font-semibold text-white"><?= $unreadNotifications > 9 ? '9+' : $unreadNotifications ?></span>
                                <?php endif; ?>
                            </button>
                            <div class="hidden absolute right-0 mt-2 w-80 rounded-lg border border-slate-200 bg-white shadow-lg" data-dropdown-menu>
                                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                                    <p class="text-sm font-semibold text-slate-900">Notifications</p>
                                    <?php if ($unreadNotifications > 0): ?>
                                        <form method="post" action="<?= e(url('/admin/notifications/read-all')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="text-xs font-medium text-blue-700 hover:text-blue-800">Mark all read</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if ($menuNotifications === []): ?>
                                    <p class="px-4 py-8 text-center text-sm text-slate-400">You're all caught up.</p>
                                <?php else: ?>
                                    <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                                        <?php foreach ($menuNotifications as $notification): ?>
                                            <li class="flex items-start gap-3 px-4 py-3 text-sm <?= (int) $notification['is_read'] === 0 ? 'bg-blue-50/40' : '' ?>">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= (int) $notification['is_read'] === 0 ? 'bg-blue-600' : 'bg-slate-200' ?>" aria-hidden="true"></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate font-medium <?= (int) $notification['is_read'] === 0 ? 'text-slate-900' : 'text-slate-500' ?>"><?= e($notification['title']) ?></p>
                                                    <p class="mt-0.5 text-xs text-slate-400"><?= e(local_datetime($notification['created_at'])) ?></p>
                                                </div>
                                                <?php if ((int) $notification['is_read'] === 0): ?>
                                                    <form method="post" action="<?= e(url('/admin/notifications/' . $notification['id'] . '/read')) ?>">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="shrink-0 text-xs font-medium text-slate-500 hover:text-slate-700">Mark read</button>
                                                    </form>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <a href="<?= e(url('/admin/notifications')) ?>" class="block border-t border-slate-100 px-4 py-2.5 text-center text-sm font-medium text-blue-700 hover:bg-slate-50">View all notifications</a>
                            </div>
                        </div>
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

            <!-- Mobile search panel: slides down under the header when toggled -->
            <div id="mobile-search-panel" class="hidden border-b border-slate-200 bg-white px-4 py-3 md:hidden" data-mobile-search-panel>
                <form action="<?= e(url('/admin/products')) ?>" method="get" role="search" data-loading-form>
                    <label class="sr-only" for="mobile-search-input">Search laptop models</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                            <?= \App\Support\View::component('icon', ['name' => 'search', 'class' => 'h-4 w-4']) ?>
                        </span>
                        <input id="mobile-search-input" name="q" value="" placeholder="Search laptop models…" class="input pl-9" autocomplete="off">
                    </div>
                </form>
            </div>

            <main id="main-content" class="flex-1 p-4 sm:p-6">
                <?= \App\Support\View::component('breadcrumbs', ['items' => $breadcrumbs ?? null, 'pageTitle' => $pageTitle ?? null]) ?>
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
