<?php /** @var list<array> $banners */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Content</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Banners</h1>
<details class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm" open>
    <summary class="cursor-pointer font-semibold text-slate-900">Add banner</summary>
    <form method="post" action="<?= e(url('/content/banners')) ?>" enctype="multipart/form-data" class="mt-4 grid gap-3 sm:grid-cols-2" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'title', 'label' => 'Title', 'required' => true]) ?>
        <div class="space-y-1.5">
            <label for="field-image" class="block text-sm font-medium text-slate-700">Banner image</label>
            <input id="field-image" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="input" required>
            <p class="text-xs text-slate-500">Max 4 MiB. JPEG, PNG, WebP, GIF.</p>
        </div>
        <?= \App\Support\View::component('field', ['name' => 'subtitle', 'label' => 'Subtitle (optional)']) ?>
        <?= \App\Support\View::component('field', ['name' => 'link_url', 'label' => 'Link URL (optional)']) ?>
        <div class="space-y-1.5">
            <label for="field-position" class="block text-sm font-medium text-slate-700">Position</label>
            <select id="field-position" name="position" class="input">
                <option value="hero">Hero</option>
                <option value="sidebar">Sidebar</option>
                <option value="footer">Footer</option>
                <option value="inline">Inline</option>
            </select>
        </div>
        <?= \App\Support\View::component('field', ['name' => 'display_order', 'label' => 'Display order', 'type' => 'number', 'value' => '0']) ?>
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_published" value="1" checked> Published</label>
        <div class="sm:col-span-2"><button type="submit" class="btn-primary text-xs">Create banner</button></div>
    </form>
</details>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Title</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Image</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Position</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Published</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($banners as $b): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-slate-900"><?= e($b['title']) ?></td>
                <td class="px-4 py-3"><img src="<?= e(url('/' . $b['image_path'])) ?>" alt="<?= e($b['title']) ?>" class="h-12 w-20 object-cover rounded"></td>
                <td class="px-4 py-3 capitalize text-slate-500"><?= e($b['position']) ?></td>
                <td class="px-4 py-3"><?= (int)$b['is_published'] === 1 ? '✓' : '—' ?></td>
                <td class="px-4 py-3">
                    <form method="post" action="<?= e(url('/content/banners/' . $b['id'] . '/delete')) ?>" data-loading-form data-confirm="Delete this banner?">
                        <?= csrf_field() ?><button type="submit" class="btn-danger text-xs">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($banners === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No banners yet.</p><?php endif; ?>
</div>
