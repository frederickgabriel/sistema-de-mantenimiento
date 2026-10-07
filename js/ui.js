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

    // ---- Animación del refresco en tiempo real: la zona aparece suave, lo nuevo o modificado
    // destella un instante y una burbuja "Actualizado" sube desde abajo. ----
    const ITEMS = 'tbody tr, [class*="card"]:not(:has([class*="card"]))';
    function textosDeItems(zona) {
        const set = new Map();
        try {
            zona.querySelectorAll(ITEMS).forEach((el) => {
                const t = el.textContent.replace(/\s+/g, ' ').trim();
                if (t) set.set(t, (set.get(t) || 0) + 1);
            });
        } catch (e) { return null; }
        return set;
    }
    function animarActualizacion(zona, previos) {
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        zona.classList.add('z-refresco');
        if (previos) {
            const vistos = new Map();
            try {
                zona.querySelectorAll(ITEMS).forEach((el) => {
                    const t = el.textContent.replace(/\s+/g, ' ').trim();
                    if (!t) return;
                    vistos.set(t, (vistos.get(t) || 0) + 1);
                    if (vistos.get(t) > (previos.get(t) || 0)) el.classList.add('z-nuevo');
                });
            } catch (e) { /* sin :has() simplemente no se resalta */ }
        }
        setTimeout(() => zona.classList.remove('z-refresco'), 600);
        window.zBurbujaActualizado();
    }

    function cargarZona(url, pushState, silencioso) {
        const actual = document.getElementById(ZONA_ID);
        if (!actual) { if (!silencioso) location.href = url; return Promise.resolve(false); }
        if (!silencioso) actual.style.opacity = '0.45';
        return fetch(url)
            .then((r) => { if (!r.ok) throw new Error('http'); return r.text(); })
            .then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nueva = doc.getElementById(ZONA_ID);
                if (!nueva) throw new Error('sin zona');
                const previos = silencioso ? textosDeItems(document.getElementById(ZONA_ID)) : null;
                document.getElementById(ZONA_ID).replaceWith(nueva);
                if (silencioso) animarActualizacion(nueva, previos);
                if (doc.title) document.title = doc.title;
                if (pushState) history.pushState({ ajaxFiltro: true }, '', url);
                return true;
            })
            .catch(() => {
                if (silencioso) { actual.style.opacity = ''; return false; } // refresco en segundo plano: reintenta en el próximo ciclo
                location.href = url;
                return false;
            });
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
// Aviso flotante (toast), arriba al centro de la pantalla. Sin emojis: ícono vectorial en círculo,
// botón de cerrar y barra de tiempo (se pausa al pasar el mouse).
// Uso: zToast('Texto', 'success' | 'error' | 'info' | 'warning'). Si no se indica el tipo,
// se deduce del emoji inicial (✅ ❌ 🗑 ⚠) que traen los mensajes del servidor, y el emoji se quita.
(function () {
    const TIPOS = {
        success: { cls: 'z-ok',   icon: 'check_circle', titulo: 'Listo' },
        error:   { cls: 'z-err',  icon: 'error',        titulo: 'Error' },
        info:    { cls: 'z-info', icon: 'info',         titulo: 'Información' },
        warning: { cls: 'z-warn', icon: 'warning',      titulo: 'Atención' },
    };
    const EMOJIS = { '✅': 'success', '❌': 'error', '🚫': 'error', '🗑': 'info', '⚠': 'warning' };
    const DURACION = 4500;

    window.zToast = function (msg, tipo) {
        if (!msg) return;
        for (const emoji in EMOJIS) {
            if (msg.startsWith(emoji)) {
                tipo = tipo || EMOJIS[emoji];
                msg = msg.slice(emoji.length);
                break;
            }
        }
        msg = msg.replace(/^[\s\uFE0F]+/, '').replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\uFE0F]/gu, '');
        const t = TIPOS[tipo] || TIPOS.info;

        let zona = document.getElementById('ajaxToastZona');
        if (!zona) {
            zona = document.createElement('div');
            zona.id = 'ajaxToastZona';
            zona.className = 'z-toast-zona';
            zona.setAttribute('aria-live', 'polite');
            document.body.appendChild(zona);
        }
        const el = document.createElement('div');
        el.className = 'z-toast ' + t.cls;
        el.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
        el.style.setProperty('--z-dur', DURACION + 'ms');
        el.innerHTML =
            '<span class="z-toast-ico"><span class="material-symbols-outlined"></span></span>' +
            '<span class="z-toast-cuerpo"><strong class="z-toast-titulo"></strong><span class="z-toast-msg"></span></span>' +
            '<button type="button" class="z-toast-x" aria-label="Cerrar aviso"><span class="material-symbols-outlined">close</span></button>' +
            '<span class="z-toast-barra"></span>';
        el.querySelector('.z-toast-ico .material-symbols-outlined').textContent = t.icon;
        el.querySelector('.z-toast-titulo').textContent = t.titulo;
        el.querySelector('.z-toast-msg').textContent = msg;
        zona.appendChild(el);

        let restante = DURACION, inicio = Date.now(), timer;
        const cerrar = () => {
            clearTimeout(timer);
            if (el.classList.contains('saliendo')) return;
            el.classList.add('saliendo');
            el.addEventListener('animationend', () => el.remove(), { once: true });
            setTimeout(() => el.remove(), 400); // por si no hay animación (reduced-motion)
        };
        const arrancar = () => { inicio = Date.now(); timer = setTimeout(cerrar, restante); };
        el.addEventListener('mouseenter', () => { clearTimeout(timer); restante -= Date.now() - inicio; el.classList.add('pausado'); });
        el.addEventListener('mouseleave', () => { el.classList.remove('pausado'); arrancar(); });
        el.querySelector('.z-toast-x').addEventListener('click', cerrar);
        arrancar();
    };
})();

