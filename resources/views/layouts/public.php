<?php
/** Storefront layout: announcement bar, premium header (search + cart + wishlist), footer. */
$auth = current_user();
// Cart count: read straight from the session — the CartService (Prompt 41+)
// writes $_SESSION['cart_count'] whenever the cart changes. Until that
// module ships, the badge stays hidden (count = 0).
$cartCount = (int) ($_SESSION['cart_count'] ?? 0);
$wishlistCount = (int) ($_SESSION['wishlist_count'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($metaTitle ?? ($pageTitle ?? 'Welcome') . ' — ' . \App\Support\Config::get('app.name')) ?></title>
    <?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
    <meta name="theme-color" content="#14243A">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 flex flex-col font-sans antialiased">

    <?php /* ====== A. Top announcement bar ====== */ ?>
    <div class="bg-navy-800 text-white text-xs sm:text-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 flex items-center justify-center gap-2 h-9 text-center">
            <span class="font-medium tracking-wide">Authentic Dubai Imports</span>
            <span class="text-slate-400">|</span>
            <span class="font-medium tracking-wide">Quality Checked</span>
            <span class="text-slate-400">|</span>
            <span class="font-medium tracking-wide">Warranty Available</span>
            <a href="<?= e(url('/contact')) ?>" class="ml-1 underline decoration-dotted underline-offset-2 hover:text-blue-200 hidden sm:inline">Support</a>
        </div>
    </div>

    <?php /* ====== B. Header + navigation ====== */ ?>
    <header class="bg-navy-700 text-white sticky top-0 z-40 shadow-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="flex h-16 items-center gap-4">

                <?php /* Logo + wordmark */ ?>
                <a href="<?= e(url('/')) ?>" class="flex items-center gap-2.5 shrink-0" aria-label="Rasidul Tech Point home">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-blue text-sm font-bold text-white">RTP</span>
                    <span class="hidden sm:block leading-tight">
                        <span class="block text-sm font-bold tracking-tight">Rasidul Tech Point</span>
                        <span class="block text-[10px] uppercase tracking-wider text-slate-300">Dubai Imported | Authentic Used</span>
                    </span>
                </a>

                <?php /* Prominent search — desktop only on >=lg; condensed on mobile */ ?>
                <form action="<?= e(url('/shop')) ?>" method="get" class="hidden sm:flex flex-1 max-w-xl items-center gap-0" role="search">
                    <label for="header-search" class="sr-only">Search laptops</label>
                    <input id="header-search" name="q" type="search" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search by brand, model, processor…"
                           class="w-full rounded-l-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-blue">
                    <button type="submit" class="rounded-r-lg bg-brand-blue px-4 py-2 text-sm font-semibold hover:bg-blue-700 inline-flex items-center gap-1.5" aria-label="Search">
                        <?= \App\Support\View::component('icon', ['name' => 'search', 'class' => 'h-4 w-4']) ?>
                        <span class="hidden lg:inline">Search</span>
                    </button>
                </form>

                <?php /* Desktop nav */ ?>
                <nav class="hidden lg:flex items-center gap-5 text-sm font-medium" aria-label="Main">
                    <a class="hover:text-blue-200 whitespace-nowrap" href="<?= e(url('/shop')) ?>">Shop Laptops</a>
                    <a class="hover:text-blue-200 whitespace-nowrap" href="<?= e(url('/shop?sort=brands')) ?>">Brands</a>
                    <a class="hover:text-blue-200 whitespace-nowrap" href="<?= e(url('/shop?budget=1')) ?>">Budget Deals</a>
                    <a class="hover:text-blue-200 whitespace-nowrap" href="<?= e(url('/about')) ?>">About Us</a>
                </nav>

                <?php /* Right-side icons */ ?>
                <div class="flex items-center gap-1 sm:gap-2 ml-auto lg:ml-0">
                    <?php /* Account */ ?>
                    <a href="<?= e($auth ? url('/account') : url('/login')) ?>" class="hidden sm:flex flex-col items-center rounded-lg px-2 py-1 text-center hover:bg-white/10" aria-label="<?= $auth ? 'My account' : 'Sign in' ?>">
                        <?= \App\Support\View::component('icon', ['name' => 'user', 'class' => 'h-5 w-5']) ?>
                        <span class="text-[10px] mt-0.5"><?= $auth ? 'Account' : 'Sign in' ?></span>
                    </a>

                    <?php /* Wishlist (placeholder — count shown when wishlist module ships) */ ?>
                    <a href="<?= e(url('/account#wishlist')) ?>" class="hidden sm:flex flex-col items-center rounded-lg px-2 py-1 text-center hover:bg-white/10 relative" aria-label="Wishlist">
                        <?= \App\Support\View::component('icon', ['name' => 'heart', 'class' => 'h-5 w-5']) ?>
                        <span class="text-[10px] mt-0.5">Wishlist</span>
                        <?php if ($wishlistCount > 0): ?><span class="absolute -top-0.5 -right-0.5 h-4 min-w-4 rounded-full bg-brand-blue text-[10px] font-bold leading-4 px-1"><?= (int) $wishlistCount ?></span><?php endif; ?>
                    </a>

                    <?php /* Cart */ ?>
                    <a href="<?= e(url('/cart')) ?>" class="flex flex-col items-center rounded-lg px-2 py-1 text-center hover:bg-white/10 relative" aria-label="Cart">
                        <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-5 w-5']) ?>
                        <span class="text-[10px] mt-0.5 hidden sm:inline">Cart</span>
                        <?php if ($cartCount > 0): ?><span class="absolute -top-0.5 -right-0.5 h-4 min-w-4 rounded-full bg-brand-green text-white text-[10px] font-bold leading-4 px-1"><?= (int) $cartCount ?></span><?php endif; ?>
                    </a>

                    <?php /* Mobile menu toggle */ ?>
                    <button type="button" class="lg:hidden inline-flex items-center justify-center rounded-lg p-2 hover:bg-white/10" data-nav-toggle aria-label="Toggle navigation" aria-expanded="false">
                        <?= \App\Support\View::component('icon', ['name' => 'menu', 'class' => 'h-5 w-5']) ?>
                    </button>
                </div>
            </div>

            <?php /* Mobile search — visible from sm down (replaces the desktop inline search) */ ?>
            <form action="<?= e(url('/shop')) ?>" method="get" class="sm:hidden pb-3" role="search">
                <label for="header-search-mobile" class="sr-only">Search laptops</label>
                <div class="flex items-center gap-0">
                    <input id="header-search-mobile" name="q" type="search" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search laptops…"
                           class="w-full rounded-l-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-blue">
                    <button type="submit" class="rounded-r-lg bg-brand-blue px-4 py-2 text-sm font-semibold" aria-label="Search">
                        <?= \App\Support\View::component('icon', ['name' => 'search', 'class' => 'h-4 w-4']) ?>
                    </button>
                </div>
            </form>

            <?php /* Mobile nav drawer */ ?>
            <nav class="lg:hidden hidden pb-4 flex-col gap-1 text-sm font-medium" data-nav-mobile aria-label="Mobile">
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/shop')) ?>">Shop Laptops</a>
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/shop?sort=brands')) ?>">Brands</a>
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/shop?budget=1')) ?>">Budget Deals</a>
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/about')) ?>">About Us</a>
                <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/contact')) ?>">Contact</a>
                <?php if ($auth): ?>
                    <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/account')) ?>">My Account</a>
                    <?php if (\App\Services\AuthService::can('dashboard.view')): ?>
                        <a class="rounded-lg px-3 py-2 bg-white/10 hover:bg-white/20" href="<?= e(url('/admin/dashboard')) ?>">Admin Console</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="rounded-lg px-3 py-2 hover:bg-white/10" href="<?= e(url('/login')) ?>">Sign in</a>
                    <a class="rounded-lg px-3 py-2 bg-brand-blue text-center hover:bg-blue-700" href="<?= e(url('/register')) ?>">Create account</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        <?= \App\Support\View::component('flash') ?>
        <?= $content ?? '' ?>
    </main>

    <?php /* ====== N. Footer ====== */ ?>
    <footer class="bg-navy-700 text-slate-300">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 py-10 grid gap-8 md:grid-cols-4 text-sm">
            <div class="md:col-span-1">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm font-bold text-white">RTP</span>
                    <span class="font-bold text-white">Rasidul Tech Point</span>
                </div>
                <p class="mt-3 text-slate-400 leading-relaxed">Authentic Dubai-imported used laptops. Every unit individually inspected, condition-graded, and priced fairly.</p>
            </div>

            <div>
                <p class="font-semibold text-white">Shop</p>
                <ul class="mt-3 space-y-2 text-slate-400">
                    <li><a class="hover:text-white" href="<?= e(url('/shop')) ?>">All laptops</a></li>
                    <li><a class="hover:text-white" href="<?= e(url('/shop?budget=1')) ?>">Budget deals</a></li>
                    <li><a class="hover:text-white" href="<?= e(url('/shop?sort=brands')) ?>">Browse brands</a></li>
                </ul>
            </div>

            <div>
                <p class="font-semibold text-white">Support</p>
                <ul class="mt-3 space-y-2 text-slate-400">
                    <li><a class="hover:text-white" href="<?= e(url('/contact')) ?>">Contact us</a></li>
                    <li><a class="hover:text-white" href="<?= e(url('/page/warranty')) ?>">Warranty &amp; service</a></li>
                    <li><a class="hover:text-white" href="<?= e(url('/page/returns')) ?>">Return policy</a></li>
                </ul>
            </div>

            <div>
                <p class="font-semibold text-white">About</p>
                <ul class="mt-3 space-y-2 text-slate-400">
                    <li><a class="hover:text-white" href="<?= e(url('/about')) ?>">About us</a></li>
                    <li><a class="hover:text-white" href="<?= e(url('/page/privacy')) ?>">Privacy policy</a></li>
                    <li><a class="hover:text-white" href="<?= e(url('/page/terms')) ?>">Terms</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 py-4 text-center text-xs text-slate-400">
                © <?= date('Y') ?> Rasidul Tech Point — Dubai Imported | Authentic Used Laptops | v<?= e(\App\Support\Config::get('app.version')) ?>
            </div>
        </div>
    </footer>

    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
