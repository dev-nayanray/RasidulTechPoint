<?php /** @var list<array> $blocks */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Content</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Content Blocks</h1>
<p class="mt-1 text-sm text-slate-500">Editable HTML sections stored in the database. Edit the homepage HTML, about page intro, contact page text, etc.</p>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Key</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Title</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Published</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Updated</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($blocks as $b): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><?= e($b['block_key']) ?></td>
                <td class="px-4 py-3 text-slate-900"><?= e($b['title']) ?></td>
                <td class="px-4 py-3"><?= (int)$b['is_published'] === 1 ? \App\Support\View::component('badge', ['status' => 'active']) : \App\Support\View::component('badge', ['status' => 'inactive']) ?></td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= e(local_datetime($b['updated_at'] ?? $b['created_at'])) ?></td>
                <td class="px-4 py-3"><a href="<?= e(url('/content/blocks/' . $b['block_key'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($blocks === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No content blocks yet. <a href="<?= e(url('/content/blocks/homepage_html/edit')) ?>" class="text-blue-700 hover:underline">Create the homepage block →</a></p><?php endif; ?>
</div>
<div class="mt-4">
    <form method="get" action="<?= e(url('/content/blocks')) ?>" class="flex gap-2">
        <input name="new_key" class="input" placeholder="New block key (e.g. about_intro)">
    </form>
    <p class="mt-2 text-xs text-slate-500">Tip: use the key <code class="bg-slate-100 px-1 rounded">homepage_html</code> to edit the homepage HTML content stored in the database.</p>
</div>
