<?php /** @var int $newLeads $openInquiries $pendingFollowups @var list<array> $upcoming $recentLeads */ ?>
<div class="grid gap-4 sm:grid-cols-3">
    <?= \App\Support\View::component('stat-card', ['label' => 'New leads', 'value' => $newLeads, 'icon' => 'user-plus']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Open inquiries', 'value' => $openInquiries, 'icon' => 'inbox']) ?>
    <?= \App\Support\View::component('stat-card', ['label' => 'Overdue follow-ups', 'value' => $pendingFollowups, 'icon' => 'clock']) ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Upcoming follow-ups</h2>
            <a href="<?= e(url('/admin/crm/leads')) ?>" class="text-xs font-medium text-blue-700 hover:underline">All leads →</a>
        </header>
        <?php if ($upcoming === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No upcoming follow-ups.</p>
        <?php else: ?>
        <ul class="divide-y divide-slate-100 text-sm">
            <?php foreach ($upcoming as $f): ?>
            <li class="px-4 py-3 flex items-center justify-between">
                <div>
                    <p class="font-medium text-slate-900"><?= e($f['lead_name'] ?? $f['customer_name'] ?? 'Unknown') ?></p>
                    <p class="text-xs text-slate-500"><?= e(ucfirst(str_replace('_', ' ', $f['method']))) ?> · <?= e(local_datetime($f['scheduled_at'], 'd M, g:i a')) ?></p>
                </div>
                <?php if (!empty($f['whatsapp_link'])): ?>
                <a href="<?= e($f['whatsapp_link']) ?>" target="_blank" rel="noopener" class="btn-secondary text-xs">WhatsApp ↗</a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent leads</h2>
            <a href="<?= e(url('/admin/crm/leads/create')) ?>" class="btn-primary text-xs">New lead</a>
        </header>
        <?php if ($recentLeads === []): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-400">No leads yet.</p>
        <?php else: ?>
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <tbody>
                <?php foreach ($recentLeads as $l): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2"><a class="font-medium text-blue-700" href="<?= e(url('/admin/crm/leads/' . $l['id'])) ?>"><?= e($l['name']) ?></a></td>
                    <td class="px-4 py-2 text-slate-500 text-xs"><?= e($l['phone']) ?></td>
                    <td class="px-4 py-2"><?= \App\Support\View::component('badge', ['status' => $l['status']]) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
    <p class="font-semibold">WhatsApp click-to-chat</p>
    <p class="mt-1">WhatsApp links on this page open the WhatsApp web/app with a pre-filled message. No automated messages are sent — you must review and manually send each message. Automated messaging requires a properly configured integration and appropriate customer consent.</p>
</div>
