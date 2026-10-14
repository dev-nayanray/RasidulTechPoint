<?php
/**
 * Shared mobile bottom tabbar — app-style 5-tab navigation.
 * Included by home.php, layouts/public.php, layouts/account.php.
 *
 * Tabs: Home / Laptops / Wishlist / Cart / My Account
 *
 * @var int $cartCount       (optional, defaults to session value)
 * @var int $wishlistCount   (optional, defaults to session value)
 * @var ?array $auth         (optional, current user; null when logged out)
 * @var string $currentPath  (optional, current URL path for active-state detection)
 */
$cartCount = $cartCount ?? (int) ($_SESSION['cart_count'] ?? 0);
$wishlistCount = $wishlistCount ?? (int) ($_SESSION['wishlist_count'] ?? 0);
$auth = $auth ?? current_user();
$currentPath = $currentPath ?? ('/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/'));

// Active-state detection per tab.
$isHome = $currentPath === '/' || $currentPath === '';
$isShop = str_starts_with($currentPath, '/shop') || str_starts_with($currentPath, '/brands') || str_starts_with($currentPath, '/categories');
$isWishlist = str_starts_with($currentPath, '/account/wishlist');
$isCart = str_starts_with($currentPath, '/cart') || str_starts_with($currentPath, '/checkout');
$isAccount = str_starts_with($currentPath, '/account') && !$isWishlist
    || str_starts_with($currentPath, '/login')
    || str_starts_with($currentPath, '/register')
    || str_starts_with($currentPath, '/forgot-password')
    || str_starts_with($currentPath, '/reset-password');
?>
<div class="tab-space"></div>
<nav class="tabbar" aria-label="Quick navigation">
    <a class="tab <?= $isHome ? 'is-active' : '' ?>" href="<?= e(url('/')) ?>">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7M5 10v10h14V10"></path></svg>
        Home
    </a>
    <a class="tab <?= $isShop ? 'is-active' : '' ?>" href="<?= e(url('/shop')) ?>">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="10" rx="1.5"></rect><path d="M3 19h18"></path></svg>
        Laptops
    </a>
    <a class="tab <?= $isWishlist ? 'is-active' : '' ?>" href="<?= e(url('/account/wishlist')) ?>">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3C19 15.6 12 20 12 20z"></path></svg>
        Wishlist
        <?php if ($wishlistCount > 0): ?><span class="count wish"><?= $wishlistCount > 9 ? '9+' : (int) $wishlistCount ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $isCart ? 'is-active' : '' ?>" href="<?= e(url('/cart')) ?>">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2.5l2 11h11l2-8H6.5"></path><circle cx="9" cy="20" r="1.3"></circle><circle cx="17" cy="20" r="1.3"></circle></svg>
        Cart
        <?php if ($cartCount > 0): ?><span class="count"><?= $cartCount > 9 ? '9+' : (int) $cartCount ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $isAccount ? 'is-active' : '' ?>" href="<?= e(url($auth ? '/account' : '/login')) ?>">
        <?php if ($auth): ?>
            <span class="tab-account-ic"><?= e(strtoupper(mb_substr($auth['name'] ?? '?', 0, 1))) ?></span>
        <?php else: ?>
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php endif; ?>
        <?= $auth ? 'Account' : 'Sign in' ?>
    </a>
</nav>
