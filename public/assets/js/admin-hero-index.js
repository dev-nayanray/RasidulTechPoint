/**
 * Admin hero slides list: drag-and-drop and up/down reordering, inline delete confirmation.
 *
 * Moved out of an inline <script> in admin/hero-slides/index.php. The site CSP is
 * "script-src 'self'", so inline scripts never run. Code is unchanged.
 */
// Reorder via drag-and-drop + up/down buttons. Posts CSRF form on drop/move.
(function () {
    var list = document.querySelector('[data-slide-list]');
    if (!list) return;
    var form = document.getElementById('hero-reorder-form');
    var idsInput = document.getElementById('hero-reorder-ids');
    var dragSrc = null;

    function postOrder() {
        var ids = [].slice.call(list.querySelectorAll('[data-slide-id]')).map(function (el) { return el.getAttribute('data-slide-id'); });
        idsInput.value = ids.join(',');
        fetch(form.action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ _token: form.querySelector('[name=_token]').value, ids: ids.join(',') }),
            credentials: 'same-origin'
        }).catch(function (e) { console.error('Reorder failed:', e); });
    }

    function moveUp(el) {
        var prev = el.previousElementSibling;
        if (prev) list.insertBefore(el, prev);
        postOrder();
    }
    function moveDown(el) {
        var next = el.nextElementSibling;
        if (next) list.insertBefore(next, el);
        postOrder();
    }

    list.querySelectorAll('[data-slide-id]').forEach(function (el) {
        el.addEventListener('dragstart', function (e) { dragSrc = el; el.classList.add('opacity-50'); e.dataTransfer.effectAllowed = 'move'; });
        el.addEventListener('dragend', function () { el.classList.remove('opacity-50'); });
        el.addEventListener('dragover', function (e) { e.preventDefault(); });
        el.addEventListener('drop', function (e) {
            e.preventDefault();
            if (dragSrc && dragSrc !== el) {
                var rect = el.getBoundingClientRect();
                if (e.clientY < rect.top + rect.height / 2) list.insertBefore(dragSrc, el);
                else list.insertBefore(dragSrc, el.nextSibling);
                postOrder();
            }
        });
        el.querySelector('[data-move-up]').addEventListener('click', function () { moveUp(el); });
        el.querySelector('[data-move-down]').addEventListener('click', function () { moveDown(el); });
    });

    // Inline delete confirmation (no browser alert).
    list.querySelectorAll('[data-confirm-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var f = btn.closest('form');
            if (btn.dataset.confirming === '1') { f.submit(); return; }
            btn.dataset.confirming = '1';
            btn.dataset.originalText = btn.textContent;
            btn.textContent = 'Sure?';
            btn.classList.add('bg-red-700');
            setTimeout(function () {
                btn.dataset.confirming = '0';
                btn.textContent = btn.dataset.originalText;
                btn.classList.remove('bg-red-700');
            }, 3000);
        });
    });
})();
