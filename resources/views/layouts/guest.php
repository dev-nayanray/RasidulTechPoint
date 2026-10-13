<?php /** Centered card layout for login/register/password pages. */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14243A">
    <title><?= e($pageTitle ?? 'Welcome') ?> — <?= e(\App\Support\Config::get('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <style><?php include BASE_PATH . '/resources/views/storefront/_design_css.php'; ?></style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4 sm:p-6" style="background:linear-gradient(135deg,#F1F5F9 0%,#E3ECFD 100%)">
    <a href="<?= e(url('/')) ?>" class="mb-8 flex flex-col items-center gap-3 text-center" aria-label="Rasidul Tech Point home">
        <span class="logomark" style="background:#14243A;padding:2px;overflow:hidden">
            <img src="<?= e(url('assets/img/logo-mark.svg')) ?>" width="100%" height="100%" alt="RTP" style="display:block;border-radius:inherit">
        </span>
        <span class="flex flex-col items-center gap-1">
            <span class="text-xl font-semibold tracking-tight text-slate-900"><?= e(\App\Support\Config::get('app.name')) ?></span>
            <span class="text-xs text-slate-500">Dubai Imported | Authentic Used Laptops | Tested &amp; Quality Checked</span>
        </span>
    </a>

    <main class="w-full max-w-md bg-white rounded-xl border border-slate-200 shadow-lg p-6 sm:p-8" style="box-shadow:0 24px 60px rgba(20,36,58,.12)">
        <?= \App\Support\View::component('flash') ?>
        <?= $content ?? '' ?>
    </main>

    <p class="mt-6 text-xs text-slate-400">v<?= e(\App\Support\Config::get('app.version')) ?></p>
    <script type="module" src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
