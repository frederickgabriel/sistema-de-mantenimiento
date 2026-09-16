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

// Filtros sin recarga de página: reemplaza la navegación (location.href) de los
// selects/links de filtro por un fetch() a la misma URL, tomando solo el contenedor
// #ajaxFiltroZona de la respuesta y sustituyéndolo en la página actual. Si algo falla
// (sin conexión, contenedor no encontrado, etc.) cae de vuelta a la navegación normal.
(function () {
    const ZONA_ID = 'ajaxFiltroZona';

    function cargarZona(url, pushState) {
        const actual = document.getElementById(ZONA_ID);
        if (!actual) { location.href = url; return; }
        actual.style.opacity = '0.45';
        fetch(url)
            .then((r) => { if (!r.ok) throw new Error('http'); return r.text(); })
            .then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nueva = doc.getElementById(ZONA_ID);
                if (!nueva) throw new Error('sin zona');
                document.getElementById(ZONA_ID).replaceWith(nueva);
                if (doc.title) document.title = doc.title;
                if (pushState) history.pushState({ ajaxFiltro: true }, '', url);
            })
            .catch(() => { location.href = url; });
    }

    window.ajaxFiltro = function (url) {
        cargarZona(url, true);
        return false;
    };

    window.addEventListener('popstate', () => {
        if (document.getElementById(ZONA_ID)) cargarZona(location.href, false);
    });
})();
