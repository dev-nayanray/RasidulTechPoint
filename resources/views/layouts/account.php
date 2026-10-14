<?php
/** Customer panel layout: shared design header + sidebar nav + footer. */
$auth = current_user();
$cartCount = (int) ($_SESSION['cart_count'] ?? 0);
$wishlistCount = (int) ($_SESSION['wishlist_count'] ?? 0);
$unreadNotifications = 0;
$customerId = 0;
$customerName = $auth['name'] ?? 'Customer';
if ($auth) {
    $repo = new \App\Repositories\CustomerRepository();
    $customer = $repo->findByUserId((int) $auth['id']);
    if ($customer) {
        $customerId = (int) $customer['id'];
        $customerName = $customer['name'];
    }
    $unreadNotifications = \App\Services\NotificationService::unreadCount((int) $auth['id']);
}
$currentPath = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
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
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <style>
<?php include BASE_PATH . '/resources/views/storefront/_design_css.php'; ?>
    </style>
</head>
<body>

<div id="top">

<div class="announce">
  <div class="wrap">
    <div class="announce-in">
      <span>Dubai Imported | 100% Authentic Used Laptops | Tested &amp; Quality Checked</span>
      <a href="<?= e(url('/contact')) ?>">Call: 01401-400041</a>
    </div>
  </div>
</div>

<header class="site-hdr">
  <div class="wrap">
    <div class="hdr">
      <button class="icon-btn burger" aria-label="Open menu" aria-expanded="false" data-nav-toggle>
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
      </button>
      <?= \App\Support\View::partial('layouts/_logo', ['variant' => 'light']) ?>
      <form class="search" method="get" action="<?= e(url('/shop')) ?>" role="search" data-search-form>
        <div class="search-box">
          <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true" style="color:#64748B"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
          <label class="sr-only" for="site-search">Search laptops</label>
          <input id="site-search" type="search" name="q" placeholder="Search laptops by brand or model…" value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off" data-search-input>
          <button type="submit" class="search-go" aria-label="Search">
            <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
          </button>
        </div>
        <div class="sugg" data-sugg-dropdown></div>
      </form>
      <div class="icons">
        <div class="dd-wrap" data-dd-wrap>
          <button class="icon-btn hide-xs" aria-label="My account" data-dd-toggle="account-dd">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c1-4 4-6 8-6s7 2 8 6"></path></svg>
            <?php if ($unreadNotifications > 0): ?><span class="count" style="background:#7AFB01"><?= $unreadNotifications ?></span><?php endif; ?>
          </button>
          <div class="dd-panel" id="account-dd">
            <div class="dd-head">Signed in as <?= e(explode(' ', $customerName)[0]) ?></div>
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
            <a class="dd-item" href="<?= e(url('/account/notifications')) ?>">
              <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path></svg>
              <div><div class="dd-item-name">Notifications</div><?php if ($unreadNotifications > 0): ?><div class="dd-item-meta" style="color:#7AFB01"><?= $unreadNotifications ?> unread</div><?php endif; ?></div>
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
          </div>
        </div>
        <div class="dd-wrap" data-dd-wrap>
          <a class="icon-btn" href="<?= e(url('/account/wishlist')) ?>" aria-label="Wishlist" data-dd-toggle="wishlist-dd">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>
            <?php if ($wishlistCount > 0): ?><span class="count"><?= (int) $wishlistCount ?></span><?php endif; ?>
          </a>
          <div class="dd-panel" id="wishlist-dd">
            <div class="dd-head">Wishlist <?php if ($wishlistCount > 0): ?><span style="color:#7AFB01">(<?= $wishlistCount ?>)</span><?php endif; ?></div>
            <div class="dd-empty" data-wishlist-items>
              <?php if ($wishlistCount > 0): ?>You have <?= $wishlistCount ?> saved item(s).<?php else: ?>Your wishlist is empty.<?php endif; ?>
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
            <div class="dd-empty" data-cart-items>
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
        <a href="<?= e(url('/contact')) ?>">Support</a>
        <a href="<?= e(url('/about')) ?>">About Us</a>
      </nav>
    </div>
  </div>
  <div class="wrap">
    <nav class="nav-mobile" aria-label="Mobile" data-nav-mobile>
      <a href="<?= e(url('/shop')) ?>">Shop Laptops</a>
      <a href="<?= e(url('/shop?sort=brands')) ?>">Brands</a>
      <a href="<?= e(url('/shop?budget=1')) ?>">Budget Deals</a>
      <a href="<?= e(url('/contact')) ?>">Support</a>
      <a href="<?= e(url('/about')) ?>">About Us</a>
      <a href="<?= e(url('/account')) ?>">My Account</a>
      <form method="post" action="<?= e(url('/logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" style="width:100%;text-align:left;background:none;border:0;font:inherit;color:inherit;padding:10px 0;cursor:pointer;color:#dc2626">Sign out</button>
      </form>
    </nav>
  </div>
