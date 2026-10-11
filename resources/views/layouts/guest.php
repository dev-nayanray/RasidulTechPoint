<?php /** Centered card layout for login/register/password pages. */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Welcome') ?> — <?= e(\App\Support\Config::get('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 dark:bg-slate-950 flex flex-col items-center justify-center p-4 sm:p-6">
    <a href="<?= e(url('/')) ?>" class="mb-8 flex flex-col items-center gap-2 text-center">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-navy-700 text-white font-bold text-lg">RTP</span>
        <span class="text-xl font-semibold tracking-tight text-slate-900"><?= e(\App\Support\Config::get('app.name')) ?></span>
        <span class="text-xs text-slate-500">Dubai Imported | Authentic Used Laptops | Tested &amp; Quality Checked</span>
    </a>

    <main class="w-full max-w-md bg-white rounded-xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <?= \App\Support\View::component('flash') ?>
        <?= $content ?? '' ?>
    </main>

    <p class="mt-6 text-xs text-slate-400">v<?= e(\App\Support\Config::get('app.version')) ?></p>
    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
