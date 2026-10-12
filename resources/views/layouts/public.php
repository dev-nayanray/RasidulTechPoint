<?php
/**
 * Storefront layout — Rasidul Tech Point reference design clone.
 *
 * Header (sticky, white, search + icon rail + nav row), footer (navy,
 * link grid + legal + watermark) and mobile bottom tab bar follow the
 * approved homepage reference. All chrome is driven by real state:
 * session cart/wishlist counts, auth-aware account link, GET search
 * routed to /shop, contact details from configured settings only —
 * never fabricated.
 */
$auth = current_user();
$cartCount = (int) ($_SESSION['cart_count'] ?? 0);
$wishlistCount = (int) ($_SESSION['wishlist_count'] ?? 0);

// Configured contact details only (PageController uses the same source).
$businessPhone = (string) App\Services\SettingService::get('business_phone', '');
$businessEmail = (string) App\Services\SettingService::get('contact_email', '');
if ($businessEmail === '') {
    $businessEmail = (string) App\Services\SettingService::get('business_email', '');
}
$businessAddress = (string) App\Services\SettingService::get('business_address', '');
$businessHours = (string) App\Services\SettingService::get('business_hours', '');
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="rtp-skin min-h-screen flex flex-col" id="top">

    <?php /* ====== Announcement bar (configurable copy; quiet, honest) ====== */ ?>
    <div class="announce">
        <div class="wrap announce-in">
            <span>Dubai-imported used laptops</span>
            <span>·</span>
            <span>Inspected one unit at a time</span>
            <a href="<?= e(url('/contact')) ?>">Support</a>
        </div>
    </div>

    <?php /* ====== Header ====== */ ?>
    <header class="site-hdr">
        <div class="wrap">
            <div class="hdr">
                <button class="icon-btn burger" aria-label="Open menu" aria-expanded="false" data-nav-toggle>
                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
                </button>
                <a class="logo" href="<?= e(url('/')) ?>" aria-label="Rasidul Tech Point home">
                    <span class="logomark">
                        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path></svg>
                    </span>
                    <span>
                        <span class="wm-a">Rasidul</span>
                        <span class="wm-b">TECH POINT</span>
                    </span>
                </a>
                <form class="search" action="<?= e(url('/shop')) ?>" method="get" role="search">
                    <div class="search-box">
                        <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true" style="color: rgb(100, 116, 139);"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
                        <label class="sr-only" for="site-search">Search laptops</label>
                        <input id="site-search" type="search" name="q" placeholder="Search laptops by brand or model" value="<?= e($_GET['q'] ?? '') ?>">
                        <button class="search-go" type="submit" aria-label="Search">
                            <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
                        </button>
                    </div>
                </form>
                <div class="icons">
                    <a class="icon-btn hide-xs" href="<?= e(url($auth ? '/account' : '/login')) ?>" aria-label="<?= $auth ? 'My account' : 'Sign in' ?>">
                        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c1-4 4-6 8-6s7 2 8 6"></path></svg>
                    </a>
                    <a class="icon-btn" href="<?= e(url('/account/wishlist')) ?>" aria-label="Wishlist">
                        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>
                        <?php if ($wishlistCount > 0): ?><span class="count"><?= (int) $wishlistCount ?></span><?php endif; ?>
                    </a>
                    <a class="icon-btn" href="<?= e(url('/cart')) ?>" aria-label="Shopping cart">
                        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2.5l2 11h11l2-8H6.5"></path><circle cx="9" cy="20" r="1.3"></circle><circle cx="17" cy="20" r="1.3"></circle></svg>
                        <?php if ($cartCount > 0): ?><span class="count"><?= (int) $cartCount ?></span><?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
        <div class="nav-row">
            <div class="wrap">
                <nav class="nav-desktop" aria-label="Main">
                    <a href="<?= e(url('/shop')) ?>">Shop Laptops</a>
                    <a href="<?= e(url('/shop?sort=brands')) ?>">Brands</a>
                    <a href="<?= e(url('/shop?budget=1')) ?>">Budget Deals</a>
                    <a href="<?= e(url('/faq')) ?>">Warranty &amp; Support</a>
                    <a href="<?= e(url('/about')) ?>">About Us</a>
                    <a class="push" href="<?= e(url('/page/inspection-process')) ?>">How we check laptops</a>
                </nav>
            </div>
        </div>
        <div class="wrap">
            <nav class="nav-mobile" aria-label="Mobile" data-nav-mobile>
                <a href="<?= e(url('/shop')) ?>">Shop Laptops</a>
                <a href="<?= e(url('/shop?sort=brands')) ?>">Brands</a>
                <a href="<?= e(url('/shop?budget=1')) ?>">Budget Deals</a>
                <a href="<?= e(url('/faq')) ?>">Warranty &amp; Support</a>
                <a href="<?= e(url('/about')) ?>">About Us</a>
                <a href="<?= e(url('/page/inspection-process')) ?>">How we check laptops</a>
                <a href="<?= e(url($auth ? '/account' : '/login')) ?>"><?= $auth ? 'My account' : 'Sign in' ?></a>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        <?= \App\Support\View::component('flash') ?>
        <?= $content ?? '' ?>
    </main>

    <?php /* ====== Footer ====== */ ?>
    <footer class="foot" id="contact">
        <div class="wrap">
            <div class="foot-grid">
                <div style="display: flex; flex-direction: column; gap: 14px; align-items: flex-start;">
                    <a class="logo" href="<?= e(url('/')) ?>" aria-label="Rasidul Tech Point home">
                        <span class="logomark" style="background: rgb(255, 255, 255); color: rgb(20, 36, 58);">
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path></svg>
                        </span>
                        <span>
                            <span class="wm-a" style="color: rgb(255, 255, 255);">Rasidul</span>
                            <span class="wm-b" style="color: rgb(127, 164, 245);">TECH POINT</span>
                        </span>
                    </a>
                    <p style="max-width: 300px;">Dubai-imported used laptops, inspected one unit at a time, for Bangladesh.</p>
                </div>
                <div>
                    <h4 class="mono">Shop</h4>
                    <ul>
                        <li><a href="<?= e(url('/shop')) ?>">All laptops</a></li>
                        <li><a href="<?= e(url('/shop?budget=1')) ?>">Budget deals</a></li>
                        <li><a href="<?= e(url('/shop?sort=brands')) ?>">Browse brands</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="mono">Support</h4>
                    <ul>
                        <li><a href="<?= e(url('/contact')) ?>">Contact us</a></li>
                        <li><a href="<?= e(url('/page/warranty')) ?>">Warranty &amp; service</a></li>
                        <li><a href="<?= e(url('/page/returns')) ?>">Returns</a></li>
                        <li><a href="<?= e(url('/page/shipping')) ?>">Delivery</a></li>
                        <li><a href="<?= e(url('/page/inspection-process')) ?>">How we check laptops</a></li>
                        <li><a href="<?= e(url('/about')) ?>">About us</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="mono">Contact</h4>
                    <ul>
                        <li><?= $businessAddress !== '' ? e($businessAddress) : '<span class="ph">[Store address]</span>' ?></li>
                        <li><?= $businessHours !== '' ? e($businessHours) : '<span class="ph">[Business hours]</span>' ?></li>
                        <li><?= $businessPhone !== '' ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $businessPhone)) . '">' . e($businessPhone) . '</a>' : '<span class="ph">[Phone number]</span>' ?></li>
                        <li><?= $businessEmail !== '' ? '<a href="mailto:' . e($businessEmail) . '">' . e($businessEmail) . '</a>' : '<span class="ph">[Email address]</span>' ?></li>
                    </ul>
                </div>
            </div>
            <div class="foot-bottom">
                <span>© <?= date('Y') ?> Rasidul Tech Point. All rights reserved.</span>
                <div class="legal">
                    <a href="<?= e(url('/page/privacy')) ?>" style="color: inherit;">Privacy Policy</a>
                    <a href="<?= e(url('/page/terms')) ?>" style="color: inherit;">Terms</a>
                    <a href="<?= e(url('/page/returns')) ?>" style="color: inherit;">Return Policy</a>
                </div>
            </div>
            <div class="foot-mark" aria-hidden="true">Rasidul Tech Point</div>
        </div>
    </footer>

    <div class="tab-space"></div>
    <nav class="tabbar" aria-label="Quick navigation">
        <a class="tab" href="<?= e(url('/')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7M5 10v10h14V10"></path></svg>Home
        </a>
        <a class="tab" href="<?= e(url('/shop')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path></svg>Laptops
        </a>
        <a class="tab" href="<?= e(url('/account/wishlist')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>Wishlist
            <?php if ($wishlistCount > 0): ?><span class="count"><?= (int) $wishlistCount ?></span><?php endif; ?>
        </a>
        <a class="tab" href="<?= e(url('/cart')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2.5l2 11h11l2-8H6.5"></path><circle cx="9" cy="20" r="1.3"></circle><circle cx="17" cy="20" r="1.3"></circle></svg>Cart
            <?php if ($cartCount > 0): ?><span class="count"><?= (int) $cartCount ?></span><?php endif; ?>
        </a>
    </nav>

    <script>
        // Mobile nav drawer toggle.
        document.addEventListener('click', function (e) {
            var t = e.target.closest('[data-nav-toggle]');
            if (!t) { return; }
            var nav = document.querySelector('[data-nav-mobile]');
            if (!nav) { return; }
            var open = nav.classList.toggle('open');
            t.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    </script>
    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
