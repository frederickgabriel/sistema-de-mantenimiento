// Modal de confirmación reutilizable — reemplaza confirm() nativo del navegador.
// Uso: onsubmit="return zConfirm(this,'¿Eliminar?','danger')"
(function () {
    const TONE_ICON = { danger: 'warning', default: 'help' };

    let pendingConfirm = null;

    function elements() {
        return {
            overlay: document.getElementById('zcOverlay'),
            icon:    document.getElementById('zcIcon'),
            msg:     document.getElementById('zcMsg'),
            ok:      document.getElementById('zcOk'),
            cancel:  document.getElementById('zcCancel'),
        };
    }

    function showZConfirm(message, tone, onConfirm) {
        const el = elements();
        if (!el.overlay) { onConfirm(); return; }

        tone = tone === 'danger' ? 'danger' : 'default';
        el.icon.className = 'zc-icon tone-' + tone;
        el.icon.querySelector('.material-symbols-outlined').textContent = TONE_ICON[tone];
        el.msg.textContent = message;
        el.ok.className = 'btn ' + (tone === 'danger' ? 'btn-danger' : 'btn-primary');

        pendingConfirm = onConfirm;
        el.overlay.classList.add('open');
    }

    function closeZConfirm() {
        const el = elements();
        el.overlay?.classList.remove('open');
        pendingConfirm = null;
    }

    window.zConfirm = function (form, message, tone) {
        if (form.dataset.zOk === '1') return true;
        showZConfirm(message, tone, () => {
            form.dataset.zOk = '1';
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
        return false;
    };

    document.addEventListener('DOMContentLoaded', () => {
        const el = elements();
        if (!el.overlay) return;

        el.ok.addEventListener('click', () => {
            const fn = pendingConfirm;
            closeZConfirm();
            if (fn) fn();
        });
        el.cancel.addEventListener('click', closeZConfirm);
        el.overlay.addEventListener('click', (e) => { if (e.target === el.overlay) closeZConfirm(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeZConfirm(); });
    });
})();
