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
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
    <?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
    <meta name="theme-color" content="#14243A">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <style>
<?php include BASE_PATH . '/resources/views/storefront/_design_css.php'; ?>
    </style>
</head>
<body class="rtp-skin min-h-screen flex flex-col" id="top">

    <?php /* ====== Announcement bar (configurable copy; quiet, honest) ====== */ ?>
    <div class="announce">
        <div class="wrap announce-in">
            <span>Dubai Imported | 100% Authentic Used Laptops | Tested &amp; Quality Checked</span>
            <a href="<?= e(url('/contact')) ?>">Call: 01401-400041</a>
        </div>
    </div>

    <?php /* ====== Header ====== */ ?>
    <header class="site-hdr">
        <div class="wrap">
            <div class="hdr">
                <button class="icon-btn burger" aria-label="Open menu" aria-expanded="false" data-nav-toggle>
                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
                </button>
                <?= \App\Support\View::partial('layouts/_logo', ['variant' => 'light']) ?>
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
                    <div class="dd-wrap" data-dd-wrap>
                        <button class="icon-btn hide-xs" aria-label="<?= $auth ? 'My account' : 'Sign in' ?>" data-dd-toggle="account-dd">
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c1-4 4-6 8-6s7 2 8 6"></path></svg>
                        </button>
                        <div class="dd-panel" id="account-dd">
                            <?php if ($auth): ?>
                                <div class="dd-head">Signed in as <?= e(explode(' ', $auth['name'])[0]) ?></div>
                                <a class="dd-item" href="<?= e(url('/account')) ?>">
                                    <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7M5 10v10h14V10"></path></svg>
                                    <div><div class="dd-item-name">Dashboard</div></div>
                                </a>
                                <a class="dd-item" href="<?= e(url('/account/orders')) ?>">
                                    <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"></rect><path d="M9 8h6M9 12h6M9 16h4"></path></svg>
                                    <div><div class="dd-item-name">My Orders</div></div>
                                </a>
                                <a class="dd-item" href="<?= e(url('/account/warranty')) ?>">
                                    <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6z"></path></svg>
                                    <div><div class="dd-item-name">Warranty</div></div>
                                </a>
                                <a class="dd-item" href="<?= e(url('/account/profile')) ?>">
                                    <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c1-4 4-6 8-6s7 2 8 6"></path></svg>
                                    <div><div class="dd-item-name">Profile</div></div>
                                </a>
                                <div class="dd-foot">
                                    <form method="post" action="<?= e(url('/logout')) ?>" style="flex:1">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="dd-link ghost" style="width:100%;color:#dc2626">Sign out</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="dd-head">Welcome</div>
                                <div class="dd-foot" style="flex-direction:column;gap:8px">
                                    <a href="<?= e(url('/login')) ?>" class="dd-link primary">Sign in</a>
                                    <a href="<?= e(url('/register')) ?>" class="dd-link ghost">Create account</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dd-wrap" data-dd-wrap>
                        <a class="icon-btn" href="<?= e(url('/account/wishlist')) ?>" aria-label="Wishlist" data-dd-toggle="wishlist-dd">
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>
                            <?php if ($wishlistCount > 0): ?><span class="count"><?= (int) $wishlistCount ?></span><?php endif; ?>
                        </a>
                        <div class="dd-panel" id="wishlist-dd">
                            <div class="dd-head">Wishlist <?php if ($wishlistCount > 0): ?><span style="color:#7AFB01">(<?= $wishlistCount ?>)</span><?php endif; ?></div>
                            <div class="dd-empty">
                                <?php if ($wishlistCount > 0): ?>You have <?= $wishlistCount ?> saved item(s).<?php else: ?>Your wishlist is empty. Save laptops you're interested in.<?php endif; ?>
                            </div>
                            <div class="dd-foot">
                                <a href="<?= e(url('/account/wishlist')) ?>" class="dd-link ghost" style="flex:1">View all</a>
                            </div>
                        </div>
                    </div>
                    <div class="dd-wrap" data-dd-wrap>
                        <a class="icon-btn" href="<?= e(url('/cart')) ?>" aria-label="Shopping cart" data-dd-toggle="cart-dd">
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2.5l2 11h11l2-8H6.5"></path><circle cx="9" cy="20" r="1.3"></circle><circle cx="17" cy="20" r="1.3"></circle></svg>
                            <?php if ($cartCount > 0): ?><span class="count"><?= (int) $cartCount ?></span><?php endif; ?>
                        </a>
                        <div class="dd-panel" id="cart-dd">
                            <div class="dd-head">Shopping Cart <?php if ($cartCount > 0): ?><span style="color:#7AFB01">(<?= $cartCount ?>)</span><?php endif; ?></div>
                            <div class="dd-empty">
                                <?php if ($cartCount > 0): ?>You have <?= $cartCount ?> item(s) in your cart.<?php else: ?>Your cart is empty. <a href="<?= e(url('/shop')) ?>" style="color:#2563EB;font-weight:700">Browse laptops</a><?php endif; ?>
                            </div>
                            <?php if ($cartCount > 0): ?>
                            <div class="dd-foot">
                                <a href="<?= e(url('/cart')) ?>" class="dd-link ghost" style="flex:1">View cart</a>
                                <a href="<?= e(url('/checkout')) ?>" class="dd-link primary" style="flex:1">Checkout</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
                <div class="nav-mobile-head">
                    <h2>Menu</h2>
                    <button type="button" class="nav-mobile-close" data-nav-toggle aria-label="Close menu" aria-expanded="true">
                        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="nav-mobile-search">
                    <form action="<?= e(url('/shop')) ?>" method="get" role="search">
                        <div class="search-box">
                            <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                            <label class="sr-only" for="nav-mobile-q">Search laptops</label>
                            <input id="nav-mobile-q" type="search" name="q" placeholder="Search laptops by brand or model…" autocomplete="off">
                        </div>
                    </form>
                </div>
                <div class="nav-mobile-section">
                    <p class="nav-mobile-section-title">Shop</p>
                    <a href="<?= e(url('/shop')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="10" rx="1.5"/><path d="M3 19h18"/></svg>Shop Laptops</a>
                    <a href="<?= e(url('/shop?sort=brands')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12.6 2.5 21 11l-9 9-8.5-8.5V3.5h8.1Z"/><circle cx="8" cy="8" r="1.5"/></svg>Browse Brands</a>
                    <a href="<?= e(url('/shop?budget=1')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/><path d="M16 13h3"/></svg>Budget Deals</a>
                </div>
                <div class="nav-mobile-section">
                    <p class="nav-mobile-section-title">Support</p>
                    <a href="<?= e(url('/faq')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>FAQ &amp; Warranty</a>
                    <a href="<?= e(url('/about')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>About Us</a>
                    <a href="<?= e(url('/contact')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>Contact</a>
                    <a href="<?= e(url('/page/inspection-process')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/></svg>How we check laptops</a>
                </div>
                <div class="nav-mobile-section">
                    <p class="nav-mobile-section-title">Account</p>
                    <a href="<?= e(url($auth ? '/account' : '/login')) ?>"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?= $auth ? 'My account' : 'Sign in' ?></a>
                </div>
                <div class="nav-mobile-foot">
                    <a href="tel:+8801401400041"><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>+880 1401-400041</a>
                </div>
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
                <div class="foot-brand">
                    <?= \App\Support\View::partial('layouts/_logo', ['variant' => 'dark']) ?>
                    <p class="foot-tagline">Dubai-imported used laptops, inspected one unit at a time, for Bangladesh.</p>
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
                        <li><a href="tel:+8801401400041">+880 1401-400041</a></li>
                        <li><a href="mailto:rasidultechpoint@gmail.com">rasidultechpoint@gmail.com</a></li>
                        <li>Shop 110, Ground Floor, Jalil Tower, Khulna 9100</li>
                        <li>Service: Khulna, Dhaka, Bagerhat, Satkhira, Jessore</li>
                    </ul>
                </div>
            </div>
            <div class="foot-bottom">
                <span>© <?= date('Y') ?> Rasidul Tech Point. All rights reserved.</span>
                <div class="legal">
                    <a href="<?= e(url('/page/privacy')) ?>">Privacy Policy</a>
                    <a href="<?= e(url('/page/terms')) ?>">Terms</a>
                    <a href="<?= e(url('/page/returns')) ?>">Return Policy</a>
                </div>
            </div>
            <div class="foot-mark" aria-hidden="true">Rasidul Tech Point</div>
        </div>
    </footer>

    <?php
    // Mobile bottom tabbar — 5-tab app-style nav
    $_tabbarCart = (int) ($_SESSION['cart_count'] ?? 0);
    $_tabbarWish = (int) ($_SESSION['wishlist_count'] ?? 0);
    $_tabbarAuth = current_user();
    $_tabbarPath = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    $_isHome = $_tabbarPath === '/' || $_tabbarPath === '';
    $_isShop = str_starts_with($_tabbarPath, '/shop') || str_starts_with($_tabbarPath, '/brands') || str_starts_with($_tabbarPath, '/categories');
    $_isWish = str_starts_with($_tabbarPath, '/account/wishlist');
    $_isCart = str_starts_with($_tabbarPath, '/cart') || str_starts_with($_tabbarPath, '/checkout');
    $_isAccount = (str_starts_with($_tabbarPath, '/account') && !$_isWish)
        || str_starts_with($_tabbarPath, '/login')
        || str_starts_with($_tabbarPath, '/register')
        || str_starts_with($_tabbarPath, '/forgot-password');
    ?>
    <div class="tab-space"></div>
    <nav class="tabbar" aria-label="Quick navigation">
        <a class="tab <?= $_isHome ? 'is-active' : '' ?>" href="<?= e(url('/')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7M5 10v10h14V10"></path></svg>Home
        </a>
        <a class="tab <?= $_isShop ? 'is-active' : '' ?>" href="<?= e(url('/shop')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path></svg>Laptops
        </a>
        <a class="tab <?= $_isWish ? 'is-active' : '' ?>" href="<?= e(url('/account/wishlist')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>Wishlist
            <?php if ($_tabbarWish > 0): ?><span class="count wish"><?= $_tabbarWish > 9 ? '9+' : $_tabbarWish ?></span><?php endif; ?>
        </a>
        <a class="tab <?= $_isCart ? 'is-active' : '' ?>" href="<?= e(url('/cart')) ?>">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2.5l2 11h11l2-8H6.5"></path><circle cx="9" cy="20" r="1.3"></circle><circle cx="17" cy="20" r="1.3"></circle></svg>Cart
            <?php if ($_tabbarCart > 0): ?><span class="count"><?= $_tabbarCart > 9 ? '9+' : $_tabbarCart ?></span><?php endif; ?>
        </a>
        <a class="tab <?= $_isAccount ? 'is-active' : '' ?>" href="<?= e(url($_tabbarAuth ? '/account' : '/login')) ?>">
            <?php if ($_tabbarAuth): ?>
                <span class="tab-account-ic"><?= e(strtoupper(mb_substr($_tabbarAuth['name'] ?? '?', 0, 1))) ?></span>
            <?php else: ?>
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?php endif; ?>
            <?= $_tabbarAuth ? 'Account' : 'Sign in' ?>
        </a>
    </nav>

    <?php /* ====== Compare bar + modal (listing pages only — never on the homepage) ====== */ ?>
    <div class="cmp-bar" data-cmp-bar>
      <div class="cmp-items" data-cmp-items></div>
      <div data-cmp-action></div>
      <button class="cmp-close" type="button" data-cmp-close aria-label="Close compare bar">×</button>
    </div>
    <div class="cmp-modal" data-cmp-modal role="dialog" aria-modal="true" aria-label="Compare laptops">
      <div class="cmp-modal-card">
        <div class="cmp-modal-head">
          <h2>Compare laptops</h2>
          <button type="button" class="cmp-close" data-cmp-modal-close aria-label="Close comparison">×</button>
        </div>
        <p class="cmp-modal-note">Prices are live "from" prices for each model. Condition and exact unit details are shown on each product page.</p>
        <div class="cmp-scroll" data-cmp-table></div>
      </div>
    </div>

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

        // Scroll-aware announcement bar: hide on scroll-down, show on scroll-up.
        (function () {
            var announce = document.querySelector('.announce');
            if (!announce) return;
            var lastY = window.scrollY || 0;
            var ticking = false;
            function onScroll() {
                var y = window.scrollY || 0;
                if (y > lastY && y > 80) {
                    // Scrolling down + past 80px → hide announcement bar
                    announce.classList.add('hide');
                } else {
                    // Scrolling up OR near the top → show announcement bar
                    announce.classList.remove('hide');
                }
                lastY = y;
                ticking = false;
            }
            window.addEventListener('scroll', function () {
                if (!ticking) {
                    window.requestAnimationFrame(onScroll);
                    ticking = true;
                }
            }, { passive: true });
        })();

        // Dropdown panels: open/close for account, wishlist, cart icons.
        // Each .dd-wrap is the positioning context; .dd-panel opens below its icon.
        (function () {
            document.addEventListener('click', function (e) {
                var toggle = e.target.closest('[data-dd-toggle]');
                // Close all open panels except the one being toggled.
                document.querySelectorAll('.dd-panel.open').forEach(function (p) {
                    if (!toggle || p.id !== toggle.getAttribute('data-dd-toggle')) {
                        p.classList.remove('open');
                    }
                });
                if (toggle) {
                    e.preventDefault();
                    var panel = document.getElementById(toggle.getAttribute('data-dd-toggle'));
                    if (panel) {
                        panel.classList.toggle('open');
                        // Reposition: if the panel would overflow the right edge of the
                        // viewport, shift it left so it stays inside.
                        if (panel.classList.contains('open')) {
                            var rect = panel.getBoundingClientRect();
                            if (rect.right > window.innerWidth - 8) {
                                panel.style.right = 'auto';
                                panel.style.left = '0';
                            } else if (rect.left < 8) {
                                panel.style.right = '0';
                                panel.style.left = 'auto';
                            }
                        } else {
                            // Reset inline position when closed.
                            panel.style.right = '';
                            panel.style.left = '';
                        }
                    }
                }
            });

            // Close on Escape.
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dd-panel.open').forEach(function (p) {
                        p.classList.remove('open');
                        p.style.right = '';
                        p.style.left = '';
                    });
                }
            });
        })();
    </script>
    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
