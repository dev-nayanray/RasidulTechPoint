<?php /** Toast/alert region: renders flashed success/error/info messages once. */ ?>
<?php
$success = \App\Support\Session::getFlash('success');
$error = \App\Support\Session::getFlash('error');
$info = \App\Support\Session::getFlash('info');
?>
<?php if ($success !== null || $error !== null || $info !== null): ?>
    <div class="fixed inset-x-0 top-4 z-50 mx-auto w-full max-w-md space-y-3 px-4" data-toast-region aria-live="polite">
        <?php if ($success !== null): ?>
            <div class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 shadow-sm" data-toast>
                <?= \App\Support\View::component('icon', ['name' => 'check-circle', 'class' => 'h-5 w-5 shrink-0 text-green-600']) ?>
                <p class="flex-1 break-words"><?= e($success) ?></p>
                <button type="button" class="text-green-600 hover:text-green-800" data-toast-dismiss aria-label="Dismiss">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 shadow-sm" data-toast>
                <?= \App\Support\View::component('icon', ['name' => 'alert', 'class' => 'h-5 w-5 shrink-0 text-red-600']) ?>
                <p class="flex-1 break-words"><?= e($error) ?></p>
                <button type="button" class="text-red-600 hover:text-red-800" data-toast-dismiss aria-label="Dismiss">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($info !== null): ?>
            <div class="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 shadow-sm" data-toast>
                <?= \App\Support\View::component('icon', ['name' => 'bell', 'class' => 'h-5 w-5 shrink-0 text-blue-600']) ?>
                <p class="flex-1 break-words"><?= e($info) ?></p>
                <button type="button" class="text-blue-600 hover:text-blue-800" data-toast-dismiss aria-label="Dismiss">&times;</button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
