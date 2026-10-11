<?php
/** Storefront layout: public header, navigation and footer. */
$auth = current_user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($metaTitle ?? ($pageTitle ?? 'Welcome') . ' — ' . \App\Support\Config::get('app.name')) ?></title>
    <?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 flex flex-col">
    <header class="bg-navy-700 text-white sticky top-0 z-40">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="flex h-16 items-center justify-between gap-4">
                <a href="<?= e(url('/')) ?>" class="flex items-center gap-3 shrink-0">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm font-bold">RTP</span>
                    <span class="hidden sm:block leading-tight">
                        <span class="block text-sm font-semibold"><?= e(\App\Support\Config::get('app.name')) ?></span>
                        <span class="block text-[11px] text-slate-300">Dubai Imported | Tested &amp; Quality Checked</span>
                    </span>
                </a>

                <nav class="hidden md:flex items-center gap-6 text-sm font-medium" aria-label="Main">
                    <a class="hover:text-slate-200" href="<?= e(url('/shop')) ?>">Shop</a>
                    <a class="hover:text-slate-200" href="<?= e(url('/about')) ?>">About</a>
                    <a class="hover:text-slate-200" href="<?= e(url('/contact')) ?>">Contact</a>
                </nav>

                <div class="flex items-center gap-3">
                    <?php if ($auth): ?>
                        <a href="<?= e(url('/account')) ?>" class="text-sm font-medium hover:text-slate-200">My account</a>
                        <?php if (\App\Services\AuthService::can('dashboard.view')): ?>
                            <a href="<?= e(url('/admin/dashboard')) ?>" class="hidden sm:inline-flex items-center rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium hover:bg-blue-700">Admin</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?= e(url('/login')) ?>" class="text-sm font-medium hover:text-slate-200">Sign in</a>
                        <a href="<?= e(url('/register')) ?>" class="inline-flex items-center rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium hover:bg-blue-700">Create account</a>
                    <?php endif; ?>
                    <button type="button" class="md:hidden inline-flex items-center justify-center rounded-lg p-2 hover:bg-white/10" data-nav-toggle aria-label="Toggle navigation" aria-expanded="false">
                        <?= \App\Support\View::component('icon', ['name' => 'menu', 'class' => 'h-5 w-5']) ?>
                    </button>
                </div>
            </div>
            <nav class="md:hidden hidden pb-4 flex-col gap-2 text-sm font-medium" data-nav-mobile aria-label="Mobile">
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/shop')) ?>">Shop</a>
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/about')) ?>">About</a>
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/contact')) ?>">Contact</a>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
            <?= \App\Support\View::component('flash') ?>
            <?= $content ?? '' ?>
        </div>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8 grid gap-6 sm:grid-cols-3 text-sm">
            <div>
                <p class="font-semibold text-slate-900"><?= e(\App\Support\Config::get('app.name')) ?></p>
                <p class="mt-1 text-slate-500">Dubai imported authentic used laptops. Every unit tested and quality checked before sale.</p>
            </div>
            <div>
                <p class="font-medium text-slate-700">Shop</p>
                <ul class="mt-2 space-y-1 text-slate-500">
                    <li><a class="hover:text-blue-700" href="<?= e(url('/shop')) ?>">All laptops</a></li>
                    <li><a class="hover:text-blue-700" href="<?= e(url('/about')) ?>">About us</a></li>
                </ul>
            </div>
            <div>
                <p class="font-medium text-slate-700">Support</p>
                <ul class="mt-2 space-y-1 text-slate-500">
                    <li><a class="hover:text-blue-700" href="<?= e(url('/contact')) ?>">Contact</a></li>
                    <li><a class="hover:text-blue-700" href="<?= e(url('/account')) ?>">Order history</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-slate-100 py-4 text-center text-xs text-slate-400">© <?= date('Y') ?> <?= e(\App\Support\Config::get('app.name')) ?> — v<?= e(\App\Support\Config::get('app.version')) ?></div>
    </footer>

    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
