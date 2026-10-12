<?php
/** Customer panel layout: sidebar nav (desktop) + drawer (mobile). */
$auth = current_user();
$customerId = 0;
$customerName = $auth['name'] ?? 'Customer';
if ($auth) {
    $repo = new \App\Repositories\CustomerRepository();
    $customer = $repo->findByUserId((int) $auth['id']);
    if ($customer) {
        $customerId = (int) $customer['id'];
        $customerName = $customer['name'];
    }
}
$unreadNotifications = $customerId > 0 ? \App\Services\NotificationService::unreadCount((int) $auth['id']) : 0;
$currentPath = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');

// Sidebar nav items: [path, label, icon, badge_count?]
$navItems = [
    ['/account', 'Dashboard', 'chart', null],
    ['/account/orders', 'My Orders', 'clipboard', null],
    ['/account/warranty', 'Warranty', 'shield', null],
    ['/account/service-jobs', 'Repairs & Service', 'wrench', null],
    ['/account/addresses', 'Address Book', 'map-pin', null],
    ['/account/wishlist', 'Wishlist', 'heart', null],
    ['/account/notifications', 'Notifications', 'bell', $unreadNotifications > 0 ? $unreadNotifications : null],
    ['/account/profile', 'Profile', 'user', null],
    ['/account/support', 'Support', 'megaphone', null],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'My Account') ?> — <?= e(\App\Support\Config::get('app.name')) ?></title>
    <?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 flex flex-col font-sans antialiased">

    <?php /* Announcement bar (shared with storefront) */ ?>
    <div class="bg-navy-800 text-white text-xs sm:text-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 flex items-center justify-center gap-2 h-9 text-center">
            <span class="font-medium tracking-wide">Authentic Dubai Imports</span>
            <span class="text-slate-400">|</span>
            <span class="font-medium tracking-wide">Quality Checked</span>
            <span class="text-slate-400">|</span>
            <span class="font-medium tracking-wide">Warranty Available</span>
        </div>
    </div>

    <?php /* Compact customer header */ ?>
    <header class="bg-navy-700 text-white sticky top-0 z-40 shadow-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="flex h-14 items-center gap-3">
                <?php /* Mobile nav toggle */ ?>
                <button type="button" class="lg:hidden inline-flex items-center justify-center rounded-lg p-2 hover:bg-white/10" data-customer-nav-toggle aria-label="Toggle navigation" aria-expanded="false">
                    <?= \App\Support\View::component('icon', ['name' => 'menu', 'class' => 'h-5 w-5']) ?>
                </button>

                <a href="<?= e(url('/account')) ?>" class="flex items-center gap-2 shrink-0">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 text-xs font-bold">RTP</span>
                    <span class="hidden sm:block text-sm font-semibold">My Account</span>
                </a>

                <div class="flex-1"></div>

                <?php /* Notifications bell */ ?>
                <a href="<?= e(url('/account/notifications')) ?>" class="relative inline-flex items-center justify-center rounded-lg p-2 hover:bg-white/10" aria-label="Notifications">
                    <?= \App\Support\View::component('icon', ['name' => 'bell', 'class' => 'h-5 w-5']) ?>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="absolute -top-0.5 -right-0.5 h-4 min-w-4 rounded-full bg-brand-green text-white text-[10px] font-bold leading-4 px-1"><?= $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <?php /* Cart icon (link to storefront cart) */ ?>
                <a href="<?= e(url('/cart')) ?>" class="inline-flex items-center justify-center rounded-lg p-2 hover:bg-white/10" aria-label="Cart">
                    <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-5 w-5']) ?>
                </a>

                <?php /* Account menu */ ?>
                <div class="relative" data-account-menu>
                    <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-white/10" data-account-toggle>
                        <span class="hidden sm:block text-sm font-medium"><?= e($customerName) ?></span>
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-xs font-bold">
                            <?= e(strtoupper(substr($customerName, 0, 1))) ?>
                        </span>
                    </button>
                    <div class="hidden absolute right-0 mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg" data-account-dropdown>
                        <a href="<?= e(url('/account/profile')) ?>" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Profile</a>
                        <a href="<?= e(url('/shop')) ?>" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Shop Laptops</a>
                        <?php if (\App\Services\AuthService::can('dashboard.view')): ?>
                            <a href="<?= e(url('/admin/dashboard')) ?>" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 border-t border-slate-100">Admin Console</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/logout')) ?>" class="border-t border-slate-100">
                            <?= csrf_field() ?>
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="mx-auto max-w-7xl w-full flex-1 flex">
        <?php /* ====== Sidebar (desktop) ====== */ ?>
        <aside class="hidden lg:block w-60 shrink-0 border-r border-slate-200 bg-white" data-customer-sidebar>
            <nav class="p-3 space-y-0.5 text-sm" aria-label="Customer navigation">
                <?php foreach ($navItems as [$path, $label, $icon, $badge]): ?>
                    <?php $active = $currentPath === $path || ($path !== '/account' && str_starts_with($currentPath, $path)); ?>
                    <a href="<?= e(url($path)) ?>"
                       class="flex items-center gap-3 rounded-lg px-3 py-2 font-medium <?= $active ? 'bg-brand-blue/10 text-brand-blue' : 'text-slate-600 hover:bg-slate-50' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-4 w-4 shrink-0']) ?>
                        <span class="flex-1"><?= e($label) ?></span>
                        <?php if ($badge !== null): ?>
                            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-green text-white text-[10px] font-bold px-1"><?= (int) $badge ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <?php /* ====== Mobile sidebar (drawer) ====== */ ?>
        <div class="lg:hidden fixed inset-0 z-40 hidden bg-slate-900/50" data-customer-nav-backdrop></div>
        <aside class="lg:hidden fixed inset-y-0 left-0 z-50 w-72 -translate-x-full overflow-y-auto bg-white shadow-xl transition-transform" data-customer-nav-drawer>
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <span class="font-semibold text-navy-700">My Account</span>
                <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" data-customer-nav-close>
                    <?= \App\Support\View::component('icon', ['name' => 'x', 'class' => 'h-5 w-5']) ?>
                </button>
            </div>
            <nav class="p-3 space-y-0.5 text-sm" aria-label="Customer mobile navigation">
                <?php foreach ($navItems as [$path, $label, $icon, $badge]): ?>
                    <?php $active = $currentPath === $path || ($path !== '/account' && str_starts_with($currentPath, $path)); ?>
                    <a href="<?= e(url($path)) ?>"
                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium <?= $active ? 'bg-brand-blue/10 text-brand-blue' : 'text-slate-600 hover:bg-slate-50' ?>">
                        <?= \App\Support\View::component('icon', ['name' => $icon, 'class' => 'h-4 w-4 shrink-0']) ?>
                        <span class="flex-1"><?= e($label) ?></span>
                        <?php if ($badge !== null): ?>
                            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-green text-white text-[10px] font-bold px-1"><?= (int) $badge ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <?php /* ====== Main content ====== */ ?>
        <main class="flex-1 min-w-0 bg-slate-100">
            <div class="p-4 sm:p-6 lg:p-8">
                <?= \App\Support\View::component('flash') ?>
                <?= $content ?? '' ?>
            </div>
        </main>
    </div>

    <?php /* Footer */ ?>
    <footer class="bg-navy-700 text-slate-400">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 py-4 text-center text-xs">
            © <?= date('Y') ?> Rasidul Tech Point — Dubai Imported | Authentic Used Laptops
            · <a href="<?= e(url('/')) ?>" class="hover:text-white">Storefront</a>
            · <a href="<?= e(url('/account/support')) ?>" class="hover:text-white">Support</a>
        </div>
    </footer>

    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
    <script>
        // Mobile sidebar drawer toggle.
        const navToggle = document.querySelector('[data-customer-nav-toggle]');
        const navDrawer = document.querySelector('[data-customer-nav-drawer]');
        const navBackdrop = document.querySelector('[data-customer-nav-backdrop]');
        const navClose = document.querySelector('[data-customer-nav-close]');
        function openNav() {
            navDrawer?.classList.remove('-translate-x-full');
            navBackdrop?.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        function closeNav() {
            navDrawer?.classList.add('-translate-x-full');
            navBackdrop?.classList.add('hidden');
            document.body.style.overflow = '';
        }
        navToggle?.addEventListener('click', openNav);
        navClose?.addEventListener('click', closeNav);
        navBackdrop?.addEventListener('click', closeNav);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeNav(); });

        // Account dropdown toggle.
        const acctToggle = document.querySelector('[data-account-toggle]');
        const acctDropdown = document.querySelector('[data-account-dropdown]');
        acctToggle?.addEventListener('click', (e) => {
            e.stopPropagation();
            acctDropdown?.classList.toggle('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!acctDropdown?.contains(e.target) && !acctToggle?.contains(e.target)) {
                acctDropdown?.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
