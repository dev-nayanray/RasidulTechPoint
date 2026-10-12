<?php /** Rendered when no payment methods are enabled in payment_methods_catalog. */ ?>
<div class="mx-auto max-w-2xl px-4 sm:px-6 py-12">
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-8 text-center">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700">
            <?= \App\Support\View::component('icon', ['name' => 'alert', 'class' => 'h-6 w-6']) ?>
        </span>
        <h1 class="mt-4 text-xl font-bold text-navy-700">Checkout is temporarily unavailable</h1>
        <p class="mt-2 text-sm text-slate-600 max-w-md mx-auto">No payment methods are currently enabled. Please contact us to complete your purchase, or check back shortly.</p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
            <a href="<?= e(url('/contact')) ?>" class="inline-flex items-center rounded-lg bg-brand-blue px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">Contact us</a>
            <a href="<?= e(url('/cart')) ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-navy-700 hover:bg-slate-50">Back to cart</a>
        </div>
    </div>
</div>
