<?php /** @var array $errors */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/roles')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Roles &amp; permissions</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Create role</h1>
<p class="mt-1 text-sm text-slate-500">Create a custom (non-system) role. After creation you can assign granular permissions from the edit page.</p>

<form method="post" action="<?= e(url('/admin/roles')) ?>" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6" novalidate data-loading-form>
    <?= csrf_field() ?>
    <?= \App\Support\View::component('field', ['name' => 'name', 'label' => 'Role name', 'required' => true, 'value' => old('name', ''), 'help' => 'Human-readable, e.g. "Shop Supervisor"', 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'slug', 'label' => 'Slug', 'required' => true, 'value' => old('slug', ''), 'help' => 'Lowercase letters, numbers, hyphens. Used in code and URLs.', 'errors' => $errors ?? []]) ?>
    <?= \App\Support\View::component('field', ['name' => 'description', 'label' => 'Description', 'value' => old('description', ''), 'help' => 'What this role is for (optional)', 'errors' => $errors ?? []]) ?>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary">Create role</button>
        <a href="<?= e(url('/admin/roles')) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
