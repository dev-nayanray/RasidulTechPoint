<?php
/**
 * Dynamic premium homepage — matches the uploaded design exactly.
 * Pulls real products from CatalogRepository + ContentService.
 * When no products are in the DB, shows a friendly empty state
 * instead of hiding the section.
 *
 * @var list<array> $featured  Real in-stock products
 * @var list<array> $brands    Active brands with stock
 * @var list<array{label:string,min:int,max:?int}> $budgetRanges
 * @var list<array> $faqs     Published CMS FAQs
 * @var string $heroBlock
 * @var string $whyChooseBlock
 * @var string $howWeCheckBlock
 * @var string $finalCtaBlock
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($metaTitle ?? 'Rasidul Tech Point — Dubai Imported Authentic Used Laptops') ?></title>
<meta name="description" content="<?= e($metaDescription ?? 'Authentic used laptops imported from Dubai. Tested and quality checked with warranty options. Shop online or visit our store.') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
<?php include BASE_PATH . '/resources/views/storefront/_design_css.php'; ?>
</style>
</head>
<body>
<?php
$auth = current_user();
$cartCount = (int) ($_SESSION['cart_count'] ?? 0);
$wishlistCount = (int) ($_SESSION['wishlist_count'] ?? 0);
?>
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
      <a class="logo" href="<?= e(url('/')) ?>" aria-label="Rasidul Tech Point home">
        <span class="logomark" style="background:#01011D;padding:2px;overflow:hidden">
          <img src="<?= e(url('uploads/logos/rtp-logo.jpg')) ?>" alt="Rasidul Tech Point" style="width:100%;height:100%;object-fit:cover;border-radius:10px">
        </span>
        <span><span class="wm-a">Rasidul</span><span class="wm-b" style="color:#7AFB01">TECH POINT</span></span>
      </a>
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
            <div class="dd-empty" data-wishlist-items>
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
        <a href="<?= e(url('/contact')) ?>">Warranty &amp; Support</a>
        <a href="<?= e(url('/about')) ?>">About Us</a>
        <a class="push" href="<?= e(url('/about')) ?>">How we check laptops</a>
      </nav>
    </div>
  </div>
  <div class="wrap">
    <nav class="nav-mobile" aria-label="Mobile" data-nav-mobile>
      <a href="<?= e(url('/shop')) ?>">Shop Laptops</a>
      <a href="<?= e(url('/shop?sort=brands')) ?>">Brands</a>
      <a href="<?= e(url('/shop?budget=1')) ?>">Budget Deals</a>
      <a href="<?= e(url('/contact')) ?>">Warranty &amp; Support</a>
      <a href="<?= e(url('/about')) ?>">About Us</a>
      <a href="<?= e(url($auth ? '/account' : '/login')) ?>"><?= $auth ? 'My account' : 'Sign in' ?></a>
    </nav>
  </div>
</header>

<?php if (!empty($heroSlides)): ?>
<div class="hero-slider" data-hero-slider data-duration="<?= (int)($heroSlides[0]['slide_duration'] ?? 5000) ?>" data-autorotate="<?= (int)($heroSlides[0]['auto_rotate'] ?? 1) ?>">
  <div class="hs-track">
    <?php foreach ($heroSlides as $i => $slide): ?>
    <div class="hs-slide hs-anim-<?= e($slide['animation_intensity'] ?? 'normal') ?>" style="background:<?= e($slide['bg_color']) ?>;color:<?= e($slide['text_color']) ?>" role="group" aria-label="<?= e($slide['accessibility_desc'] ?? $slide['headline']) ?>">
      <?php if (!empty($slide['desktop_image'])): ?>
        <img class="hs-bg-img" src="<?= e(url($slide['desktop_image'])) ?>" alt="<?= e($slide['image_alt'] ?? '') ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" width="1920" height="520">
        <div class="hs-overlay" style="background:linear-gradient(90deg,<?= e($slide['bg_color']) ?>cc,<?= e($slide['bg_color']) ?>33)"></div>
      <?php endif; ?>
      <div class="wrap" style="position:relative;z-index:2;width:100%;display:flex;align-items:center;min-height:inherit">
        <div class="hs-content <?= e($slide['content_position'] ?? 'left') ?>">
          <?php if (!empty($slide['eyebrow'])): ?><span class="hs-eyebrow"><?= e($slide['eyebrow']) ?></span><?php endif; ?>
          <h1 class="hs-headline"><?= e($slide['headline']) ?><?php if (!empty($slide['highlight_phrase'])): ?> <span class="hs-highlight"><?= e($slide['highlight_phrase']) ?></span><?php endif; ?></h1>
          <?php if (!empty($slide['description'])): ?><p class="hs-desc"><?= e($slide['description']) ?></p><?php endif; ?>
          <div class="hs-ctas">
            <a class="btn btn-primary" href="<?= e(url($slide['cta1_url'])) ?>"><?= e($slide['cta1_label']) ?></a>
            <?php if (!empty($slide['cta2_label'])): ?><a class="btn btn-onnavy" href="<?= e(url($slide['cta2_url'] ?? '/shop')) ?>"><?= e($slide['cta2_label']) ?></a><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (!empty($slide['product_image'])): ?><img class="hs-product-img <?= e($slide['image_position'] ?? 'right') ?>" src="<?= e(url($slide['product_image'])) ?>" alt="<?= e($slide['image_alt'] ?? '') ?>" loading="lazy"><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ((int)($heroSlides[0]['show_nav'] ?? 1) === 1 && count($heroSlides) > 1): ?>
    <button class="hs-nav prev" aria-label="Previous slide" data-hs-prev><svg class="ic" viewBox="0 0 24 24" style="width:20px;height:20px"><path d="M15 6l-6 6 6 6"></path></svg></button>
    <button class="hs-nav next" aria-label="Next slide" data-hs-next><svg class="ic" viewBox="0 0 24 24" style="width:20px;height:20px"><path d="M9 6l6 6-6 6"></path></svg></button>
  <?php endif; ?>
  <?php if ((int)($heroSlides[0]['show_pagination'] ?? 1) === 1 && count($heroSlides) > 1): ?>
    <div class="hs-dots" data-hs-dots>
      <?php foreach ($heroSlides as $i => $_): ?><button class="hs-dot <?= $i === 0 ? 'active' : '' ?>" data-hs-dot="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ((int)($heroSlides[0]['auto_rotate'] ?? 1) === 1 && count($heroSlides) > 1): ?>
    <div class="hs-progress"><div class="hs-progress-bar" data-hs-progress></div></div>
  <?php endif; ?>
</div>
<?php else: ?>
<section class="hero">
  <div class="wrap">
    <div class="hero-in">
      <div style="display:flex;flex-direction:column;gap:24px;align-items:flex-start">
        <span class="stamp mono">Dubai Imported | 100% Authentic</span>
        <h1>Premium Laptops. <span class="hl" style="color:#7AFB01">Smarter Prices.</span></h1>
        <p class="lead">Original • Tested • Reliable — quality-checked Dubai-imported laptops for study, work, business, and everyday use.</p>
        <div class="hero-ctas">
          <a class="btn btn-primary" href="<?= e(url('/shop')) ?>">Shop Laptops</a>
          <a class="btn btn-onnavy" href="<?= e(url('/shop?budget=1')) ?>">Explore Budget Deals</a>
        </div>
        <ul class="trust">
          <li><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>Quality Checked</li>
          <li><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>Transparent Specifications</li>
          <li><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>Warranty Options</li>
        </ul>
      </div>
      <div class="art">
        <span class="qc"><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>Quality Checked</span>
        <div class="art-lap">
          <svg class="laptop" viewBox="0 0 320 200" role="img" aria-label="Laptop illustration">
            <ellipse class="lp-shadow" cx="160" cy="186" rx="124" ry="8"></ellipse>
            <rect class="lp-lid" x="56" y="8" width="208" height="134" rx="10"></rect>
            <rect class="lp-rim" x="56.5" y="8.5" width="207" height="133" rx="10"></rect>
            <rect class="lp-screen" x="65" y="17" width="190" height="116" rx="4"></rect>
            <rect class="lp-glow" x="75" y="27" width="46" height="7" rx="3.5"></rect>
            <rect class="lp-card" x="75" y="42" width="52" height="34" rx="4"></rect>
            <rect class="lp-card" x="133" y="42" width="52" height="34" rx="4"></rect>
            <rect class="lp-card" x="191" y="42" width="54" height="34" rx="4"></rect>
            <rect class="lp-line" x="75" y="86" width="170" height="5" rx="2.5"></rect>
            <rect class="lp-line" x="75" y="97" width="132" height="5" rx="2.5"></rect>
            <rect class="lp-line" x="75" y="108" width="150" height="5" rx="2.5"></rect>
            <circle class="lp-ok" cx="236" cy="30" r="4"></circle>
            <path class="lp-base" d="M22 146h276l-12 24a10 10 0 0 1-9 5H43a10 10 0 0 1-9-5z"></path>
            <rect class="lp-notch" x="124" y="146" width="72" height="6" rx="3"></rect>
            <path class="lp-keys" d="M52 158h216M48 165h224M45 172h230"></path>
          </svg>
        </div>
        <p class="art-note">Illustration. Photos of each exact unit replace this on the live store.</p>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="sec" id="categories">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Browse</span>
        <h2>Shop by Category</h2>
        <p class="sub">Start with how you plan to use the laptop.</p>
      </div>
    </div>
    <div class="bento">
      <?php foreach ([
        ['Business Laptops', 'Reliable for office work', '<rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path>', 'shop?q=business'],
        ['Student Laptops', 'Affordable & portable', '<path d="M2 9l10-5 10 5-10 5z"></path><path d="M6 11.5V16c3 2.5 9 2.5 12 0v-4.5"></path>', 'shop?q=student'],
        ['Core i5 Laptops', 'Balanced performance', '<rect x="6" y="6" width="12" height="12" rx="2"></rect><rect x="9" y="9" width="6" height="6"></rect><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"></path>', 'shop?q=core+i5'],
        ['Core i7 Laptops', 'High performance', '<rect x="6" y="6" width="12" height="12" rx="2"></rect><rect x="9" y="9" width="6" height="6"></rect><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"></path>', 'shop?q=core+i7'],
        ['Lightweight & Portable', 'Easy to carry', '<path d="M2 9l10-5 10 5-10 5z"></path><path d="M2 9v6l10 5 10-5V9"></path>', 'shop?q=portable'],
        ['Budget Laptops', 'Under ৳15,000', '<path d="M3 7h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path><path d="M16 13h3"></path>', 'shop?budget=1'],
        ['Premium Laptops', '৳40,000+', '<path d="M12 2l9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6l9-4z"></path>', 'shop?budget=4'],
      ] as [$name, $desc, $icon, $link]): ?>
        <a class="tile" href="<?= e(url('/' . ltrim($link, '/'))) ?>">
          <span class="tile-mark" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><?= $icon ?></svg></span>
          <span class="tile-name"><?= e($name) ?></span>
          <span class="tile-desc"><?= e($desc) ?></span>
          <span class="tile-go mono">View laptops <svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec sec-white" id="budget">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Price</span>
        <h2>Shop by Budget</h2>
        <p class="sub">Pick a price range and see laptops in that range.</p>
      </div>
    </div>
    <div class="budget-grid">
      <?php foreach ($budgetRanges as $i => $range): ?>
        <a class="budget-card" href="<?= e(url('/shop?budget=' . ($i + 1))) ?>">
          <span class="budget-label"><?= e($range['label']) ?></span>
          <span class="budget-range">In-stock only</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec" id="featured">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Ready to buy</span>
        <h2>Featured Laptops</h2>
        <p class="sub">Every card shows the specs and condition of one exact unit.</p>
      </div>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/shop')) ?>">View all laptops</a>
    </div>
    <?php if (empty($featured)): ?>
      <div style="padding:48px 24px;text-align:center;border:2px dashed #D5DEE9;border-radius:20px;background:#fff">
        <p style="font-size:18px;font-weight:700;color:#14243A">No laptops in stock right now.</p>
        <p style="margin-top:8px;color:#475569;font-size:14px">New Dubai shipments arrive regularly. Check back soon or <a href="<?= e(url('/contact')) ?>" style="color:#2563EB;font-weight:700">contact us</a>.</p>
      </div>
    <?php else: ?>
      <div class="pgrid">
        <?php foreach (array_slice($featured, 0, 8) as $p): ?>
          <article class="pcard">
            <a class="pcard-img" href="<?= e(url('/shop/' . $p['slug'])) ?>">
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);color:#94a3b8"><svg class="ic" viewBox="0 0 24 24" style="width:48px;height:48px"><path d="M2 9l10-5 10 5-10 5z"></path><path d="M2 9v6l10 5 10-5V9"></path></svg></div>
              <span class="qc-badge"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>Quality Checked</span>
              <?php if ((int)($p['available_units'] ?? 0) <= 2 && (int)($p['available_units'] ?? 0) > 0): ?>
                <span class="low-badge">Only <?= (int)$p['available_units'] ?> left</span>
              <?php elseif ((int)($p['available_units'] ?? 0) === 0): ?>
                <span class="oos-badge">Out of stock</span>
              <?php endif; ?>
            </a>
            <div class="pcard-body">
              <p class="pcard-brand"><?= e($p['brand_name'] ?? '') ?></p>
              <a class="pcard-name" href="<?= e(url('/shop/' . $p['slug'])) ?>"><?= e($p['name'] ?? '') ?></a>
              <dl class="pcard-specs">
                <div><dt>CPU</dt><dd><?= e($p['processor'] ?? '—') ?></dd></div>
                <div><dt>RAM</dt><dd><?= isset($p['ram_gb']) ? (int)$p['ram_gb'] . ' GB' : '—' ?></dd></div>
                <div><dt>Storage</dt><dd><?= isset($p['storage_capacity_gb']) ? (int)$p['storage_capacity_gb'] . ' GB ' . ($p['storage_type'] ?? '') : '—' ?></dd></div>
                <?php if (!empty($p['display_size_inches'])): ?>
                  <div><dt>Display</dt><dd><?= e(number_format((float)$p['display_size_inches'], 1)) ?>"</dd></div>
                <?php endif; ?>
              </dl>
              <div class="pcard-foot">
                <div><span class="price"><?= $p['from_price'] !== null ? money($p['from_price']) : '—' ?></span></div>
                <span class="grade grade-a">In stock: <?= (int)($p['available_units'] ?? 0) ?></span>
              </div>
              <div class="pcard-actions">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/shop/' . $p['slug'])) ?>">View details</a>
                <a class="btn btn-sm btn-primary" href="<?= e(url('/shop/' . $p['slug'])) ?>#units">Add to cart</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="sec sec-white" id="why">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">About Us</span>
        <h2>Why Choose Rasidul Tech Point?</h2>
        <p class="sub">What you can expect when you buy a used laptop from us.</p>
      </div>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/about')) ?>">See how we check laptops</a>
    </div>
    <div class="why-grid">
      <?php foreach ([
        ['<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12"></path><path d="M15 18h-5m9-4h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1m-5 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M7 18a2 2 0 1 0 4 0a2 2 0 1 0-4 0"></path>', 'Dubai-imported selection', 'Authentic used stock imported directly — no refurbished-as-new ambiguity.'],
        ['<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"></path><path d="M9 11l3 3L22 4"></path>', 'Individual unit inspection', 'Every laptop is examined by our team before listing — not bulk-graded.'],
        ['<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v5h5M10 9H8M10 13H8M10 17H8"></path>', 'Clear condition & specs', 'Real processor, RAM, storage, display size, and any known defects.'],
        ['<path d="M3 7h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path><path d="M16 13h3"></path>', 'Transparent pricing', 'No hidden fees, no inflated "discounts". The price you see is the price you pay.'],
        ['<path d="M12 2l9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6l9-4z"></path>', 'Warranty information', 'Where warranty applies, it is shown on the product page.'],
        ['<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path><circle cx="12" cy="10" r="3"></circle>', 'Online & physical store', 'Buy online with delivery, or visit us to inspect the unit in person.'],
      ] as [$svg, $title, $text]): ?>
        <div class="why">
          <span class="why-ic"><svg class="ic" viewBox="0 0 24 24"><?= $svg ?></svg></span>
          <p class="why-title"><?= e($title) ?></p>
          <p class="why-text"><?= e($text) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec dark" id="inspection">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Inspection</span>
        <h2>How We Check Our Laptops</h2>
        <p class="sub">Every laptop gets its own inspection record. You see the result on the product page, including any known marks or defects.</p>
      </div>
    </div>
    <div class="steps-grid">
      <?php foreach ([
        ['1', 'Verify the laptop', 'Check the make, model, serial, and identifying details against the import manifest.'],
        ['2', 'Test hardware components', 'Boot, keyboard, trackpad, screen, ports, battery, Wi-Fi, and storage health.'],
        ['3', 'Record condition & defects', 'Photograph any cosmetic marks and document known issues on the product page.'],
        ['4', 'Approve for sale', 'Only units that pass all four steps are listed. The rest are set aside — not sold.'],
      ] as [$num, $title, $text]): ?>
        <div class="step-card">
          <span class="step-num"><?= e($num) ?></span>
          <p class="step-title"><?= e($title) ?></p>
          <p class="step-text"><?= e($text) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if (!empty($brands)): ?>
<section class="sec" id="brands">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Brands</span>
        <h2>Popular Brands</h2>
        <p class="sub">Only brands with available stock are shown.</p>
      </div>
    </div>
    <div class="brand-logos">
      <?php foreach ($brands as $b): ?>
        <a class="brand" href="<?= e(url('/brands/' . $b['slug'])) ?>"><span><?= e($b['name']) ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="sec sec-white" id="deals">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Offers</span>
        <h2>Special Deals</h2>
        <p class="sub">Promotions use live prices only.</p>
      </div>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/shop?budget=1')) ?>">Browse all deals</a>
    </div>
    <?php $deals = array_slice($featured, 0, 4); ?>
    <?php if (empty($deals)): ?>
      <p style="padding:32px;text-align:center;color:#475569">Special deals appear here when active promotions are running.</p>
    <?php else: ?>
      <div class="pgrid">
        <?php foreach ($deals as $p): ?>
          <article class="pcard">
            <a class="pcard-img" href="<?= e(url('/shop/' . $p['slug'])) ?>">
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);color:#94a3b8"><svg class="ic" viewBox="0 0 24 24" style="width:48px;height:48px"><path d="M2 9l10-5 10 5-10 5z"></path><path d="M2 9v6l10 5 10-5V9"></path></svg></div>
              <span class="qc-badge"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>Quality Checked</span>
            </a>
            <div class="pcard-body">
              <p class="pcard-brand"><?= e($p['brand_name'] ?? '') ?></p>
              <a class="pcard-name" href="<?= e(url('/shop/' . $p['slug'])) ?>"><?= e($p['name'] ?? '') ?></a>
              <dl class="pcard-specs">
                <div><dt>CPU</dt><dd><?= e($p['processor'] ?? '—') ?></dd></div>
                <div><dt>RAM</dt><dd><?= isset($p['ram_gb']) ? (int)$p['ram_gb'] . ' GB' : '—' ?></dd></div>
              </dl>
              <div class="pcard-foot">
                <div><span class="price"><?= $p['from_price'] !== null ? money($p['from_price']) : '—' ?></span></div>
                <span class="grade grade-a"><?= (int)($p['available_units'] ?? 0) ?> units</span>
              </div>
              <div class="pcard-actions">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/shop/' . $p['slug'])) ?>">View</a>
                <a class="btn btn-sm btn-primary" href="<?= e(url('/shop/' . $p['slug'])) ?>#units">Buy</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="sec" id="reviews">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Reviews</span>
        <h2>Customer Reviews</h2>
      </div>
    </div>
    <div style="padding:48px 24px;text-align:center;border:2px dashed #D5DEE9;border-radius:20px;background:#fff">
      <p style="font-size:18px;font-weight:700;color:#14243A">No reviews have been added yet.</p>
      <p style="margin-top:8px;color:#475569;font-size:14px">Only reviews from real, confirmed orders are published here.</p>
    </div>
  </div>
</section>

<section class="sec sec-white" id="faq">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <span class="eyebrow mono">Support</span>
        <h2>Frequently Asked Questions</h2>
      </div>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('/contact')) ?>">Still have a question?</a>
    </div>
    <?php if (!empty($faqs)): ?>
      <div class="faq">
        <?php foreach ($faqs as $faq): ?>
          <details class="faq-item">
            <summary class="faq-q"><span><?= e($faq['question']) ?></span><svg class="ic ic-sm faq-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg></summary>
            <div class="faq-a"><?= e($faq['answer']) ?></div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="padding:32px;text-align:center;border:2px dashed #D5DEE9;border-radius:20px;background:#fff">
        <p style="color:#475569;font-size:14px">FAQ entries will appear here once published. Admin → Marketing &amp; Content → FAQs.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="sec" id="cta">
  <div class="wrap">
    <div class="cta-panel">
      <h2>Find the Right Laptop for Your Budget.</h2>
      <p style="color:#B7C4D6;font-size:16px;max-width:480px">Browse our full inventory or contact us with your requirements.</p>
      <div class="hero-ctas">
        <a class="btn btn-primary" href="<?= e(url('/shop')) ?>">Explore All Laptops</a>
        <a class="btn btn-onnavy" href="<?= e(url('/contact')) ?>">Contact Us</a>
      </div>
    </div>
  </div>
</section>

<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div style="display:flex;flex-direction:column;gap:14px;align-items:flex-start">
        <a class="logo" href="<?= e(url('/')) ?>" aria-label="Rasidul Tech Point home">
          <span class="logomark" style="background:#fff;padding:2px;overflow:hidden">
            <img src="<?= e(url('uploads/logos/rtp-logo.jpg')) ?>" alt="RTP" style="width:100%;height:100%;object-fit:cover;border-radius:10px">
          </span>
          <span><span class="wm-a" style="color:#fff">Rasidul</span><span class="wm-b" style="color:#7AFB01">TECH POINT</span></span>
        </a>
        <p style="max-width:300px">Dubai Imported | 100% Authentic Used Laptops — Original • Tested • Reliable. Serving Khulna, Dhaka &amp; all of Bangladesh.</p>
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
          <li><a href="<?= e(url('/about')) ?>">How we check laptops</a></li>
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
      <span>&copy; <?= date('Y') ?> Rasidul Tech Point — Dubai Imported | Authentic Used Laptops</span>
      <div class="legal">
        <a href="<?= e(url('/page/privacy')) ?>" style="color:#B7C4D6">Privacy Policy</a>
        <a href="<?= e(url('/page/terms')) ?>" style="color:#B7C4D6">Terms</a>
        <a href="<?= e(url('/page/returns')) ?>" style="color:#B7C4D6">Return Policy</a>
      </div>
    </div>
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

</div>
<script>
  // Mobile nav drawer toggle.
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-nav-toggle]');
    if (t) { var n = document.querySelector('[data-nav-mobile]'); if (n) { var o = n.classList.toggle('open'); t.setAttribute('aria-expanded', o?'true':'false'); } }
  });

  // Dropdown panels.
  document.addEventListener('click', function (e) {
    var tg = e.target.closest('[data-dd-toggle]');
    document.querySelectorAll('.dd-panel.open').forEach(function (p) { if (!tg || p.id !== tg.getAttribute('data-dd-toggle')) p.classList.remove('open'); });
    if (tg) { e.preventDefault(); var p = document.getElementById(tg.getAttribute('data-dd-toggle')); if (p) p.classList.toggle('open'); }
  });

  // AJAX live search.
  (function () {
    var input = document.querySelector('[data-search-input]');
    var dd = document.querySelector('[data-sugg-dropdown]');
    if (!input || !dd) return;
    var timer = null, lastQ = '';
    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
    input.addEventListener('input', function () {
      var q = input.value.trim();
      if (q.length < 2) { dd.classList.remove('open'); lastQ=''; return; }
      if (q === lastQ) return; lastQ = q;
      clearTimeout(timer);
      timer = setTimeout(function () {
        fetch('<?= e(url('/search/suggest')) ?>?q=' + encodeURIComponent(q)).then(function(r){return r.json()}).then(function(data){
          if (!data.results || data.results.length === 0) { dd.innerHTML = '<div class="s-empty">No laptops found. Try Dell, HP, Lenovo.</div>'; }
          else { dd.innerHTML = data.results.map(function(r){return '<a class="s-item" href="'+r.url+'"><span class="s-thumb"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9l10-5 10 5-10 5z"/><path d="M2 9v6l10 5 10-5V9"/></svg></span><div class="s-info"><div class="s-name">'+esc(r.brand+' '+r.name)+'</div><div class="s-meta">'+esc(r.processor)+(r.ram?' · '+esc(r.ram):'')+(r.units>0?' · In stock':'')+'</div></div><span class="s-price">'+esc(r.price)+'</span></a>'}).join('') + (data.more?'<a class="s-more" href="'+data.search_url+'">See all results →</a>':''); }
          dd.classList.add('open');
        }).catch(function(){ dd.classList.remove('open'); });
      }, 250);
    });
    document.addEventListener('click', function(e){ if(!e.target.closest('.search')) dd.classList.remove('open'); });
    input.addEventListener('keydown', function(e){ if(e.key==='Escape'){dd.classList.remove('open');input.blur();} });
  })();

  // Premium Hero Slider.
  (function () {
    var slider = document.querySelector('[data-hero-slider]');
    if (!slider) return;
    var track = slider.querySelector('.hs-track');
    var slides = slider.querySelectorAll('.hs-slide');
    var dots = slider.querySelectorAll('[data-hs-dot]');
    var prevBtn = slider.querySelector('[data-hs-prev]');
    var nextBtn = slider.querySelector('[data-hs-next]');
    var progressBar = slider.querySelector('[data-hs-progress]');
    var duration = parseInt(slider.getAttribute('data-duration') || '5000');
    var autoRotate = parseInt(slider.getAttribute('data-autorotate') || '1');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var current = 0, count = slides.length, timer = null, progressTimer = null, progressStart = 0;

    function go(n) {
      current = ((n % count) + count) % count;
      track.style.transform = 'translateX(-' + (current * 100) + '%)';
      dots.forEach(function(d,i){ d.classList.toggle('active', i===current); });
      restartTimer();
    }
    function next(){ go(current+1); }
    function prev(){ go(current-1); }
    function startTimer(){
      if (autoRotate !== 1 || count <= 1 || reducedMotion) return;
      timer = setInterval(next, duration);
      progressStart = Date.now();
      progressTimer = setInterval(function(){
        if (!progressBar) return;
        var elapsed = Date.now() - progressStart;
        var pct = Math.min(100, (elapsed / duration) * 100);
        progressBar.style.width = pct + '%';
      }, 50);
    }
    function stopTimer(){ clearInterval(timer); clearInterval(progressTimer); if(progressBar) progressBar.style.width='0'; }
    function restartTimer(){ stopTimer(); startTimer(); }

    if (prevBtn) prevBtn.addEventListener('click', prev);
    if (nextBtn) nextBtn.addEventListener('click', next);
    dots.forEach(function(d,i){ d.addEventListener('click', function(){ go(i); }); });

    // Pause on hover.
    slider.addEventListener('mouseenter', stopTimer);
    slider.addEventListener('mouseleave', startTimer);

    // Pause when not visible.
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function(entries){
        entries.forEach(function(e){ if(e.isIntersecting) startTimer(); else stopTimer(); });
      }).observe(slider);
    }

    // Keyboard nav.
    slider.setAttribute('tabindex','0');
    slider.addEventListener('keydown', function(e){
      if (e.key === 'ArrowLeft') prev();
      if (e.key === 'ArrowRight') next();
    });

    // Touch swipe.
    var touchStart = 0, touchEnd = 0;
    slider.addEventListener('touchstart', function(e){ touchStart = e.touches[0].clientX; stopTimer(); }, {passive:true});
    slider.addEventListener('touchend', function(e){
      touchEnd = e.changedTouches[0].clientX;
      var diff = touchStart - touchEnd;
      if (Math.abs(diff) > 40) { if (diff > 0) next(); else prev(); }
      startTimer();
    });

    startTimer();
  })();
</script>

<!-- Compare bar (fixed bottom) -->
<div class="cmp-bar" data-cmp-bar>
  <div class="cmp-items" data-cmp-items></div>
  <div data-cmp-action></div>
  <button class="cmp-close" onclick="document.querySelector('[data-cmp-bar]').classList.remove('open');compareItems=[];">×</button>
</div>

</body>
</html>
