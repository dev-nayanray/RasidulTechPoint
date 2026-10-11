<?php /** Generic error page rendered inside layouts/bare by the exception handler. */ ?>
<p class="text-6xl font-bold text-navy-700"><?= e($status ?? '500') ?></p>
<h1 class="mt-3 text-xl font-semibold text-slate-900">
    <?= e(match ((int) ($status ?? 500)) {
        403 => 'Access denied',
        404 => 'Page not found',
        419 => 'Session expired',
        422 => 'Invalid input',
        429 => 'Too many attempts',
        default => 'Something went wrong',
    }) ?>
</h1>
<p class="mt-2 text-slate-500"><?= e($message ?? '') ?></p>
<a href="<?= e(url('/')) ?>" class="mt-6 inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Back to homepage</a>
<?php if (!empty($debug)): ?>
    <pre class="mt-6 text-left text-xs bg-slate-50 border border-slate-200 rounded-lg p-4 overflow-x-auto text-slate-600"><?= e(($debug['exception'] ?? '') . ': ' . ($debug['message'] ?? '') . "\n@" . ($debug['file'] ?? '')) ?></pre>
    <?php if (!empty($debug['trace'])): ?>
        <pre class="mt-2 text-left text-[11px] bg-slate-50 border border-slate-200 rounded-lg p-4 overflow-x-auto text-slate-500 max-h-64 overflow-y-auto"><?= e(implode("\n", $debug['trace'])) ?></pre>
    <?php endif; ?>
<?php endif; ?>
