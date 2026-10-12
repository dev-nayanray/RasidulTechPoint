<?php /** @var list<array> $leads @var list<string> $statuses @var list<string> $sources */ ?>
<form method="get" action="<?= e(url('/admin/crm/leads')) ?>" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3" role="search">
    <div class="sm:col-span-2 space-y-1.5">
        <label for="cl-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="cl-q" name="q" value="<?= e($q) ?>" class="input" placeholder="Lead #, name, phone, email…">
    </div>
    <?= \App\Support\View::component('select', ['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => array_merge(['' => 'All'], array_combine($statuses, array_map(fn ($s) => ucfirst($s), $statuses)))]) ?>
    <div class="flex items-end"><button type="submit" class="btn-primary">Apply</button></div>
</form>
<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Lead #</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Name</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Phone</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Source</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Assigned</th>
            <th class="px-4 py-3 text-left font-medium text-slate-500">Next follow-up</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($leads as $l): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-mono text-blue-700"><a href="<?= e(url('/admin/crm/leads/' . $l['id'])) ?>"><?= e($l['lead_number']) ?></a></td>
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($l['name']) ?></td>
                <td class="px-4 py-3 text-slate-500"><?= e($l['phone']) ?></td>
                <td class="px-4 py-3 capitalize text-xs"><?= e(str_replace('_', ' ', $l['source'])) ?></td>
                <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $l['status']]) ?></td>
                <td class="px-4 py-3 text-xs text-slate-500"><?= e($l['assigned_to_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-xs text-slate-400"><?= $l['next_followup_at'] ? e(local_datetime($l['next_followup_at'], 'd M, g:i a')) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($leads === []): ?><p class="px-4 py-10 text-center text-sm text-slate-400">No leads found.</p><?php endif; ?>
    <?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/crm/leads', 'queryParams' => array_filter(['q' => $q, 'status' => $status])]) ?>
</div>