(function () {
    function mostrarToast(msg, ok) {
        if (!msg) return;
        const tieneEmoji = /^(✅|❌|🚫|🗑|⚠)/.test(msg);
        window.zToast(msg, tieneEmoji ? undefined : (ok ? 'success' : 'error'));
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

// Vista previa de documentos imprimibles (formatos, reportes, dictámenes) dentro de la
// página, en un modal con iframe — sin abrir ventanas nuevas. El modal se crea la primera vez.
// Uso: <a href="/pages/doc.php" data-vista="Título" data-excel="/pages/doc_excel.php">
//      o zVista('/pages/doc.php', 'Título', '/pages/doc_excel.php')  (excel es opcional)
// Si el documento redirige (p.ej. no se pudo generar), se cierra y se muestra su aviso en un toast.
(function () {
    let modal, frame, cuerpo, titulo, btnExcel, btnImprimir;

    function crear() {
        modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.id = 'zVista';
        modal.innerHTML =
            '<div class="modal-box z-vista-box" role="dialog" aria-modal="true" aria-labelledby="zVistaTitulo">' +
                '<div class="modal-header z-vista-header">' +
                    '<div class="modal-title"><span class="material-symbols-outlined mi-md">print</span> <span id="zVistaTitulo">Vista previa</span></div>' +
                    '<div class="z-vista-acciones">' +
                        '<a href="#" class="btn btn-success btn-sm" id="zVistaExcel" data-descarga><span class="material-symbols-outlined mi-sm">download</span><span class="z-vista-txt"> Descargar Excel</span></a>' +
                        '<button type="button" class="btn btn-primary btn-sm" id="zVistaImprimir"><span class="material-symbols-outlined mi-sm">print</span><span class="z-vista-txt"> Imprimir / PDF</span></button>' +
                        '<button type="button" class="modal-close" id="zVistaCerrar" aria-label="Cerrar vista previa"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">close</span></button>' +
                    '</div>' +
                '</div>' +
                '<div class="z-vista-cuerpo">' +
                    '<div class="z-vista-cargando"><div class="z-vista-spinner"></div>Cargando documento…</div>' +
                    '<iframe title="Vista previa del documento"></iframe>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);

        frame       = modal.querySelector('iframe');
        cuerpo      = modal.querySelector('.z-vista-cuerpo');
        titulo      = modal.querySelector('#zVistaTitulo');
        btnExcel    = modal.querySelector('#zVistaExcel');
        btnImprimir = modal.querySelector('#zVistaImprimir');

        modal.querySelector('#zVistaCerrar').addEventListener('click', cerrar);
        modal.addEventListener('click', (e) => { if (e.target === modal) cerrar(); });
        btnImprimir.addEventListener('click', () => { frame.contentWindow.focus(); frame.contentWindow.print(); });
        frame.addEventListener('load', alCargar);
    }

    function alCargar() {
        if (!frame.dataset.url) return; // about:blank al cerrar
        let doc = null;
        try { doc = frame.contentDocument; } catch (err) { /* otro origen */ }

        const esperado = new URL(frame.dataset.url, location.href).pathname;
        if (doc && doc.location.pathname !== esperado) {
            const aviso = doc.querySelector('.alert');
            cerrar();
            window.zToast(aviso ? aviso.textContent.trim() : 'No se pudo generar el documento.', 'error');
            return;
        }
        if (doc && doc.head) {
            // La barra "Imprimir / Volver" del documento sobra: esas acciones están en el encabezado del modal
            doc.head.insertAdjacentHTML('beforeend', '<style>.no-print{display:none!important}</style>');
        }
        cuerpo.classList.add('cargado');
        btnImprimir.disabled = false;
    }

    function cerrar() {
        if (!modal) return;
        modal.classList.remove('open');
        delete frame.dataset.url;
        setTimeout(() => { if (!frame.dataset.url) frame.src = 'about:blank'; }, 250);
    }

    window.zVista = function (url, tit, excelUrl) {
        if (!modal) crear();
        titulo.textContent = tit || 'Vista previa';
        btnExcel.style.display = excelUrl ? '' : 'none';
        btnExcel.href = excelUrl || '#';
        btnImprimir.disabled = true;
        cuerpo.classList.remove('cargado');
        frame.dataset.url = url;
        frame.src = url;
        requestAnimationFrame(() => modal.classList.add('open'));
        modal.querySelector('#zVistaCerrar').focus();
    };
    window.zVistaCerrar = cerrar;

    document.addEventListener('click', (e) => {
        const enlace = e.target.closest('a[data-vista]');
        if (enlace) {
            e.preventDefault();
            window.zVista(enlace.getAttribute('href'), enlace.dataset.vista, enlace.dataset.excel);
            return;
        }
        // Enlaces de descarga directa (adjuntos): avisan que empezó la descarga
        if (e.target.closest('a[data-descarga]')) window.zToast('Descargando el archivo…', 'success');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && modal.classList.contains('open')) cerrar();
    });
})();


// Tiempo real: consulta /actions/cambios.php cada pocos segundos; si otro usuario guardó, subió o
// aprobó algo, refresca en silencio la zona actual (#ajaxFiltroZona). No interrumpe: si hay un modal
// abierto o el usuario está escribiendo, espera al siguiente ciclo. Si cambia el rol o el estado de
// la cuenta del usuario actual (p.ej. el admin aprueba su solicitud), recarga la página completa
// para que el menú lateral y los permisos se actualicen.
(function () {
    const CADA_MS = 4000;
    let huella = null, sesion = null, pendiente = false, ocupado = false;

    function usuarioOcupado() {
        if (document.querySelector('.modal-overlay.open, #zcOverlay.open')) return true;
        const a = document.activeElement;
        return !!(a && a.closest('#ajaxFiltroZona') && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)
                  && !['checkbox', 'radio', 'button', 'submit'].includes(a.type));
    }

    function ciclo() {
        if (document.hidden || ocupado) return;
        ocupado = true;
        fetch('/actions/cambios.php', { cache: 'no-store' })
            .then((r) => { if (r.status === 401) { location.reload(); throw new Error('sesion'); } return r.json(); })
            .then((d) => {
                if (!d.ok) return;
                if (sesion !== null && d.sesion !== sesion) { location.reload(); return; }
                if (huella !== null && d.v !== huella) pendiente = true;
                huella = d.v; sesion = d.sesion;
                if (pendiente && !usuarioOcupado()) {
                    pendiente = false;
                    // Dashboard y estadísticas dibujan sus gráficas con scripts propios: se recargan completas
                    if (!document.getElementById('ajaxFiltroZona') && /\/(dashboard|estadisticas)\.php$/i.test(location.pathname)) {
                        try { sessionStorage.setItem('zActualizado', '1'); } catch (e) {}
                        location.reload();
                        return;
                    }
                    return cargarZona(location.href, false, true);
                }
            })
            .catch(() => {})
            .finally(() => { ocupado = false; });
    }

    if (window.location.pathname.indexOf('/pages/') !== 0) return;
    setInterval(ciclo, CADA_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) ciclo(); });
    ciclo();
})();


