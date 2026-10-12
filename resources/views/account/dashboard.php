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
  <p class="dash-greeting">Hello, <span class="name"><?= e(explode(' ', $customer['name'])[0]) ?></span> 👋</p>
  <p class="dash-sub">Original • Tested • Reliable — your Dubai-imported laptop dashboard</p>
  <div class="dash-quick">
    <a href="<?= e(url('/shop')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M3 4h2.5l2 11h11l2-8H6.5"/></svg> Shop Laptops</a>
    <a href="<?= e(url('/account/orders')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/></svg> My Orders</a>
    <a href="<?= e(url('/account/wishlist')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3"/></svg> Wishlist</a>
    <a href="<?= e(url('/account/support')) ?>"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M3 11l18-5v12L3 14z"/></svg> Support</a>
  </div>
  <div class="dash-stats">
    <div class="dash-stat">
      <div class="dash-stat-num" style="color:#7AFB01"><?= (int) $orderStats['total'] ?></div>
      <div class="dash-stat-lbl">Total Orders</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num" style="color:#fbbf24"><?= (int)($orderStats['pending'] + $orderStats['confirmed'] + $orderStats['processing']) ?></div>
      <div class="dash-stat-lbl">In Progress</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num" style="color:#4ade80"><?= (int) $orderStats['completed'] ?></div>
      <div class="dash-stat-lbl">Completed</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num" style="color:#7AFB01"><?= (int) $warrantyCount ?></div>
      <div class="dash-stat-lbl">Warranty</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num" style="color:#7AFB01"><?= (int) $serviceCount ?></div>
      <div class="dash-stat-lbl">Service Jobs</div>
    </div>
    <div class="dash-stat">
      <div class="dash-stat-num" style="color:#fbbf24"><?= (int) $unreadNotifications ?></div>
      <div class="dash-stat-lbl">Notifications</div>
    </div>
  </div>
</div>

<?php if ($orderStats['total'] === 0 && $warrantyCount === 0 && $serviceCount === 0): ?>
<div style="margin-top:20px;padding:32px;text-align:center;border:2px dashed #D5DEE9;border-radius:16px;background:#fff">
  <p style="font-size:20px;font-weight:800;color:#01011D">Welcome to Rasidul Tech Point!</p>
  <p style="margin-top:8px;color:#475569;font-size:14px;max-width:420px;margin-left:auto;margin-right:auto">You haven't placed any orders yet. Browse our Dubai-imported, quality-checked laptops — each one individually inspected.</p>
  <a href="<?= e(url('/shop')) ?>" class="btn btn-primary" style="margin-top:16px">Browse Laptops →</a>
</div>
<?php else: ?>

<div style="margin-top:24px;display:grid;gap:16px;grid-template-columns:1fr">
  <div style="background:#fff;border:1px solid #D5DEE9;border-radius:16px;padding:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <h2 style="font-size:18px;font-weight:800;color:#01011D">Recent Orders</h2>
      <a href="<?= e(url('/account/orders')) ?>" style="font-size:13px;font-weight:700;color:#2563EB">View all →</a>
    </div>
    <?php if ($recentOrders === []): ?>
      <p style="color:#64748B;font-size:14px">No orders yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:10px">
        <?php foreach (array_slice($recentOrders, 0, 5) as $order): ?>
          <a href="<?= e(url('/account/orders/' . $order['reference'])) ?>" style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#F1F5F9;border-radius:12px;transition:background .12s;text-decoration:none" onmouseover="this.style.background='#E3ECFD'" onmouseout="this.style.background='#F1F5F9'">
            <div>
              <div style="font-family:'IBM Plex Mono',monospace;font-weight:700;font-size:13px;color:#01011D"><?= e($order['reference']) ?></div>
              <div style="font-size:11px;color:#64748B;margin-top:2px"><?= e(local_datetime($order['created_at'])) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-weight:800;font-size:16px;color:#01011D"><?= money($order['total']) ?></div>
              <div style="margin-top:4px;display:flex;gap:4px;justify-content:flex-end">
                <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;background:rgba(37,99,235,.1);color:#2563EB;text-transform:uppercase"><?= e(ucfirst($order['status'])) ?></span>
                <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;background:rgba(220,38,38,.1);color:#dc2626;text-transform:uppercase"><?= e(ucfirst($order['payment_status'])) ?></span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div style="margin-top:16px;display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr))">
  <a href="<?= e(url('/account/warranty')) ?>" style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #D5DEE9;border-radius:14px;transition:border-color .15s;text-decoration:none">
    <span style="width:40px;height:40px;border-radius:10px;background:rgba(22,163,74,.1);display:flex;align-items:center;justify-content:center;color:#16A34A"><svg class="ic" viewBox="0 0 24 24"><path d="M12 2l9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6z"/></svg></span>
    <div><div style="font-weight:700;color:#01011D;font-size:14px">Warranty</div><div style="font-size:12px;color:#64748B"><?= $warrantyCount ?> registration(s)</div></div>
  </a>
  <a href="<?= e(url('/account/service-jobs')) ?>" style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #D5DEE9;border-radius:14px;transition:border-color .15s;text-decoration:none">
    <span style="width:40px;height:40px;border-radius:10px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center;color:#2563EB"><svg class="ic" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg></span>
    <div><div style="font-weight:700;color:#01011D;font-size:14px">Repairs</div><div style="font-size:12px;color:#64748B"><?= $serviceCount ?> service job(s)</div></div>
  </a>
  <a href="<?= e(url('/account/wishlist')) ?>" style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #D5DEE9;border-radius:14px;transition:border-color .15s;text-decoration:none">
    <span style="width:40px;height:40px;border-radius:10px;background:rgba(122,251,1,.1);display:flex;align-items:center;justify-content:center;color:#7AFB01"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3"/></svg></span>
    <div><div style="font-weight:700;color:#01011D;font-size:14px">Wishlist</div><div style="font-size:12px;color:#64748B"><?= $wishlistCount ?> saved item(s)</div></div>
  </a>
  <a href="<?= e(url('/account/notifications')) ?>" style="display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border:1px solid #D5DEE9;border-radius:14px;transition:border-color .15s;text-decoration:none">
    <span style="width:40px;height:40px;border-radius:10px;background:rgba(251,191,36,.1);display:flex;align-items:center;justify-content:center;color:#fbbf24"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/></svg></span>
    <div><div style="font-weight:700;color:#01011D;font-size:14px">Notifications</div><div style="font-size:12px;color:#64748B"><?= $unreadNotifications ?> unread</div></div>
  </a>
</div>
<?php endif; ?>
