<?php /** @var list<array> $faqs */ ?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/content')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Content</a></p>
<h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">FAQs</h1>
<details class="mt-4 rounded-xl border border-slate-200 bg-white p-5 text-sm" open>
    <summary class="cursor-pointer font-semibold text-slate-900">Add FAQ</summary>
    <form method="post" action="<?= e(url('/content/faqs')) ?>" class="mt-4 space-y-3" data-loading-form>
        <?= csrf_field() ?>
        <?= \App\Support\View::component('field', ['name' => 'question', 'label' => 'Question', 'required' => true]) ?>
        <div class="space-y-1.5">
            <label for="field-answer" class="block text-sm font-medium text-slate-700">Answer</label>
            <textarea id="field-answer" name="answer" rows="3" class="input" required></textarea>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <?= \App\Support\View::component('field', ['name' => 'category', 'label' => 'Category (optional)']) ?>
            <?= \App\Support\View::component('field', ['name' => 'display_order', 'label' => 'Display order', 'type' => 'number', 'value' => '0']) ?>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_published" value="1" checked> Published</label>
        <button type="submit" class="btn-primary text-xs">Create FAQ</button>
    </form>
</details>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Question</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Category</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Published</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($faqs as $f): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-slate-900 max-w-md truncate"><?= e($f['question']) ?></td>
                <td class="px-4 py-3 text-slate-500 text-xs"><?= e($f['category'] ?? '—') ?></td>
                <td class="px-4 py-3"><?= (int)$f['is_published'] === 1 ? '✓' : '—' ?></td>
                <td class="px-4 py-3">
                    <form method="post" action="<?= e(url('/content/faqs/' . $f['id'] . '/delete')) ?>" data-loading-form data-confirm="Delete this FAQ?">
                        <?= csrf_field() ?><button type="submit" class="btn-danger text-xs">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($faqs === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No FAQs yet.</p><?php endif; ?>
</div>
