<?php /** @var array $business
 *  @var ?string $hours
 *  @var string $whatsapp
 *  @var bool $whatsappUsable
 *  @var string $email
 *  @var bool $emailValid
 *  @var string $address
 *  @var array<string,string> $topics
 *  @var list<array> $faqs
 *  @var array $old
 *  @var array $errors
 *  @var bool $emailDeliveryConfigured
 */
?>
<?php /* ====== A. Contact hero ====== */ ?>
<section class="relative bg-navy-700 text-white overflow-hidden">
    <div class="absolute inset-0 opacity-30" aria-hidden="true" style="background: radial-gradient(60% 70% at 80% 20%, rgba(37,99,235,0.4) 0%, transparent 70%);"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16 text-center">
        <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold tracking-wide uppercase text-blue-200 ring-1 ring-white/10">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-green"></span> Contact Rasidul Tech Point
        </p>
        <h1 class="mt-4 text-3xl sm:text-4xl font-bold tracking-tight">Let's find the right laptop for you.</h1>
        <p class="mt-3 max-w-2xl mx-auto text-base sm:text-lg text-slate-300">Need help choosing a laptop, understanding its condition, or asking about an order? Get in touch with Rasidul Tech Point.</p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm">
            <?php if ($emailValid): ?>
                <a href="mailto:<?= e($email) ?>" class="inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2 font-semibold ring-1 ring-white/10 hover:bg-white/15 transition">
                    <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-4 w-4']) ?>
                    Email us
                </a>
            <?php endif; ?>
            <?php if ($whatsappUsable): ?>
                <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $whatsapp)) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg bg-brand-green px-4 py-2 font-semibold hover:bg-green-700 transition">
                    <?= \App\Support\View::component('icon', ['name' => 'megaphone', 'class' => 'h-4 w-4']) ?>
                    WhatsApp
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php /* ====== B. Contact information cards ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">

        <?php /* Phone card */ ?>
        <?php $phone = (string) ($business['phone'] ?? ''); ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 <?= $phone === '' ? 'opacity-60' : '' ?>">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'phone', 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-navy-700">Phone</p>
            <?php if ($phone !== ''): ?>
                <p class="mt-1 text-sm text-slate-600"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>" class="hover:text-brand-blue hover:underline"><?= e($phone) ?></a></p>
            <?php else: ?>
                <p class="mt-1 text-sm text-slate-400">Not configured yet. Use the contact form below.</p>
            <?php endif; ?>
        </div>

        <?php /* WhatsApp card */ ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 <?= !$whatsappUsable ? 'opacity-60' : '' ?>">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-green/10 text-brand-green">
                <?= \App\Support\View::component('icon', ['name' => 'megaphone', 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-navy-700">WhatsApp</p>
            <?php if ($whatsappUsable): ?>
                <p class="mt-1 text-sm text-slate-600"><a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $whatsapp)) ?>?text=<?= urlencode('Hi Rasidul Tech Point, I have a question about a laptop') ?>" target="_blank" rel="noopener" class="hover:text-brand-green hover:underline"><?= e($whatsapp) ?></a></p>
                <p class="mt-1 text-xs text-slate-400">Opens WhatsApp click-to-chat in a new tab.</p>
            <?php else: ?>
                <p class="mt-1 text-sm text-slate-400">Not configured. The admin can enable WhatsApp from <code>System Settings → Integrations</code>.</p>
            <?php endif; ?>
        </div>

        <?php /* Email card */ ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 <?= !$emailValid ? 'opacity-60' : '' ?>">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-navy-700">Email</p>
            <?php if ($emailValid): ?>
                <p class="mt-1 text-sm text-slate-600"><a href="mailto:<?= e($email) ?>" class="hover:text-brand-blue hover:underline break-all"><?= e($email) ?></a></p>
            <?php else: ?>
                <p class="mt-1 text-sm text-slate-400">Not configured. The admin can set it under <code>Business Settings</code>.</p>
            <?php endif; ?>
        </div>

        <?php /* Address card */ ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 <?= $address === '' ? 'opacity-60' : '' ?>">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'map-pin', 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-navy-700">Physical store address</p>
            <?php if ($address !== ''): ?>
                <p class="mt-1 text-sm text-slate-600"><?= e($address) ?></p>
                <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($address) ?>" target="_blank" rel="noopener" class="mt-1 inline-flex text-xs font-semibold text-brand-blue hover:underline">Get directions →</a>
            <?php else: ?>
                <p class="mt-1 text-sm text-slate-400">Not configured. Visit the admin <code>Business Settings</code> page.</p>
            <?php endif; ?>
        </div>

        <?php /* Business hours card */ ?>
        <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 <?= $hours === null ? 'opacity-60' : '' ?>">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'clock', 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-navy-700">Business hours</p>
            <?php if ($hours !== null): ?>
                <p class="mt-1 text-sm text-slate-600"><?= e($hours) ?></p>
            <?php else: ?>
                <p class="mt-1 text-sm text-slate-400">Not configured. Set hours under <code>Business Settings → Business hours</code>.</p>
            <?php endif; ?>
        </div>

        <?php /* Order reference help card */ ?>
        <div class="rounded-xl bg-blue-50 ring-1 ring-blue-200 p-5">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'clipboard', 'class' => 'h-5 w-5']) ?>
            </span>
            <p class="mt-3 font-semibold text-navy-700">Have an order?</p>
            <p class="mt-1 text-sm text-slate-600">Include your order reference (e.g. <code>RTP-251012-A1B2C3</code>) in the form so we can find it quickly.</p>
        </div>
    </div>

    <?php if (!$emailDeliveryConfigured): ?>
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <strong>Email delivery note:</strong> Email notifications on this server are currently in <code>log</code> mode — your message will be received and stored safely, but an automatic email confirmation may not arrive at your inbox. Our team reviews incoming inquiries during business hours.
        </div>
    <?php endif; ?>
