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
//
// El mismo cargarZona() se reutiliza más abajo para refrescar la zona después de
// guardar/eliminar algo (ver bloque "Guardar/eliminar sin recargar la página").
const cargarZona = (function () {
    const ZONA_ID = 'ajaxFiltroZona';

    function cargarZona(url, pushState) {
        const actual = document.getElementById(ZONA_ID);
        if (!actual) { location.href = url; return Promise.resolve(false); }
        actual.style.opacity = '0.45';
        return fetch(url)
            .then((r) => { if (!r.ok) throw new Error('http'); return r.text(); })
            .then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nueva = doc.getElementById(ZONA_ID);
                if (!nueva) throw new Error('sin zona');
                document.getElementById(ZONA_ID).replaceWith(nueva);
                if (doc.title) document.title = doc.title;
                if (pushState) history.pushState({ ajaxFiltro: true }, '', url);
                return true;
            })
            .catch(() => { location.href = url; return false; });
    }

    window.ajaxFiltro = function (url) {
        cargarZona(url, true);
        return false;
    };

    window.addEventListener('popstate', () => {
        if (document.getElementById(ZONA_ID)) cargarZona(location.href, false);
    });

    return cargarZona;
})();

// Guardar/eliminar sin recargar la página: intercepta el submit de cualquier
// formulario POST del sistema, lo manda por fetch() con FormData (funciona igual
// para formularios con fotos, multipart/form-data incluido) y, si el servidor
// responde JSON (ver isAjax()/respond() en includes/config.php), refresca la
// #ajaxFiltroZona actual y muestra el mensaje en un toast en vez de recargar.
//
// Convive con zConfirm sin tocarlo: zConfirm bloquea el primer submit (dispara
// preventDefault) y, al confirmar, llama form.requestSubmit(), que emite un
// SEGUNDO evento submit ya no bloqueado — ese es el que este listener procesa.
(function () {
    function mostrarToast(msg, ok) {
        if (!msg) return;
        let zona = document.getElementById('ajaxToastZona');
        if (!zona) {
            zona = document.createElement('div');
            zona.id = 'ajaxToastZona';
            zona.style.cssText = 'position:fixed;top:16px;right:16px;left:16px;z-index:2000;display:flex;flex-direction:column;gap:8px;align-items:flex-end;pointer-events:none';
            document.body.appendChild(zona);
        }
        const cls = msg.startsWith('✅') ? 'alert-success' : (msg.startsWith('🗑') ? 'alert-info' : (ok ? 'alert-success' : 'alert-error'));
        const el = document.createElement('div');
        el.className = 'alert ' + cls;
        el.style.cssText = 'max-width:420px;box-shadow:0 10px 24px rgba(20,20,40,.15);pointer-events:auto;margin:0';
        el.textContent = msg;
        zona.appendChild(el);
        setTimeout(() => el.remove(), 4500);
    }

    function cerrarModales() {
        document.querySelectorAll('.modal-overlay.open').forEach((m) => m.classList.remove('open'));
    }

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (e.defaultPrevented) return; // zConfirm (u otro) ya bloqueó este intento
        if (form.dataset.ajax === 'off') return;
        if ((form.method || 'get').toLowerCase() !== 'post') return;

        e.preventDefault();

        const removeSelector = form.dataset.ajaxRemove || null;
        const fd = new FormData(form);
        const url = form.getAttribute('action') || location.href;

        fetch(url, { method: 'POST', body: fd, headers: { 'X-Ajax-Request': '1' } })
            .then((r) => { if (!r.ok) throw new Error('http'); return r.json(); })
            .then((data) => {
                if (removeSelector) {
                    const nodo = form.closest(removeSelector);
                    if (nodo) nodo.remove();
                    cargarZona(location.href, false); // sincroniza contadores en segundo plano
                } else {
                    cerrarModales();
                    form.reset();
                    const destino = data.qs ? (location.pathname + '?' + data.qs) : location.href;
                    cargarZona(destino, false);
                }
                mostrarToast(data.msg, data.ok);
            })
            .catch(() => { form.submit(); });
    });
})();
