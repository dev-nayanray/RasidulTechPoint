<?php /** Minimal shell for error pages — no dependency on session/nav state. */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Error') ?> — <?= e(\App\Support\Config::get('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/img/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 flex items-center justify-center p-6">
    <main class="w-full max-w-lg bg-white rounded-xl border border-slate-200 shadow-sm p-8 text-center">
        <p class="text-6xl font-bold text-navy-700"><?= e(preg_replace('/\D/', '', $pageTitle ?? '500') ?: '500') ?></p>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">
            <?= e(match ((int) (preg_replace('/\D/', '', $pageTitle ?? '500') ?: 500)) {
                403 => 'Access denied',
                404 => 'Page not found',
                419 => 'Session expired',
                422 => 'Invalid input',
                429 => 'Too many attempts',
                default => 'Something went wrong',
            }) ?>
        </h1>
        <p class="mt-2 text-slate-500"><?= e($message ?? '') ?></p>
        <a href="<?= e(url('/')) ?>" class="mt-6 inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Back to homepage</a>
    </main>
</body>
</html>