</section>

<?php /* ====== C. Contact form ====== */ ?>
<section id="contact-form" class="bg-slate-50 border-y border-slate-200">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-12 sm:py-16">
        <div class="text-center max-w-xl mx-auto">
            <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Send us a message</h2>
            <p class="mt-2 text-sm text-slate-500">We typically respond within one business day. All fields with <span class="text-red-600">*</span> are required.</p>
        </div>

        <form method="post" action="<?= e(url('/contact')) ?>" class="mt-8 rounded-2xl bg-white ring-1 ring-slate-200 p-6 sm:p-8 space-y-5" data-loading-form>
            <?= csrf_field() ?>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label for="contact-name" class="block text-sm font-medium text-navy-700">Full name <span class="text-red-600">*</span></label>
                    <input id="contact-name" name="name" type="text" required maxlength="120" value="<?= e($old['name'] ?? '') ?>" class="input text-sm" autocomplete="name">
                </div>
                <div class="space-y-1.5">
                    <label for="contact-email" class="block text-sm font-medium text-navy-700">Email <span class="text-red-600">*</span></label>
                    <input id="contact-email" name="email" type="email" required maxlength="190" value="<?= e($old['email'] ?? '') ?>" class="input text-sm" autocomplete="email">
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label for="contact-phone" class="block text-sm font-medium text-navy-700">Phone <span class="text-xs font-normal text-slate-400">(optional)</span></label>
                    <input id="contact-phone" name="phone" type="tel" value="<?= e($old['phone'] ?? '') ?>" class="input text-sm" autocomplete="tel" placeholder="+8801XXXXXXXXX">
                </div>
                <div class="space-y-1.5">
                    <label for="contact-topic" class="block text-sm font-medium text-navy-700">Inquiry topic <span class="text-red-600">*</span></label>
                    <select id="contact-topic" name="topic" required class="input text-sm">
                        <?php foreach ($topics as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($old['topic'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="space-y-1.5">
                <label for="contact-subject" class="block text-sm font-medium text-navy-700">Subject <span class="text-xs font-normal text-slate-400">(optional)</span></label>
                <input id="contact-subject" name="subject" type="text" maxlength="255" value="<?= e($old['subject'] ?? '') ?>" class="input text-sm" placeholder="A short summary of your question">
            </div>

            <div class="space-y-1.5">
                <label for="contact-message" class="block text-sm font-medium text-navy-700">Message <span class="text-red-600">*</span></label>
                <textarea id="contact-message" name="message" rows="6" required maxlength="5000" class="input text-sm" placeholder="Tell us what you need help with — model, condition, order number, budget, etc."><?= e($old['message'] ?? '') ?></textarea>
                <p class="text-xs text-slate-400">Min 10 characters, max 5000 characters.</p>
            </div>

            <div class="space-y-1.5">
                <label for="contact-order-reference" class="block text-sm font-medium text-navy-700">Order reference <span class="text-xs font-normal text-slate-400">(optional, for order support)</span></label>
                <input id="contact-order-reference" name="order_reference" type="text" maxlength="40" value="<?= e($old['order_reference'] ?? '') ?>" class="input text-sm font-mono" placeholder="RTP-251012-A1B2C3" pattern="[A-Za-z0-9-]{3,40}">
            </div>

            <div class="pt-2 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-400">By submitting, you agree to be contacted about your inquiry. We do not share your details.</p>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-blue px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition">Send message</button>
            </div>
        </form>
    </div>
</section>

<?php /* ====== D. Frequently asked questions ====== */ ?>
<?php if (!empty($faqs)): ?>
<section class="mx-auto max-w-3xl px-4 sm:px-6 py-12 sm:py-16">
    <div class="text-center">
        <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Frequently asked questions</h2>
        <p class="mt-2 text-sm text-slate-500">Answers come from the configurable business policies managed in the admin CMS.</p>
    </div>
    <dl class="mt-8 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
        <?php foreach ($faqs as $faq): ?>
            <details class="group p-5">
                <summary class="flex cursor-pointer items-center justify-between gap-4 font-medium text-navy-700">
                    <?= e($faq['question']) ?>
                    <span class="text-slate-400 group-open:rotate-180 transition">
                        <?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-5 w-5']) ?>
                    </span>
                </summary>
                <dd class="mt-3 text-sm text-slate-600 leading-relaxed"><?= e($faq['answer']) ?></dd>
            </details>
        <?php endforeach; ?>
    </dl>
</section>
<?php else: ?>
<section class="mx-auto max-w-3xl px-4 sm:px-6 py-12">
    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <p class="text-sm text-slate-500">FAQ entries will appear here once the content manager publishes them.</p>
        <p class="mt-1 text-xs text-slate-400">Admin → Marketing &amp; Content → FAQs</p>
    </div>
</section>
<?php endif; ?>

<?php /* ====== E. Location & directions ====== */ ?>
<?php if ($address !== ''): ?>
<section class="bg-slate-50 border-t border-slate-200">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-12">
        <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-navy-700">Visit our store</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5">
                <p class="text-xs uppercase tracking-wider text-slate-400">Address</p>
                <p class="mt-1 font-medium text-navy-700"><?= e($address) ?></p>
                <?php if ($hours !== null): ?>
                    <p class="mt-3 text-xs uppercase tracking-wider text-slate-400">Hours</p>
                    <p class="mt-1 text-sm text-slate-600"><?= e($hours) ?></p>
                <?php endif; ?>
            </div>
            <div class="rounded-xl bg-white ring-1 ring-slate-200 p-5 flex flex-col">
                <p class="text-xs uppercase tracking-wider text-slate-400">Directions</p>
                <p class="mt-1 text-sm text-slate-600">Open in Google Maps to plan your route:</p>
                <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($address) ?>" target="_blank" rel="noopener" class="mt-auto inline-flex items-center justify-center gap-2 rounded-lg bg-navy-700 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800 transition">
                    <?= \App\Support\View::component('icon', ['name' => 'map-pin', 'class' => 'h-4 w-4']) ?>
                    Get directions
                </a>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-400">We don't embed a map widget on this page — you'll get a clean link to Google Maps with the actual configured address.</p>
    </div>
</section>
<?php endif; ?>

<?php /* ====== F. Additional support links ====== */ ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-12">
    <h2 class="text-center text-xl sm:text-2xl font-bold tracking-tight text-navy-700">More help</h2>
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="<?= e(url('/shop')) ?>" class="group rounded-xl bg-white ring-1 ring-slate-200 p-5 transition hover:ring-brand-blue hover:shadow-md flex items-center gap-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'shopping-cart', 'class' => 'h-5 w-5']) ?>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-navy-700">Shop laptops</p>
                <p class="text-xs text-slate-500">Browse the full inventory</p>
            </div>
            <span class="text-slate-300 group-hover:text-brand-blue"><?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?></span>
        </a>

        <a href="<?= e(url('/account')) ?>" class="group rounded-xl bg-white ring-1 ring-slate-200 p-5 transition hover:ring-brand-blue hover:shadow-md flex items-center gap-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'clipboard', 'class' => 'h-5 w-5']) ?>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-navy-700">Order tracking</p>
                <p class="text-xs text-slate-500">Sign in to view past orders</p>
            </div>
            <span class="text-slate-300 group-hover:text-brand-blue"><?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?></span>
        </a>

        <a href="<?= e(url('/page/warranty')) ?>" class="group rounded-xl bg-white ring-1 ring-slate-200 p-5 transition hover:ring-brand-blue hover:shadow-md flex items-center gap-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'shield', 'class' => 'h-5 w-5']) ?>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-navy-700">Warranty information</p>
                <p class="text-xs text-slate-500">Per-unit policy details</p>
            </div>
            <span class="text-slate-300 group-hover:text-brand-blue"><?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?></span>
        </a>

        <a href="<?= e(url('/page/returns')) ?>" class="group rounded-xl bg-white ring-1 ring-slate-200 p-5 transition hover:ring-brand-blue hover:shadow-md flex items-center gap-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'package-check', 'class' => 'h-5 w-5']) ?>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-navy-700">Return policy</p>
                <p class="text-xs text-slate-500">Conditions + how to start a return</p>
            </div>
            <span class="text-slate-300 group-hover:text-brand-blue"><?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?></span>
        </a>

        <a href="<?= e(url('/page/privacy')) ?>" class="group rounded-xl bg-white ring-1 ring-slate-200 p-5 transition hover:ring-brand-blue hover:shadow-md flex items-center gap-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'file-text', 'class' => 'h-5 w-5']) ?>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-navy-700">Privacy policy</p>
                <p class="text-xs text-slate-500">How we handle your details</p>
            </div>
            <span class="text-slate-300 group-hover:text-brand-blue"><?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?></span>
        </a>

        <a href="<?= e(url('/about')) ?>" class="group rounded-xl bg-white ring-1 ring-slate-200 p-5 transition hover:ring-brand-blue hover:shadow-md flex items-center gap-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-brand-blue">
                <?= \App\Support\View::component('icon', ['name' => 'users', 'class' => 'h-5 w-5']) ?>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-navy-700">About us</p>
                <p class="text-xs text-slate-500">Learn more about the business</p>
            </div>
            <span class="text-slate-300 group-hover:text-brand-blue"><?= \App\Support\View::component('icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 -rotate-90']) ?></span>
        </a>
    </div>
</section>
