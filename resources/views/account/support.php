<?php /** @var array $profile
 *  @var list<array> $hours
 *  @var list<array> $faqs
 *  @var string $whatsapp
 *  @var bool $whatsappUsable
 *  @var string $address
 *  @var string $email
 *  @var bool $emailValid
 */
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<h1 class="text-xl font-bold tracking-tight text-navy-700">Support</h1>
<p class="mt-1 text-sm text-slate-500">Get help with orders, warranty, repairs, or choosing a laptop.</p>

<div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <?php if (!empty($profile['phone'])): ?>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
            <?= \App\Support\View::component('icon', ['name' => 'phone', 'class' => 'h-5 w-5']) ?>
        </span>
        <p class="mt-3 font-semibold text-navy-700 text-sm">Phone</p>
        <p class="mt-1 text-sm text-slate-600"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $profile['phone'])) ?>" class="hover:text-brand-blue hover:underline"><?= e($profile['phone']) ?></a></p>
    </div>
    <?php endif; ?>
    <?php if ($whatsappUsable): ?>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-green/10 text-brand-green">
            <?= \App\Support\View::component('icon', ['name' => 'megaphone', 'class' => 'h-5 w-5']) ?>
        </span>
        <p class="mt-3 font-semibold text-navy-700 text-sm">WhatsApp</p>
        <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $whatsapp)) ?>?text=<?= urlencode('Hi, I need help with my account') ?>" target="_blank" rel="noopener" class="mt-1 text-sm text-brand-green hover:underline">Chat now →</a>
    </div>
    <?php endif; ?>
    <?php if ($emailValid): ?>
    <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
            <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-5 w-5']) ?>
        </span>
        <p class="mt-3 font-semibold text-navy-700 text-sm">Email</p>
        <p class="mt-1 text-sm text-slate-600"><a href="mailto:<?= e($email) ?>" class="hover:text-brand-blue hover:underline break-all"><?= e($email) ?></a></p>
    </div>
    <?php endif; ?>
</div>

<?php if ($address !== ''): ?>
<div class="mt-4 rounded-xl bg-white ring-1 ring-slate-200 p-5">
    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
        <?= \App\Support\View::component('icon', ['name' => 'map-pin', 'class' => 'h-5 w-5']) ?>
    </span>
    <p class="mt-3 font-semibold text-navy-700 text-sm">Store address</p>
    <p class="mt-1 text-sm text-slate-600"><?= e($address) ?></p>
    <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($address) ?>" target="_blank" rel="noopener" class="mt-2 inline-flex text-xs font-semibold text-brand-blue hover:underline">Get directions →</a>
</div>
<?php endif; ?>

<?php if ($hours !== []): ?>
<div class="mt-4 rounded-xl bg-white ring-1 ring-slate-200 p-5">
    <p class="font-semibold text-navy-700 text-sm">Business hours</p>
    <ul class="mt-2 space-y-1 text-xs text-slate-600">
        <?php foreach ($hours as $h): ?>
            <li><?= e($dayNames[(int) $h['day_of_week']] ?? '?') ?>: <?php if ((int) $h['is_closed'] === 1): ?>Closed<?php else: ?><?= e(substr((string) $h['open_time'], 0, 5)) ?>–<?= e(substr((string) $h['close_time'], 0, 5)) ?><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php /* Quick links */ ?>
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <a href="<?= e(url('/account/orders')) ?>" class="block rounded-xl bg-white ring-1 ring-slate-200 p-4 hover:ring-brand-blue transition">
        <p class="font-semibold text-navy-700 text-sm">My Orders</p>
        <p class="text-xs text-slate-500">Track, view invoices, return</p>
    </a>
    <a href="<?= e(url('/account/warranty')) ?>" class="block rounded-xl bg-white ring-1 ring-slate-200 p-4 hover:ring-brand-blue transition">
        <p class="font-semibold text-navy-700 text-sm">Warranty</p>
        <p class="text-xs text-slate-500">View coverage + claims</p>
    </a>
    <a href="<?= e(url('/contact')) ?>" class="block rounded-xl bg-white ring-1 ring-slate-200 p-4 hover:ring-brand-blue transition">
        <p class="font-semibold text-navy-700 text-sm">Contact form</p>
        <p class="text-xs text-slate-500">Send us a message</p>
    </a>
</div>

<?php if ($faqs !== []): ?>
<div class="mt-6">
    <h2 class="text-base font-semibold text-navy-700">FAQs</h2>
    <dl class="mt-3 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
        <?php foreach ($faqs as $faq): ?>
            <details class="group p-4">
                <summary class="flex cursor-pointer items-center justify-between gap-4 font-medium text-navy-700 text-sm">
                    <?= e($faq['question']) ?>
                    <span class="text-slate-400 group-open:rotate-180 transition">
                        <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4']) ?>
                    </span>
                </summary>
                <dd class="mt-2 text-sm text-slate-600"><?= e($faq['answer']) ?></dd>
            </details>
        <?php endforeach; ?>
    </dl>
</div>
<?php endif; ?>
