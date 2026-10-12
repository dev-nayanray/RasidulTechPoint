<?php /** @var array $inquiry
 *  @var list<string> $statuses
 *  @var bool $canManage
 */
$topicLabel = \App\Services\ContactService::TOPICS[$inquiry['topic']] ?? ucfirst($inquiry['topic']);
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="font-mono text-xl font-bold tracking-tight text-slate-900"><?= e($inquiry['inquiry_number']) ?></h1>
            <?= \App\Support\View::component('badge', ['status' => $inquiry['status']]) ?>
        </div>
        <p class="mt-1 text-sm text-slate-500">Received <?= e(local_datetime($inquiry['created_at'])) ?></p>
    </div>
    <a href="<?= e(url('/admin/contact-inquiries')) ?>" class="btn-secondary">← Back to inquiries</a>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Message</h2>
            <p class="mt-1 text-sm font-medium text-slate-900"><?= e($inquiry['subject'] !== null && $inquiry['subject'] !== '' ? $inquiry['subject'] : $topicLabel) ?></p>
            <div class="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-slate-700"><?= e($inquiry['message']) ?></div>
        </div>

        <?php if ($canManage): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Internal note</h2>
                <p class="mt-1 text-xs text-slate-400">Only staff with inquiry management permission can see this note. It is never shown to the customer.</p>
                <form method="post" action="<?= e(url('/admin/contact-inquiries/' . $inquiry['id'] . '/note')) ?>" class="mt-3 space-y-3" data-loading-form>
                    <?= csrf_field() ?>
                    <label for="admin_note" class="sr-only">Internal note</label>
                    <textarea id="admin_note" name="admin_note" rows="4" class="input" placeholder="Triage notes, follow-up reminders, reply summary…"><?= e($inquiry['admin_note'] ?? '') ?></textarea>
                    <button type="submit" class="btn-primary">Save note</button>
                </form>
            </div>
        <?php elseif ($inquiry['admin_note'] !== null && $inquiry['admin_note'] !== ''): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Internal note</h2>
                <div class="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-slate-700"><?= e($inquiry['admin_note']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">From</h2>
            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Name</dt>
                    <dd class="mt-0.5 font-medium text-slate-900"><?= e($inquiry['full_name']) ?></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Email</dt>
                    <dd class="mt-0.5"><a class="font-medium text-blue-700 hover:text-blue-800" href="mailto:<?= e($inquiry['email']) ?>"><?= e($inquiry['email']) ?></a></dd>
                </div>
                <?php if ($inquiry['phone'] !== null && $inquiry['phone'] !== ''): ?>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Phone</dt>
                        <dd class="mt-0.5 font-medium text-slate-900"><?= e($inquiry['phone']) ?></dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Topic</dt>
                    <dd class="mt-0.5 font-medium text-slate-900"><?= e($topicLabel) ?></dd>
                </div>
                <?php if ($inquiry['order_reference'] !== null && $inquiry['order_reference'] !== ''): ?>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Order reference</dt>
                        <dd class="mt-0.5 font-mono text-sm font-medium text-slate-900"><?= e($inquiry['order_reference']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Metadata</h2>
            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Submitted</dt>
                    <dd class="mt-0.5 text-slate-700"><?= e(local_datetime($inquiry['created_at'])) ?></dd>
                </div>
                <?php if ($inquiry['responded_at'] !== null): ?>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Responded</dt>
                        <dd class="mt-0.5 text-slate-700"><?= e(local_datetime($inquiry['responded_at'])) ?><?= $inquiry['responded_by_name'] ? ' by ' . e($inquiry['responded_by_name']) : '' ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($inquiry['ip_address'] !== null && $inquiry['ip_address'] !== ''): ?>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">IP address</dt>
                        <dd class="mt-0.5 font-mono text-xs text-slate-500"><?= e($inquiry['ip_address']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($canManage): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Status</h2>
                <form method="post" action="<?= e(url('/admin/contact-inquiries/' . $inquiry['id'] . '/status')) ?>" class="mt-3 space-y-3" data-loading-form>
                    <?= csrf_field() ?>
                    <label for="status" class="sr-only">Status</label>
                    <select id="status" name="status" class="input">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= e($s) ?>" <?= $s === $inquiry['status'] ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary w-full">Update status</button>
                </form>
            </div>

            <div class="rounded-xl border border-red-200 bg-red-50 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-red-500">Danger zone</h2>
                <p class="mt-1 text-xs text-red-600">Deleting permanently removes the inquiry and cannot be undone. Type the inquiry number to confirm.</p>
                <form method="post" action="<?= e(url('/admin/contact-inquiries/' . $inquiry['id'] . '/delete')) ?>" class="mt-3 space-y-3" data-loading-form onsubmit="return this.confirm.value === '<?= e($inquiry['inquiry_number']) ?>' ? confirm('Delete this inquiry permanently?') : (alert('Inquiry number does not match.'), false)">
                    <?= csrf_field() ?>
                    <label for="confirm" class="sr-only">Inquiry number</label>
                    <input id="confirm" name="confirm" class="input" placeholder="<?= e($inquiry['inquiry_number']) ?>" autocomplete="off">
                    <button type="submit" class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete inquiry</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
