<?php
/**
 * Inline SVG icon (Lucide-style, stroke based). Single source for the UI —
 * no emoji icons anywhere.
 */
$icons = [
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
    'chart' => '<path d="M3 3v18h18"/><path d="m7 15 4-6 4 4 5-8"/>',
    'tag' => '<path d="M12.6 2.5 21 11l-9 9-8.5-8.5V3.5h8.1Z"/><circle cx="8" cy="8" r="1.5"/>',
    'folder' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.2 3.9A2 2 0 0 0 7.5 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/>',
    'box' => '<path d="M21 8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
    'layers' => '<path d="m12 2 8.5 4.5L12 11 3.5 6.5 12 2Z"/><path d="m3.5 11.5 8.5 4.5 8.5-4.5M3.5 16.5 12 21l8.5-4.5"/>',
    'truck' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18h-5m9-4h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1m-5 0a2 2 0 1 0 4 0 2 2 0 0 0-4 0ZM7 18a2 2 0 1 0 4 0 2 2 0 0 0-4 0Z"/>',
    'check-circle' => '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/>',
    'shopping-cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57L22 6H5.12"/>',
    'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'credit-card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
    'wallet' => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.7 9a.6.6 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a2 2 0 0 1 2.6 0C15.5 3.8 18 5 20 5a1 1 0 0 1 1 1Z"/>',
    'map-pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    'heart' => '<path d="M19 14c1.5-1.5 3-3.3 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2C10.5 3.5 9.3 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/>',
    'bar-chart' => '<path d="M3 3v18h18"/><path d="M7 16v-5M12 16V8M17 16v-3"/>',
    'key' => '<path d="m21 2-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8Zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3"/>',
    'settings' => '<path d="M12.2 2h-.4a2 2 0 0 0-2 1.7l-.3 1.6a8 8 0 0 0-2.1 1.2l-1.6-.5a2 2 0 0 0-2.3 1l-.2.3a2 2 0 0 0 .5 2.5l1.2 1a8 8 0 0 0 0 2.5l-1.2 1a2 2 0 0 0-.5 2.4l.2.4a2 2 0 0 0 2.3 1l1.6-.5a8 8 0 0 0 2.1 1.2l.3 1.6a2 2 0 0 0 2 1.7h.4a2 2 0 0 0 2-1.7l.3-1.6a8 8 0 0 0 2.1-1.2l1.6.5a2 2 0 0 0 2.3-1l.2-.4a2 2 0 0 0-.5-2.4l-1.2-1a8 8 0 0 0 0-2.5l1.2-1a2 2 0 0 0 .5-2.4l-.2-.4a2 2 0 0 0-2.3-1l-1.6.5a8 8 0 0 0-2.1-1.2l-.3-1.6a2 2 0 0 0-2-1.7Z"/><circle cx="12" cy="12" r="3"/>',
    'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"/>',
    'plus' => '<path d="M5 12h14M12 5v14"/>',
    'edit' => '<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
    'trash' => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'alert' => '<path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z"/><path d="M12 9v4M12 17h.01"/>',
    'log-in' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
    'package-check' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
    'megaphone' => '<path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'ticket' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
    'tool' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/>',
    'book-open' => '<path d="M12 7v14"/><path d="M3 3v14h9V3H3Z"/><path d="M21 3v14h-9V3h9Z"/>',
    'cpu' => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>',
    'briefcase' => '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
];
$path = $icons[$name] ?? $icons['box'];
$class = $class ?? 'h-5 w-5';
?>
<svg class="<?= e($class) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $path ?></svg>
