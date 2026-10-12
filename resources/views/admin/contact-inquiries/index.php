<?php /** @var list<array> $inquiries
 *  @var list<string> $statuses
 *  @var array{q:string,status:string,from:string,to:string,sort:string} $filters
 *  @var array{total:int,new:int,responded:int,closed:int,spam:int} $counts
 *  @var int $total
 *  @var int $page
 *  @var int $pages
 *  @var bool $canManage
 */
?>

<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Contact Inquiries</h1>
        <p class="mt-1 text-sm text-slate-500">Submissions from the public contact form. Every successful form submission is stored here immediately.</p>
    </div>
</div>

<?php if ($counts['new'] > 0): ?>
    <a href="<?= e(url('/admin/contact-inquiries?status=new')) ?>" class="mt-3 inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
        <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'h-4 w-4']) ?>
        <?= (int) $counts['new'] ?> new inquiry(ies) awaiting a reply
    </a>
<?php endif; ?>

<div class="mt-4 grid gap-3 grid-cols-2 lg:grid-cols-4">
    <?php foreach ([['Total', $counts['total'], 'text-slate-900'], ['New', $counts['new'], 'text-amber-600'], ['Responded', $counts['responded'], 'text-brand-green'], ['Closed', $counts['closed'], 'text-slate-400']] as [$label, $value, $tone]): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wider text-slate-400"><?= e($label) ?></p>
            <p class="mt-1 text-2xl font-bold <?= $tone ?>"><?= (int) $value ?></p>
        </div>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e(url('/admin/contact-inquiries')) ?>" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6" role="search">
    <div class="lg:col-span-2 space-y-1.5">
        <label for="ci-q" class="block text-sm font-medium text-slate-700">Search</label>
        <input id="ci-q" name="q" value="<?= e($filters['q']) ?>" class="input" placeholder="Inquiry number, name, email, subject…">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'status', 'label' => 'Status', 'value' => $filters['status'],
        'options' => array_merge(['' => 'All statuses'], array_combine($statuses, array_map('ucfirst', $statuses))),
    ]) ?>
    <div class="space-y-1.5">
        <label for="ci-from" class="block text-sm font-medium text-slate-700">From date</label>
        <input id="ci-from" type="date" name="from" value="<?= e($filters['from']) ?>" class="input">
    </div>
    <div class="space-y-1.5">
        <label for="ci-to" class="block text-sm font-medium text-slate-700">To date</label>
        <input id="ci-to" type="date" name="to" value="<?= e($filters['to']) ?>" class="input">
    </div>
    <?= \App\Support\View::component('select', [
        'name' => 'sort', 'label' => 'Sort', 'value' => $filters['sort'],
        'options' => ['newest' => 'Newest first', 'oldest' => 'Oldest first'],
    ]) ?>
    <div class="sm:col-span-2 lg:col-span-6 flex items-end gap-2">
        <button type="submit" class="btn-primary">Apply filters</button>
        <a href="<?= e(url('/admin/contact-inquiries')) ?>" class="btn-secondary">Reset</a>
        <p class="ml-auto text-sm text-slate-500"><span class="font-semibold text-slate-700"><?= (int) $total ?></span> inquiry(ies)</p>
    </div>
</form>

<div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Inquiry</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">From</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Topic / Subject</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Status</th>
                <th class="px-4 py-3 text-left font-medium text-slate-500">Received</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($inquiries as $i): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3">
                        <a class="font-mono font-semibold text-blue-700 hover:text-blue-800" href="<?= e(url('/admin/contact-inquiries/' . $i['id'])) ?>"><?= e($i['inquiry_number']) ?></a>
                        <?php if ($i['order_reference'] !== null && $i['order_reference'] !== ''): ?>
                            <div class="text-xs text-slate-400">ref <?= e($i['order_reference']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900"><?= e($i['full_name']) ?></div>
                        <div class="text-xs text-slate-500"><?= e($i['email']) ?><?= $i['phone'] ? ' · ' . e($i['phone']) : '' ?></div>
                    </td>
                    <td class="px-4 py-3 text-slate-700">
                        <span class="text-xs uppercase tracking-wide text-slate-400"><?= e($i['topic']) ?></span>
                        <div><?= e($i['subject'] !== null && $i['subject'] !== '' ? $i['subject'] : mb_substr((string) $i['message'], 0, 60) . (mb_strlen((string) $i['message']) > 60 ? '…' : '')) ?></div>
                    </td>
                    <td class="px-4 py-3"><?= \App\Support\View::component('badge', ['status' => $i['status']]) ?></td>
                    <td class="px-4 py-3 text-xs text-slate-500"><?= e(local_datetime($i['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($inquiries === []): ?>
        <div class="px-4 py-10 text-center">
            <?= \App\Support\View::component('icon', ['name' => 'mail', 'class' => 'mx-auto h-8 w-8 text-slate-300']) ?>
            <p class="mt-2 text-sm font-medium text-slate-700">No inquiries found</p>
            <p class="mt-1 text-sm text-slate-500"><?= $filters['q'] !== '' || $filters['status'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' ? 'No inquiries match the current filters. Try resetting them.' : 'No contact form submissions yet. New submissions will appear here immediately.' ?></p>
        </div>
    <?php endif; ?>
</div>

<?= \App\Support\View::component('pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => '/admin/contact-inquiries', 'queryParams' => array_filter($filters)]) ?>
