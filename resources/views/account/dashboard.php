<?php /** @var array $customer
 *  @var list<array> $recentOrders
 *  @var array $orderStats
 *  @var int $warrantyCount
 *  @var int $serviceCount
 *  @var int $unreadNotifications
 *  @var int $wishlistCount
 */
?>
<div class="dash-hero">
  <p class="dash-greeting">Hello, <span class="name"><?= e(explode(' ', $customer['name'])[0]) ?></span></p>
  <p class="dash-sub">Original • Tested • Reliable — your Dubai-imported laptop dashboard</p>
  <div class="dash-quick">
    <a href="<?= e(url('/shop')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2.5l2 11h11l2-8H6.5"/></svg> Shop Laptops</a>
    <a href="<?= e(url('/account/orders')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/></svg> My Orders</a>
    <a href="<?= e(url('/account/wishlist')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3"/></svg> Wishlist</a>
    <a href="<?= e(url('/account/support')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l18-5v12L3 14z"/></svg> Support</a>
  </div>
  <div class="dash-stats">
    <div class="dash-stat">
      <div class="dash-stat-num dash-num-lime"><?= (int) $orderStats['total'] ?></div>
      <div class="dash-stat-lbl">Total Orders</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num dash-num-amber"><?= (int)($orderStats['pending'] + $orderStats['confirmed'] + $orderStats['processing']) ?></div>
      <div class="dash-stat-lbl">In Progress</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num dash-num-green"><?= (int) $orderStats['completed'] ?></div>
      <div class="dash-stat-lbl">Completed</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num dash-num-lime"><?= (int) $warrantyCount ?></div>
      <div class="dash-stat-lbl">Warranty</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num dash-num-lime"><?= (int) $serviceCount ?></div>
      <div class="dash-stat-lbl">Service Jobs</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num dash-num-amber"><?= (int) $unreadNotifications ?></div>
      <div class="dash-stat-lbl">Notifications</div>
    </div>
  </div>
</div>

<?php if ($orderStats['total'] === 0 && $warrantyCount === 0 && $serviceCount === 0): ?>
<div class="dash-welcome">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-6l-2-3H5a2 2 0 0 0-2 2z"/></svg>
  <h3>Welcome to Rasidul Tech Point!</h3>
  <p>You haven't placed any orders yet. Browse our Dubai-imported, quality-checked laptops — each one individually inspected.</p>
  <a href="<?= e(url('/shop')) ?>" class="btn btn-primary">Browse Laptops</a>
</div>
<?php else: ?>

<div class="dash-section">
  <div class="dash-orders">
    <div class="dash-orders-head">
      <h2 class="dash-section-title">Recent Orders</h2>
      <a href="<?= e(url('/account/orders')) ?>" class="dash-orders-link">View all →</a>
    </div>
    <?php if ($recentOrders === []): ?>
      <div class="dash-orders-empty">No orders yet.</div>
    <?php else: ?>
      <?php foreach (array_slice($recentOrders, 0, 5) as $order): ?>
        <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" class="dash-order">
          <span class="dash-order-ref"><?= e($order['reference']) ?></span>
          <span class="dash-order-date"><?= e(local_datetime($order['created_at'])) ?></span>
          <span class="dash-order-total"><?= money($order['total']) ?></span>
          <span class="dash-status dash-status-info"><?= e(ucfirst($order['status'])) ?></span>
          <span class="dash-status dash-status-off"><?= e(ucfirst($order['payment_status'])) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="dash-quick-grid">
  <a href="<?= e(url('/account/warranty')) ?>" class="dash-quick-card">
    <span class="dash-quick-ic dash-ic-green"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6z"/></svg></span>
    <span class="dash-quick-text-block">
      <span class="dash-quick-text">Warranty</span>
      <span class="dash-quick-sub"><?= $warrantyCount ?> registration(s)</span>
    </span>
  </a>
  <a href="<?= e(url('/account/service-jobs')) ?>" class="dash-quick-card">
    <span class="dash-quick-ic dash-ic-blue"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg></span>
    <span class="dash-quick-text-block">
      <span class="dash-quick-text">Repairs</span>
      <span class="dash-quick-sub"><?= $serviceCount ?> service job(s)</span>
    </span>
  </a>
  <a href="<?= e(url('/account/wishlist')) ?>" class="dash-quick-card">
    <span class="dash-quick-ic dash-ic-lime"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3"/></svg></span>
    <span class="dash-quick-text-block">
      <span class="dash-quick-text">Wishlist</span>
      <span class="dash-quick-sub"><?= $wishlistCount ?> saved item(s)</span>
    </span>
  </a>
  <a href="<?= e(url('/account/notifications')) ?>" class="dash-quick-card">
    <span class="dash-quick-ic dash-ic-amber"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/></svg></span>
    <span class="dash-quick-text-block">
      <span class="dash-quick-text">Notifications</span>
      <span class="dash-quick-sub"><?= $unreadNotifications ?> unread</span>
    </span>
  </a>
</div>
<?php endif; ?>
