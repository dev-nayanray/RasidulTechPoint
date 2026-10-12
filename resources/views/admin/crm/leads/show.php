<?php
/** @var array $lead
 *  @var list<string> $statuses
 *  @var list<string> $followupMethods
 *  @var list<string> $followupOutcomes
 *  @var list<string> $commChannels
 */
$whatsappLink = $lead['whatsapp_link'] ?? '';
?>
<p class="text-sm text-slate-500"><a href="<?= e(url('/admin/crm/leads')) ?>" class="font-medium text-blue-700 hover:text-blue-800">← Leads</a></p>
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900"><?= e($lead['name']) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <?= \App\Support\View::component('badge', ['status' => $lead['status']]) ?>
            · <?= e($lead['lead_number']) ?> · <?= e($lead['phone']) ?> · <?= e(ucfirst(str_replace('_', ' ', $lead['source']))) ?>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if (!empty($whatsappLink)): ?>
        <a href="<?= e($whatsappLink) ?>" target="_blank" rel="noopener" class="btn-primary">WhatsApp ↗</a>
        <?php endif; ?>
    </div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <!-- Lead details -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Details</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                <div><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900"><?= e($lead['phone']) ?></dd></div>
                <div><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900"><?= e($lead['email'] ?? '—') ?></dd></div>
                <div><dt class="text-slate-500">Budget</dt><dd class="font-medium text-slate-900"><?= $lead['budget'] ? money($lead['budget']) : '—' ?></dd></div>
                <div><dt class="text-slate-500">Assigned to</dt><dd class="font-medium text-slate-900"><?= e($lead['assigned_to_name'] ?? '—') ?></dd></div>
                <?php if (!empty($lead['interest'])): ?><div class="sm:col-span-2"><dt class="text-slate-500">Interest</dt><dd class="text-slate-700"><?= e($lead['interest']) ?></dd></div><?php endif; ?>
                <?php if (!empty($lead['lost_reason'])): ?><div class="sm:col-span-2"><dt class="text-slate-500">Lost reason</dt><dd class="text-red-700"><?= e($lead['lost_reason']) ?></dd></div><?php endif; ?>
            </dl>
        </div>

        <!-- Status update -->
        <?php if (!in_array($lead['status'], ['won', 'lost'], true)): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Update status</h2>
            <form method="post" action="<?= e(url('/admin/crm/leads/' . $lead['id'] . '/status')) ?>" class="mt-3 flex flex-wrap items-end gap-2" data-loading-form>
                <?= csrf_field() ?>
                <select name="status" class="input w-40">
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $lead['status'] ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="lost_reason" class="input flex-1" placeholder="Lost reason (if lost)">
                <button type="submit" class="btn-secondary">Update</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Schedule follow-up -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Schedule follow-up</h2>
            <form method="post" action="<?= e(url('/admin/crm/leads/' . $lead['id'] . '/followup')) ?>" class="mt-3 grid gap-2 sm:grid-cols-3" data-loading-form>
                <?= csrf_field() ?>
                <input type="datetime-local" name="scheduled_at" required class="input">
                <select name="method" class="input">
                    <?php foreach ($followupMethods as $m): ?>
                        <option value="<?= e($m) ?>"><?= e(ucfirst(str_replace('_', ' ', $m))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-secondary">Schedule</button>
                <textarea name="notes" rows="1" class="input sm:col-span-3" placeholder="Notes (optional)"></textarea>
            </form>
        </div>

        <!-- Follow-ups -->
        <?php if (!empty($lead['followups'])): ?>
        <div id="followups" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Follow-ups (<?= count($lead['followups']) ?>)</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($lead['followups'] as $f): ?>
                <li class="px-5 py-3 flex items-center justify-between">
                    <div>
                        <p class="font-medium text-slate-900"><?= e(ucfirst(str_replace('_', ' ', $f['method']))) ?> · <?= e(ucfirst($f['outcome'])) ?></p>
                        <p class="text-xs text-slate-500">Scheduled: <?= e(local_datetime($f['scheduled_at'])) ?><?php if ($f['completed_at']): ?> · Completed: <?= e(local_datetime($f['completed_at'])) ?><?php endif; ?></p>
                    </div>
                    <?php if ($f['outcome'] === 'pending'): ?>
                    <form method="post" action="<?= e(url('/admin/crm/followups/' . $f['id'] . '/complete')) ?>" class="flex gap-1" data-loading-form>
                        <?= csrf_field() ?>
                        <select name="outcome" class="input !py-1 !text-xs w-28">
                            <?php foreach ($followupOutcomes as $o): if ($o === 'pending') continue; ?>
                                <option value="<?= e($o) ?>"><?= e(ucfirst(str_replace('_', ' ', $o))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-secondary text-xs">Done</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Log communication -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Log communication</h2>
            <p class="mt-1 text-xs text-slate-500">Record what happened after the fact. The system does NOT send automated messages.</p>
            <form method="post" action="<?= e(url('/admin/crm/leads/' . $lead['id'] . '/communication')) ?>" class="mt-3 space-y-2" data-loading-form>
                <?= csrf_field() ?>
                <div class="flex gap-2">
                    <select name="channel" class="input w-32">
                        <?php foreach ($commChannels as $ch): ?>
                            <option value="<?= e($ch) ?>"><?= e(ucfirst(str_replace('_', ' ', $ch))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="direction" class="input w-32">
                        <option value="outbound">Outbound</option>
                        <option value="inbound">Inbound</option>
                    </select>
                    <input name="subject" class="input flex-1" placeholder="Subject (optional)">
                </div>
                <textarea name="body" rows="2" class="input" placeholder="What was discussed…"></textarea>
                <button type="submit" class="btn-secondary text-xs">Log communication</button>
            </form>
        </div>

        <!-- Communications -->
        <?php if (!empty($lead['communications'])): ?>
        <div id="communications" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">Communication history (<?= count($lead['communications']) ?>)</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($lead['communications'] as $c): ?>
                <li class="px-5 py-3">
                    <div class="flex items-baseline justify-between text-xs">
                        <span class="font-medium text-slate-700"><?= e(ucfirst(str_replace('_', ' ', $c['channel']))) ?> · <?= e(ucfirst($c['direction'])) ?></span>
                        <span class="text-slate-400"><?= e(local_datetime($c['occurred_at'])) ?></span>
                    </div>
                    <?php if ($c['subject']): ?><p class="mt-1 font-medium text-slate-900 text-xs"><?= e($c['subject']) ?></p><?php endif; ?>
                    <?php if ($c['body']): ?><p class="mt-0.5 text-slate-600 text-xs"><?= e($c['body']) ?></p><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Quick actions</h2>
            <div class="mt-3 space-y-2">
                <?php if (!empty($whatsappLink)): ?>
                <a href="<?= e($whatsappLink) ?>" target="_blank" rel="noopener" class="btn-primary w-full text-xs">WhatsApp click-to-chat ↗</a>
                <?php endif; ?>
                <a href="tel:<?= e($lead['phone']) ?>" class="btn-secondary w-full text-xs">Call <?= e($lead['phone']) ?></a>
            </div>
        </div>
        <?php if (!empty($lead['quotations'])): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 class="font-semibold text-slate-900">Quotations (<?= count($lead['quotations']) ?>)</h2>
            <ul class="mt-3 space-y-2 text-xs">
                <?php foreach ($lead['quotations'] as $q): ?>
                <li><a class="font-mono text-blue-700 hover:underline" href="<?= e(url('/admin/crm/quotations/' . $q['id'])) ?>"><?= e($q['quote_number']) ?></a> · <?= e(ucfirst($q['status'])) ?> · <?= money($q['total']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </aside>
</div>