</header>

<div class="mx-auto max-w-7xl w-full flex-1 flex" style="padding-top:8px">
    <aside class="hidden lg:block w-60 shrink-0 border-r border-slate-200 bg-white" style="border-radius:0">
        <nav class="p-3 space-y-0.5 text-sm" aria-label="Customer navigation">
            <?php foreach ($navItems as [$path, $label, $icon, $badge]): ?>
                <?php $active = $currentPath === $path || ($path !== '/account' && str_starts_with($currentPath, $path)); ?>
                <a href="<?= e(url($path)) ?>"
                   class="flex items-center gap-3 rounded-lg px-3 py-2 font-medium <?= $active ? 'bg-brand-blue/10 text-brand-blue' : 'text-slate-600 hover:bg-slate-50' ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>>
                    <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true" style="width:18px;height:18px;flex:none;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round">
                        <?php
                        $paths = [
                            'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-6 4 4 5-8"/>',
                            'clipboard' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/>',
                            'shield' => '<path d="M12 2l9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6z"/>',
                            'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
                            'map-pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
                            'heart' => '<path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"/>',
                            'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
                            'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                            'megaphone' => '<path d="m3 11 18-5v12L3 14v-3Z"/>',
                        ];
                        echo $paths[$icon] ?? $paths['chart'];
                        ?>
                    </svg>
                    <span class="flex-1"><?= e($label) ?></span>
                    <?php if ($badge !== null): ?>
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-green text-white text-[10px] font-bold px-1"><?= (int) $badge ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="lg:hidden fixed inset-0 z-40 hidden bg-slate-900/50" data-customer-nav-backdrop></div>
    <aside class="lg:hidden fixed inset-y-0 left-0 z-50 w-72 -translate-x-full overflow-y-auto bg-white shadow-xl transition-transform" data-customer-nav-drawer>
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <span class="font-semibold text-navy-700">My Account</span>
            <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" data-customer-nav-close>
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true" style="width:20px;height:20px"><path d="M18 6 6 18M6 6l12 12"></path></svg>
            </button>
        </div>
        <nav class="p-3 space-y-0.5 text-sm" aria-label="Customer mobile navigation">
            <?php foreach ($navItems as [$path, $label, $icon, $badge]): ?>
                <?php $active = $currentPath === $path || ($path !== '/account' && str_starts_with($currentPath, $path)); ?>
                <a href="<?= e(url($path)) ?>"
                   class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium <?= $active ? 'bg-brand-blue/10 text-brand-blue' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span class="flex-1"><?= e($label) ?></span>
                    <?php if ($badge !== null): ?>
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-green text-white text-[10px] font-bold px-1"><?= (int) $badge ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="flex-1 min-w-0 bg-slate-100">
        <div class="p-4 sm:p-6 lg:p-8">
            <?= \App\Support\View::component('flash') ?>
            <?= $content ?? '' ?>
        </div>
    </main>
</div>

<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div class="foot-brand">
        <?= \App\Support\View::partial('layouts/_logo', ['variant' => 'dark']) ?>
        <p class="foot-tagline">Dubai Imported | 100% Authentic Used Laptops — Original • Tested • Reliable.</p>
      </div>
      <div>
        <h4 class="mono">Shop</h4>
        <ul><li><a href="<?= e(url('/shop')) ?>">All laptops</a></li><li><a href="<?= e(url('/shop?budget=1')) ?>">Budget deals</a></li><li><a href="<?= e(url('/shop?sort=brands')) ?>">Browse brands</a></li></ul>
      </div>
      <div>
        <h4 class="mono">Support</h4>
        <ul><li><a href="<?= e(url('/contact')) ?>">Contact</a></li><li><a href="<?= e(url('/page/warranty')) ?>">Warranty</a></li><li><a href="<?= e(url('/page/returns')) ?>">Returns</a></li><li><a href="<?= e(url('/about')) ?>">About us</a></li></ul>
      </div>
      <div>
        <h4 class="mono">Contact</h4>
        <ul>
          <li><a href="tel:+8801401400041">+880 1401-400041</a></li>
          <li><a href="mailto:rasidultechpoint@gmail.com">rasidultechpoint@gmail.com</a></li>
          <li>Shop 110, Jalil Tower, Khulna 9100</li>
        </ul>
      </div>
    </div>
    <div class="foot-bottom">
      <span>&copy; <?= date('Y') ?> Rasidul Tech Point — Dubai Imported | Authentic Used Laptops</span>
      <div class="legal">
        <a href="<?= e(url('/page/privacy')) ?>">Privacy</a>
        <a href="<?= e(url('/page/terms')) ?>">Terms</a>
        <a href="<?= e(url('/page/returns')) ?>">Returns</a>
      </div>
    </div>
  </div>
