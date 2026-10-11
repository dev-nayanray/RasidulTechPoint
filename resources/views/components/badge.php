<?php
/** Status badge with domain-aware color mapping. */
$label = $label ?? $status ?? '';
$map = [
    // inventory
    'in_transit' => 'bg-blue-50 text-blue-700 border-blue-200',
    'awaiting_inspection' => 'bg-amber-50 text-amber-700 border-amber-200',
    'available' => 'bg-green-50 text-green-700 border-green-200',
    'reserved' => 'bg-purple-50 text-purple-700 border-purple-200',
    'sold' => 'bg-slate-100 text-slate-700 border-slate-200',
    'returned' => 'bg-orange-50 text-orange-700 border-orange-200',
    'damaged' => 'bg-red-50 text-red-700 border-red-200',
    'written_off' => 'bg-slate-100 text-slate-500 border-slate-200',
    // orders
    'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
    'confirmed' => 'bg-blue-50 text-blue-700 border-blue-200',
    'processing' => 'bg-blue-50 text-blue-700 border-blue-200',
    'completed' => 'bg-green-50 text-green-700 border-green-200',
    'cancelled' => 'bg-slate-100 text-slate-600 border-slate-200',
    'refunded' => 'bg-orange-50 text-orange-700 border-orange-200',
    // payments
    'unpaid' => 'bg-red-50 text-red-700 border-red-200',
    'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
    'paid' => 'bg-green-50 text-green-700 border-green-200',
    'failed' => 'bg-red-50 text-red-700 border-red-200',
    // users
    'active' => 'bg-green-50 text-green-700 border-green-200',
    'inactive' => 'bg-slate-100 text-slate-500 border-slate-200',
    // grades
    'A' => 'bg-green-50 text-green-700 border-green-200',
    'B' => 'bg-blue-50 text-blue-700 border-blue-200',
    'C' => 'bg-amber-50 text-amber-700 border-amber-200',
    'D' => 'bg-red-50 text-red-700 border-red-200',
    'online' => 'bg-blue-50 text-blue-700 border-blue-200',
    'pos' => 'bg-purple-50 text-purple-700 border-purple-200',
];
$classes = $map[$label] ?? 'bg-slate-100 text-slate-700 border-slate-200';
$text = str_replace('_', ' ', $label);
?>
<span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize <?= $classes ?>"><?= e($text) ?></span>
