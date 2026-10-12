<?php /** @var list<array> $pages */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Content</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Info Pages</h1>
<div class="mt-4"><a href="<?= e(url('/content/pages/create')) ?>" class="btn-primary">New page</a></div>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Slug</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Title</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Published</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($pages as $p): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700">/page/<?= e($p['slug']) ?></td>
                <td class="px-4 py-3 text-slate-900"><?= e($p['title']) ?></td>
                <td class="px-4 py-3"><?= (int)$p['is_published'] === 1 ? '✓' : '—' ?></td>
                <td class="px-4 py-3 flex gap-2">
                    <a href="<?= e(url('/content/pages/' . $p['id'] . '/edit')) ?>" class="btn-secondary text-xs">Edit</a>
                    <form method="post" action="<?= e(url('/content/pages/' . $p['id'] . '/delete')) ?>" data-loading-form data-confirm="Delete this page?">
                        <?= csrf_field() ?><button type="submit" class="btn-danger text-xs">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pages === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No info pages yet.</p><?php endif; ?>
</div>