// Estilos y burbuja de la animación de "datos actualizados" (tiempo real)
(function () {
    const css = document.createElement('style');
    css.textContent = `
    @keyframes z-entra{from{opacity:.55;transform:translateY(8px)}to{opacity:1;transform:none}}
    .z-refresco{animation:z-entra .45s cubic-bezier(.2,.8,.3,1)}
    @keyframes z-destello{0%{box-shadow:0 0 0 0 var(--accent-glow,rgba(91,33,182,.25));background-color:var(--accent-glow,rgba(91,33,182,.12))}60%{box-shadow:0 0 0 8px transparent}100%{box-shadow:0 0 0 0 transparent;background-color:transparent}}
    .z-nuevo{animation:z-destello 1.6s ease-out}
    .z-burbuja{position:fixed;left:50%;bottom:26px;z-index:2200;display:flex;align-items:center;gap:8px;padding:9px 16px 9px 12px;border-radius:999px;background:var(--accent);color:#fff;font-size:13px;font-weight:600;box-shadow:0 8px 26px rgba(0,0,0,.28);pointer-events:none;opacity:0;transform:translate(-50%,24px) scale(.6);transition:transform .45s cubic-bezier(.34,1.56,.64,1),opacity .25s ease}
    .z-burbuja.in{opacity:1;transform:translate(-50%,0) scale(1)}
    .z-burbuja.out{opacity:0;transform:translate(-50%,-10px) scale(.9);transition:transform .3s ease,opacity .3s ease}
    .z-burbuja .material-symbols-outlined{font-size:18px;animation:z-gira 1s ease}
    @keyframes z-gira{from{transform:rotate(-180deg)}to{transform:none}}
    @media (prefers-reduced-motion:reduce){.z-refresco,.z-nuevo{animation:none}.z-burbuja{transition:opacity .2s}}
    @media print{.z-burbuja{display:none!important}}`;
    document.head.appendChild(css);

    let activa = null, temporizador = null;
    window.zBurbujaActualizado = function () {
        if (!activa) {
            activa = document.createElement('div');
            activa.className = 'z-burbuja';
            activa.innerHTML = '<span class="material-symbols-outlined">sync</span> Datos actualizados';
            document.body.appendChild(activa);
            requestAnimationFrame(() => requestAnimationFrame(() => activa && activa.classList.add('in')));
        }
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
            const b = activa; activa = null;
            if (!b) return;
            b.classList.remove('in'); b.classList.add('out');
            setTimeout(() => b.remove(), 350);
        }, 1800);
    };

    // Tras una recarga completa por tiempo real (dashboard / estadísticas) también se muestra la burbuja
    try {
        if (sessionStorage.getItem('zActualizado') === '1') {
            sessionStorage.removeItem('zActualizado');
            window.addEventListener('load', () => setTimeout(window.zBurbujaActualizado, 300));
        }
    } catch (e) {}
})();
