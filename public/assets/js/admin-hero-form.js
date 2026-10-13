/**
 * Admin hero slide editor: dirty tracking, theme preset toggle, color sync, contrast warnings, preview width toggle.
 *
 * Moved out of an inline <script> in admin/hero-slides/form.php. The site CSP is
 * "script-src 'self'", so inline scripts never run. Code is unchanged.
 */
(function () {
    var form = document.getElementById('hero-slide-form');
    if (!form) return;
    var saveBtn = document.getElementById('save-btn');
    var unsavedWarn = document.getElementById('unsaved-warn');
    var dirty = false;

    // Track dirty state
    form.addEventListener('input', function () { dirty = true; if (unsavedWarn) unsavedWarn.hidden = false; });
    form.addEventListener('submit', function () {
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
    });
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // Theme preset → toggle custom color block
    var customBlock = document.getElementById('custom-theme-block');
    form.querySelectorAll('[data-theme-preset]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (r.value === 'custom') customBlock.classList.remove('hidden');
            else customBlock.classList.add('hidden');
            runContrastChecks();
        });
    });

    // Color picker ↔ text input sync
    form.querySelectorAll('[data-color-picker]').forEach(function (picker) {
        var field = picker.getAttribute('data-color-picker');
        var input = form.querySelector('[data-color-input="' + field + '"]');
        picker.addEventListener('input', function () { input.value = picker.value; runContrastChecks(); });
        input.addEventListener('input',  function () {
            if (/^#[0-9a-fA-F]{6}$/.test(input.value)) picker.value = input.value;
            runContrastChecks();
        });
    });

    // Visual type → toggle image upload
    var visualSelect = form.querySelector('[name=visual_type]');
    var imageBlock = document.getElementById('image-upload-block');
    visualSelect.addEventListener('change', function () {
        if (visualSelect.value === 'image') imageBlock.classList.remove('hidden');
        else imageBlock.classList.add('hidden');
    });

    // WCAG contrast warnings for custom theme
    function luminance(hex) {
        hex = (hex || '').replace('#','');
        if (hex.length === 3) hex = hex.split('').map(function(c){return c+c;}).join('');
        var r = parseInt(hex.substr(0,2),16)/255, g = parseInt(hex.substr(2,2),16)/255, b = parseInt(hex.substr(4,2),16)/255;
        var f = function(c){ return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
        return 0.2126*f(r) + 0.7152*f(g) + 0.0722*f(b);
    }
    function ratio(a, b) {
        var la = luminance(a), lb = luminance(b);
        var hi = Math.max(la, lb), lo = Math.min(la, lb);
        return (hi + 0.05) / (lo + 0.05);
    }
    function val(name) { var el = form.querySelector('[data-color-input="' + name + '"]'); return el ? el.value : ''; }
    function runContrastChecks() {
        var warn = document.getElementById('contrast-warnings');
        if (!warn) return;
        warn.innerHTML = '';
        if (form.querySelector('[data-theme-preset]:checked')?.value !== 'custom') return;
        var checks = [
            ['Ink on background', val('custom_ink'), val('custom_bg')],
            ['Muted on background', val('custom_muted'), val('custom_bg')],
            ['Accent text on accent', val('custom_accent_ink'), val('custom_accent')]
        ];
        checks.forEach(function (c) {
            var r = ratio(c[1], c[2]);
            if (r < 4.5) {
                var div = document.createElement('div');
                div.textContent = '⚠ ' + c[0] + ': ' + r.toFixed(2) + ' (below 4.5:1)';
                warn.appendChild(div);
            }
        });
    }
    runContrastChecks();

    // Preview width toggle (desktop / phone)
    var iframe = document.getElementById('hero-preview-iframe');
    document.querySelectorAll('[data-w]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.dataset.w === 'phone') {
                iframe.style.maxWidth = '390px';
                iframe.style.height = '640px';
            } else {
                iframe.style.maxWidth = '100%';
                iframe.style.height = '480px';
            }
            document.querySelectorAll('[data-w]').forEach(function (b) { b.className = 'px-2 py-1 text-xs rounded bg-slate-800 text-slate-400'; });
            btn.className = 'px-2 py-1 text-xs rounded bg-slate-700 text-white';
        });
    });

    // Live-update preview as user types (debounced).
    // Only triggers a reload of the iframe; the iframe itself re-pulls from the
    // latest saved slide on each reload. Edits not yet saved won't reflect,
    // that's why the preview is best-effort rather than truly live.
    var timer = null;
    form.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            // Mark dirty + show "Save to update preview"
            dirty = true;
            if (unsavedWarn) { unsavedWarn.hidden = false; unsavedWarn.textContent = 'Save to update preview.'; }
        }, 250);
    });
})();