</footer>

<?php
// Mobile bottom tabbar — 5-tab app-style nav (also on account pages).
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
    <a class="tab <?= $_isAccount ? 'is-active' : '' ?>" href="<?= e(url('/account')) ?>">
        <?php if ($_tabbarAuth): ?>
            <span class="tab-account-ic"><?= e(strtoupper(mb_substr($_tabbarAuth['name'] ?? '?', 0, 1))) ?></span>
        <?php else: ?>
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php endif; ?>
        Account
    </a>
</nav>

</div>

<script>
  // Scroll-aware announcement bar: hide on scroll-down, show on scroll-up.
  (function () {
    var announce = document.querySelector('.announce');
    if (!announce) return;
    var lastY = window.scrollY || 0;
    var ticking = false;
    function onScroll() {
      var y = window.scrollY || 0;
      if (y > lastY && y > 80) {
        announce.classList.add('hide');
      } else {
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

  // Mobile nav drawer toggle (both header + sidebar).
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-nav-toggle]');
    if (t) {
      var nav = document.querySelector('[data-nav-mobile]');
      if (nav) { var o = nav.classList.toggle('open'); t.setAttribute('aria-expanded', o?'true':'false'); }
    }
    // Customer sidebar drawer
    var st = e.target.closest('[data-customer-nav-toggle]');
    if (st) {
      var drawer = document.querySelector('[data-customer-nav-drawer]');
      var backdrop = document.querySelector('[data-customer-nav-backdrop]');
      if (drawer) { drawer.classList.remove('-translate-x-full'); backdrop?.classList.remove('hidden'); document.body.style.overflow='hidden'; }
    }
    var sc = e.target.closest('[data-customer-nav-close]');
    if (sc || e.target.closest('[data-customer-nav-backdrop]')) {
      var drawer = document.querySelector('[data-customer-nav-drawer]');
      var backdrop = document.querySelector('[data-customer-nav-backdrop]');
      if (drawer) { drawer.classList.add('-translate-x-full'); backdrop?.classList.add('hidden'); document.body.style.overflow=''; }
    }
  });

  // Dropdown panels.
  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-dd-toggle]');
    document.querySelectorAll('.dd-panel.open').forEach(function (p) {
      if (!toggle || p.id !== toggle.getAttribute('data-dd-toggle')) p.classList.remove('open');
    });
    if (toggle) {
      e.preventDefault();
      var panel = document.getElementById(toggle.getAttribute('data-dd-toggle'));
      if (panel) panel.classList.toggle('open');
    }
  });

  // AJAX live search.
  (function () {
    var input = document.querySelector('[data-search-input]');
    var dropdown = document.querySelector('[data-sugg-dropdown]');
    if (!input || !dropdown) return;
    var timer = null, lastQ = '';
    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
    input.addEventListener('input', function () {
      var q = input.value.trim();
      if (q.length < 2) { dropdown.classList.remove('open'); lastQ=''; return; }
      if (q === lastQ) return; lastQ = q;
      clearTimeout(timer);
      timer = setTimeout(function () {
        fetch('<?= e(url('/search/suggest')) ?>?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.results || data.results.length === 0) {
              dropdown.innerHTML = '<div class="s-empty">No laptops found. Try Dell, HP, Lenovo.</div>';
            } else {
              var h = data.results.map(function (r) {
                return '<a class="s-item" href="' + r.url + '"><span class="s-thumb"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9l10-5 10 5-10 5z"/><path d="M2 9v6l10 5 10-5V9"/></svg></span><div class="s-info"><div class="s-name">' + esc(r.brand + ' ' + r.name) + '</div><div class="s-meta">' + esc(r.processor) + (r.ram?' · '+esc(r.ram):'') + (r.units>0?' · In stock':'') + '</div></div><span class="s-price">' + esc(r.price) + '</span></a>';
              }).join('');
              if (data.more) h += '<a class="s-more" href="' + data.search_url + '">See all results →</a>';
              dropdown.innerHTML = h;
            }
            dropdown.classList.add('open');
          }).catch(function () { dropdown.classList.remove('open'); });
      }, 250);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.search')) dropdown.classList.remove('open'); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { dropdown.classList.remove('open'); input.blur(); } });
  })();
</script>
</body>
</html>
