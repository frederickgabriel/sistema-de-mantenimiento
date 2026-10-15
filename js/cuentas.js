// Extras › Cuentas (pages/cuentas.php). Todo sin recargar la página:
//  - Altas, ediciones y bajas por fetch a /actions/cuentas.php; la lista se actualiza en el acto.
//  - Tiempo real: consulta cada 4 s con una "huella" de versión; si nada cambió el servidor responde
//    {sin_cambios:true} y no se redibuja nada. Lo que llega nuevo o modificado destella un instante.
(function () {
    'use strict';
    const API = '/actions/cuentas.php';
    const CADA_MS = 4000;
    const OCULTAR_PASS_MS = 15000;
    const EMPRESA = '__empresa__';
    const reducir = matchMedia('(prefers-reduced-motion: reduce)').matches;

    const $ = (id) => document.getElementById(id);
    const el = {
        grid: $('cuGrid'), vacio: $('cuVacio'), stats: $('cuStats'), filtros: $('cuFiltros'),
        buscar: $('cuBuscar'), nueva: $('cuNueva'), live: $('cuLive'), liveTxt: $('cuLiveTxt'),
        modal: $('cuModal'), form: $('cuForm'), cerrar: $('cuCerrar'), cancelar: $('cuCancelar'),
        titulo: $('cuModalTitulo'), sub: $('cuModalSub'), ico: $('cuModalIco'),
        correo: $('cuCorreo'), correoEstado: $('cuCorreoEstado'), correoAyuda: $('cuCorreoAyuda'), dominios: $('cuDominios'),
        pass: $('cuPassword'), verPass: $('cuVerPass'), generar: $('cuGenerar'), fuerza: $('cuFuerza'), passAyuda: $('cuPassAyuda'),
        asignado: $('cuAsignado'), notas: $('cuNotas'), error: $('cuFormError'),
        guardar: $('cuGuardar'), guardarTxt: $('cuGuardarTxt'),
    };

    const D = window.CUENTAS_DOMINIOS || { populares: [], empresa: [] };
    const state = {
        cuentas: [], version: '', filtro: 'todos', q: '', editando: null, ocupado: false, enVuelo: false,
        nodos: new Map(),      // id → { nodo, firma }
        ocultar: new Map(),    // id → timeout que vuelve a ocultar la contraseña
    };
    let ultimoFoco = null;

    // ---------- Utilidades ----------
    const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const icono = (n, cls) => '<span class="material-symbols-outlined' + (cls ? ' ' + cls : '') + '">' + n + '</span>';
    const toast = (msg, tipo) => { if (window.zToast) window.zToast(msg, tipo || 'success'); };
    const fecha = (iso) => new Date(iso.replace(' ', 'T')).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });

    function api(accion, datos, metodo) {
        const post = metodo === 'POST';
        const params = new URLSearchParams(Object.assign({ accion }, datos || {}));
        return fetch(post ? API : API + '?' + params, {
            method: post ? 'POST' : 'GET', body: post ? params : undefined,
            headers: { 'X-Ajax-Request': '1' }, cache: 'no-store', credentials: 'same-origin',
        }).then((r) => {
            if (r.status === 401) { location.href = '/index.php'; throw new Error('sesion'); }
            return r.json().catch(() => ({ ok: false, msg: 'Respuesta no válida del servidor.' }));
        });
    }

    // ---------- Estado "en vivo" ----------
    function setLive(estado, txt) { el.live.dataset.estado = estado; el.liveTxt.textContent = txt; }

    // ---------- Render ----------
    function firma(c) { return [c.correo, c.asignado_a, c.notas, c.actualizada].join('|'); }

    function proveedores() {
        const m = new Map();
        state.cuentas.forEach((c) => { if (c.empresa) return; const k = c.proveedor; m.set(k, (m.get(k) || 0) + 1); });
        return m;
    }

    function pintarResumen() {
        const total = state.cuentas.length, prov = proveedores().size;
        const empresa = state.cuentas.filter((c) => c.empresa).length;
        const items = [['key', total, 'Cuentas asignadas'], ['group', new Set(state.cuentas.map((c) => c.asignado_a.toLowerCase())).size, 'Personas'],
                       ['mail', prov, 'Proveedores'], ['business', empresa, 'De la empresa']];
        el.stats.innerHTML = items.map((i) => '<div class="cu-stat"><div class="cu-stat-ico">' + icono(i[0]) + '</div><div><strong>' + i[1] + '</strong><span class="cu-stat-lbl">' + i[2] + '</span></div></div>').join('');
    }

    function pintarFiltros() {
        const m = proveedores();
        if (state.filtro !== 'todos' && state.filtro !== EMPRESA && !m.has(state.filtro)) state.filtro = 'todos';
        const nEmpresa = state.cuentas.filter((c) => c.empresa).length;
        const chips = [['todos', 'Todas', state.cuentas.length, ''], [EMPRESA, 'Empresa', nEmpresa, 'business']]
            .concat([...m.entries()].sort((a, b) => b[1] - a[1]).map((e) => [e[0], e[0], e[1], '']));
        el.filtros.innerHTML = chips.map((c) => '<button type="button" class="cu-chip" data-f="' + esc(c[0]) + '" aria-pressed="' + (state.filtro === c[0]) + '">' + (c[3] ? icono(c[3], 'mi-sm') : '') + esc(c[1]) + ' <b>' + c[2] + '</b></button>').join('');
    }

    function nodoCuenta(c, i) {
        const art = document.createElement('article');
        art.className = 'cu-card';
        art.dataset.id = c.id;
        art.style.setProperty('--i', Math.min(i, 12));
        art.style.setProperty('--c', c.color);
        llenarNodo(art, c);
        return art;
    }

    function llenarNodo(art, c) {
        const visible = art.querySelector('.cu-pass.visible');
        const claro = visible ? visible.textContent : null;
        art.style.setProperty('--c', c.color);
        art.innerHTML =
            '<div class="cu-card-top"><div class="cu-orb">' + icono(c.icono) + '</div><div class="cu-card-id">' +
                '<div class="cu-correo">' + esc(c.correo) + '</div>' +
                '<div class="cu-prov">' + icono(c.empresa ? 'verified' : 'mail') + (c.empresa ? 'Empresa · ' + esc(c.correo.split('@')[1]) : esc(c.proveedor)) + '</div></div></div>' +
            '<div class="cu-datos">' +
                '<div class="cu-dato">' + icono('alternate_email') + '<div class="cu-dato-txt">' + esc(c.correo) + '</div>' +
                    '<button type="button" class="cu-btn" data-a="copiar-correo" aria-label="Copiar correo" title="Copiar correo">' + icono('content_copy') + '</button></div>' +
                '<div class="cu-dato">' + icono('lock') + '<div class="cu-dato-txt cu-pass' + (claro !== null ? ' visible' : '') + '" data-pass>' + (claro !== null ? esc(claro) : '••••••••••') + '</div>' +
                    '<button type="button" class="cu-btn" data-a="ver" aria-label="' + (claro !== null ? 'Ocultar' : 'Mostrar') + ' contraseña" title="' + (claro !== null ? 'Ocultar' : 'Mostrar') + ' contraseña">' + icono(claro !== null ? 'visibility_off' : 'visibility') + '</button>' +
                    '<button type="button" class="cu-btn" data-a="copiar-pass" aria-label="Copiar contraseña" title="Copiar contraseña">' + icono('key') + '</button></div>' +
            '</div>' +
            '<div class="cu-meta"><div class="cu-asignada"><span class="cu-ini">' + esc(c.asignado_a.charAt(0).toUpperCase()) + '</span><span>' + esc(c.asignado_a) + '</span></div>' +
                '<span class="cu-fecha" title="Última actualización">' + fecha(c.actualizada) + '</span></div>' +
            (c.notas ? '<div class="cu-nota">' + esc(c.notas) + '</div>' : '') +
            '<div class="cu-card-pie">' +
                '<button type="button" class="cu-btn" data-a="editar" aria-label="Editar cuenta" title="Editar">' + icono('edit') + '</button>' +
                '<button type="button" class="cu-btn peligro" data-a="borrar" aria-label="Eliminar cuenta" title="Eliminar">' + icono('delete') + '</button></div>';
    }

    function coincide(c) {
        if (state.filtro === EMPRESA) { if (!c.empresa) return false; }
        else if (state.filtro !== 'todos' && c.proveedor !== state.filtro) return false;
        if (!state.q) return true;
        return (c.correo + ' ' + c.asignado_a + ' ' + c.notas + ' ' + c.proveedor).toLowerCase().includes(state.q);
    }

    // Reconciliación por id: solo se crean, actualizan o quitan los nodos que cambiaron (nada de borrar y repintar todo).
    function render(opts) {
        opts = opts || {};
        pintarResumen(); pintarFiltros();
        const lista = state.cuentas.filter(coincide);
        const ids = new Set(lista.map((c) => c.id));

        state.nodos.forEach((v, id) => {
            if (ids.has(id)) return;
            state.nodos.delete(id);
            if (reducir) v.nodo.remove();
            else { v.nodo.classList.add('cu-sale'); setTimeout(() => v.nodo.remove(), 230); }
        });

        let previo = null;
        lista.forEach((c, i) => {
            let reg = state.nodos.get(c.id);
            const f = firma(c);
            if (!reg) {
                reg = { nodo: nodoCuenta(c, opts.primera ? i : 0), firma: f };
                if (!opts.primera) { reg.nodo.classList.add('cu-nueva-fila'); reg.nodo.style.setProperty('--i', 0); }
                state.nodos.set(c.id, reg);
            } else if (reg.firma !== f) {
                llenarNodo(reg.nodo, c); reg.firma = f;
                reg.nodo.classList.remove('cu-cambio'); void reg.nodo.offsetWidth; reg.nodo.classList.add('cu-cambio');
            }
            // Orden correcto sin mover nodos que ya están bien colocados
            const esperado = previo ? previo.nextSibling : el.grid.firstChild;
            if (reg.nodo !== esperado) el.grid.insertBefore(reg.nodo, esperado);
            previo = reg.nodo;
        });

        const hay = state.cuentas.length > 0;
        el.vacio.hidden = lista.length > 0;
        if (!lista.length) {
            el.vacio.innerHTML = hay
                ? icono('search_off') + '<strong>Sin resultados</strong>Prueba con otra búsqueda o quita el filtro.'
                : icono('key') + '<strong>Aún no hay cuentas</strong>Crea la primera para asignar un correo y una contraseña.<br><button type="button" class="btn btn-primary" data-a="vacio-nueva">' + icono('add', 'mi-sm') + ' Nueva cuenta</button>';
        }
    }

    function aplicar(d, opts) {
        state.cuentas = d.cuentas; state.version = d.version;
        render(opts);
    }

    // ---------- Tiempo real ----------
    function sondear() {
        if (state.enVuelo || document.hidden) return;
        state.enVuelo = true;
        api('lista', { v: state.version }).then((d) => {
            if (!d.ok) { setLive('error', 'Sin conexión'); return; }
            setLive('vivo', 'En vivo');
            if (!d.sin_cambios) aplicar(d);
        }).catch(() => setLive('error', 'Sin conexión')).finally(() => { state.enVuelo = false; });
    }
    setInterval(sondear, CADA_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sondear(); });
    window.addEventListener('online', sondear);
    window.addEventListener('offline', () => setLive('error', 'Sin conexión'));

    // ---------- Inclinación 3D (solo puntero fino, y siempre 1:1 con el cursor) ----------
    if (!reducir && matchMedia('(hover: hover) and (pointer: fine)').matches) {
        let raf = 0, objetivo = null, px = 0, py = 0;
        const MAX = 7;
        el.grid.addEventListener('pointermove', (e) => {
            const card = e.target.closest('.cu-card');
            if (!card || card.querySelector('.cu-confirma')) return;
            objetivo = card; px = e.clientX; py = e.clientY;
            if (raf) return;
            raf = requestAnimationFrame(() => {
                raf = 0;
                const r = objetivo.getBoundingClientRect();
                const x = (px - r.left) / r.width, y = (py - r.top) / r.height;
                objetivo.classList.add('cu-mueve');
                objetivo.style.setProperty('--ry', ((x - 0.5) * 2 * MAX).toFixed(2) + 'deg');
                objetivo.style.setProperty('--rx', ((0.5 - y) * 2 * MAX).toFixed(2) + 'deg');
                objetivo.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
                objetivo.style.setProperty('--my', (y * 100).toFixed(1) + '%');
            });
        });
        el.grid.addEventListener('pointerout', (e) => {
            const card = e.target.closest('.cu-card');
            if (!card || card.contains(e.relatedTarget)) return;
            card.classList.remove('cu-mueve');
            card.style.setProperty('--rx', '0deg'); card.style.setProperty('--ry', '0deg');
        });
    }

    // ---------- Acciones de tarjeta ----------
    function copiar(texto, btn) {
        const listo = () => {
            const ic = btn.querySelector('.material-symbols-outlined'), antes = ic.textContent;
            btn.classList.add('ok'); ic.textContent = 'check';
            setTimeout(() => { btn.classList.remove('ok'); ic.textContent = antes; }, 1400);
        };
        const respaldo = () => {
            const t = document.createElement('textarea'); t.value = texto; t.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); listo(); } catch (e) { toast('No se pudo copiar.', 'error'); }
            t.remove();
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(texto).then(listo, respaldo); else respaldo();
    }

    function pedirPass(id) { return api('revelar', { id }).then((d) => { if (!d.ok) throw new Error(d.msg || 'No se pudo obtener la contraseña.'); return d.password; }); }

    function ocultarPass(card) {
        const id = +card.dataset.id;
        clearTimeout(state.ocultar.get(id)); state.ocultar.delete(id);
        const txt = card.querySelector('[data-pass]'); if (!txt) return;
        txt.textContent = '••••••••••'; txt.classList.remove('visible');
        const b = card.querySelector('[data-a="ver"]');
        b.querySelector('.material-symbols-outlined').textContent = 'visibility';
        b.setAttribute('aria-label', 'Mostrar contraseña'); b.title = 'Mostrar contraseña';
    }

    function alternarPass(card, btn) {
        const txt = card.querySelector('[data-pass]');
        if (txt.classList.contains('visible')) { ocultarPass(card); return; }
        btn.disabled = true;
        pedirPass(+card.dataset.id).then((p) => {
            txt.textContent = p; txt.classList.add('visible');
            btn.querySelector('.material-symbols-outlined').textContent = 'visibility_off';
            btn.setAttribute('aria-label', 'Ocultar contraseña'); btn.title = 'Ocultar contraseña';
            const id = +card.dataset.id;
            clearTimeout(state.ocultar.get(id));
            state.ocultar.set(id, setTimeout(() => ocultarPass(card), OCULTAR_PASS_MS));
        }).catch((e) => toast(e.message, 'error')).finally(() => { btn.disabled = false; });
    }

    function pedirBorrar(card) {
        if (card.querySelector('.cu-confirma')) return;
        const c = state.cuentas.find((x) => x.id === +card.dataset.id); if (!c) return;
        const v = document.createElement('div');
        v.className = 'cu-confirma'; v.setAttribute('role', 'alertdialog');
        v.innerHTML = icono('delete', 'mi-lg') + '<strong>¿Eliminar esta cuenta?</strong><p>' + esc(c.correo) + ' dejará de estar registrada. No se puede deshacer.</p>' +
            '<div><button type="button" class="btn btn-ghost" data-a="no">Cancelar</button><button type="button" class="btn btn-danger" data-a="si">Eliminar</button></div>';
        card.appendChild(v);
        v.querySelector('[data-a="no"]').focus();
    }

    function borrar(card, btn) {
        btn.disabled = true;
        api('eliminar', { id: card.dataset.id }, 'POST').then((d) => {
            if (d.cuentas) aplicar(d);
            toast(d.msg, d.ok ? 'success' : 'error');
        }).catch(() => toast('No se pudo eliminar. Revisa tu conexión.', 'error')).finally(() => { btn.disabled = false; });
    }

    el.grid.addEventListener('click', (e) => {
        const b = e.target.closest('[data-a]'); if (!b) return;
        const card = b.closest('.cu-card'); if (!card) return;
        const c = state.cuentas.find((x) => x.id === +card.dataset.id);
        switch (b.dataset.a) {
            case 'copiar-correo': if (c) copiar(c.correo, b); break;
            case 'copiar-pass': {
                const vis = card.querySelector('.cu-pass.visible');
                if (vis) copiar(vis.textContent, b);
                else pedirPass(+card.dataset.id).then((p) => copiar(p, b)).catch((er) => toast(er.message, 'error'));
                break;
            }
            case 'ver': alternarPass(card, b); break;
            case 'editar': if (c) abrirModal(c); break;
            case 'borrar': pedirBorrar(card); break;
            case 'no': card.querySelector('.cu-confirma')?.remove(); break;
            case 'si': borrar(card, b); break;
        }
    });
    el.grid.addEventListener('keydown', (e) => { if (e.key === 'Escape') e.target.closest('.cu-card')?.querySelector('.cu-confirma')?.remove(); });
    el.vacio.addEventListener('click', (e) => { if (e.target.closest('[data-a="vacio-nueva"]')) abrirModal(null); });

    // ---------- Búsqueda y filtros ----------
    let tb = 0;
    el.buscar.addEventListener('input', () => { clearTimeout(tb); tb = setTimeout(() => { state.q = el.buscar.value.trim().toLowerCase(); render(); }, 120); });
    el.filtros.addEventListener('click', (e) => {
        const b = e.target.closest('[data-f]'); if (!b) return;
        state.filtro = b.dataset.f; render();
    });

    // ---------- Modal ----------
    function anotarDominios() {
        const mk = (d) => '<button type="button" data-d="' + esc(d) + '">@' + esc(d) + '</button>';
        const pop = ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com'].filter((d) => D.populares.includes(d));
        el.dominios.innerHTML = pop.concat(D.empresa).map(mk).join('');
    }

    function fijarEstadoCorreo(tipo, msg) {
        const grupo = el.correo.closest('.cu-input');
        grupo.classList.toggle('invalido', tipo === 'mal'); grupo.classList.toggle('valido', tipo === 'ok');
        el.correoEstado.innerHTML = tipo === 'ok' ? icono('check_circle', 'ok') : tipo === 'mal' ? icono('error', 'mal') : tipo === 'carga' ? icono('progress_activity', 'cu-spin') : '';
        el.correoAyuda.className = 'cu-ayuda' + (tipo === 'mal' ? ' mal' : tipo === 'ok' ? ' ok' : '');
        el.correoAyuda.textContent = msg;
    }
    const AYUDA_CORREO = 'Gmail, Outlook, Hotmail, Yahoo, iCloud o de la empresa.';

    let tv = 0, validando = 0, correoOk = false;
    function validarCorreo(inmediato) {
        clearTimeout(tv);
        const v = el.correo.value.trim().toLowerCase();
        correoOk = false;
        if (!v) { fijarEstadoCorreo('', AYUDA_CORREO); return Promise.resolve(false); }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
            // Mientras escribe no se grita: solo se marca error si ya hay @ y dominio incompleto al salir del campo
            if (inmediato) fijarEstadoCorreo('mal', 'Escribe un correo con formato válido (nombre@dominio.com).');
            else fijarEstadoCorreo('', AYUDA_CORREO);
            return Promise.resolve(false);
        }
        const dom = v.split('@')[1];
        if (!D.populares.includes(dom) && !D.empresa.includes(dom)) {
            fijarEstadoCorreo('mal', 'Solo se aceptan proveedores conocidos (Gmail, Outlook, Hotmail, Yahoo, iCloud…) o de la empresa.');
            return Promise.resolve(false);
        }
        const mio = ++validando;
        fijarEstadoCorreo('carga', 'Comprobando que el correo exista…');
        return new Promise((res) => {
            tv = setTimeout(() => {
                api('validar', { correo: v, id: state.editando ? state.editando.id : 0 }).then((d) => {
                    if (mio !== validando) return res(false);
                    correoOk = !!d.ok;
                    fijarEstadoCorreo(d.ok ? 'ok' : 'mal', d.ok ? 'Correo válido (' + d.proveedor.nombre + ').' : d.msg);
                    res(!!d.ok);
                }).catch(() => { if (mio === validando) fijarEstadoCorreo('', 'No se pudo comprobar ahora; se validará al guardar.'); res(true); });
            }, inmediato ? 0 : 450);
        });
    }
    el.correo.addEventListener('input', () => validarCorreo(false));
    el.correo.addEventListener('blur', () => validarCorreo(true));
    el.dominios.addEventListener('click', (e) => {
        const b = e.target.closest('[data-d]'); if (!b) return;
        const usuario = el.correo.value.split('@')[0].trim();
        el.correo.value = (usuario || '') + '@' + b.dataset.d;
        el.correo.focus();
        if (usuario) { el.correo.setSelectionRange(usuario.length, usuario.length); validarCorreo(true); }
        else el.correo.setSelectionRange(0, 0);
    });

    function medirFuerza(p) {
        if (!p) return 0;
        let n = 0;
        if (p.length >= 8) n++;
        if (p.length >= 12) n++;
        if (/[a-z]/.test(p) && /[A-Z]/.test(p)) n++;
        if (/\d/.test(p) && /[^A-Za-z0-9]/.test(p)) n++;
        return Math.min(4, Math.max(1, n));
    }
    el.pass.addEventListener('input', () => { el.fuerza.dataset.n = medirFuerza(el.pass.value); });
    el.verPass.addEventListener('click', () => {
        const ver = el.pass.type === 'password';
        el.pass.type = ver ? 'text' : 'password';
        el.verPass.querySelector('.material-symbols-outlined').textContent = ver ? 'visibility_off' : 'visibility';
    });
    el.generar.addEventListener('click', () => {
        const sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%&*?'];
        const todo = sets.join(''), buf = new Uint32Array(16);
        crypto.getRandomValues(buf);
        const out = sets.map((s, i) => s[buf[i] % s.length]);
        for (let i = 4; i < 16; i++) out.push(todo[buf[i] % todo.length]);
        for (let i = out.length - 1; i > 0; i--) { const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1); [out[i], out[j]] = [out[j], out[i]]; }
        el.pass.value = out.join('');
        el.pass.type = 'text'; el.verPass.querySelector('.material-symbols-outlined').textContent = 'visibility_off';
        el.pass.dispatchEvent(new Event('input'));
    });

    function mostrarError(msg) { el.error.hidden = !msg; el.error.innerHTML = msg ? icono('error', 'mi-sm') + '<span>' + esc(msg) + '</span>' : ''; }

    function abrirModal(c) {
        ultimoFoco = document.activeElement;
        state.editando = c; mostrarError('');
        el.form.reset(); el.pass.type = 'password'; el.verPass.querySelector('.material-symbols-outlined').textContent = 'visibility';
        el.fuerza.dataset.n = 0;
        el.titulo.textContent = c ? 'Editar cuenta' : 'Nueva cuenta';
        el.sub.textContent = c ? 'Cambia los datos; deja la contraseña vacía para conservar la actual.' : 'Asigna un correo y una contraseña a una persona.';
        el.ico.textContent = c ? 'edit' : 'person_add';
        el.guardarTxt.textContent = c ? 'Guardar cambios' : 'Guardar cuenta';
        el.passAyuda.textContent = c ? 'Vacía = no se cambia la contraseña.' : 'Usa letras, números y símbolos.';
        el.pass.placeholder = c ? 'Sin cambios' : 'Mínimo 8 caracteres';
        if (c) { el.correo.value = c.correo; el.asignado.value = c.asignado_a; el.notas.value = c.notas; }
        fijarEstadoCorreo('', AYUDA_CORREO);
        correoOk = !!c;
        el.modal.classList.add('open'); el.modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(() => el.correo.focus(), 60);
    }

    function cerrarModal() {
        if (state.ocupado) return;
        el.modal.classList.remove('open'); el.modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        state.editando = null; validando++;
        el.form.reset();
        if (ultimoFoco && ultimoFoco.focus) ultimoFoco.focus();
    }

    el.nueva.addEventListener('click', () => abrirModal(null));
    el.cerrar.addEventListener('click', cerrarModal);
    el.cancelar.addEventListener('click', cerrarModal);
    el.modal.addEventListener('mousedown', (e) => { if (e.target === el.modal) cerrarModal(); });
    document.addEventListener('keydown', (e) => {
        if (!el.modal.classList.contains('open')) return;
        if (e.key === 'Escape') { e.stopPropagation(); cerrarModal(); return; }
        if (e.key === 'Tab') { // el foco se queda dentro del cuadro
            const f = [...el.modal.querySelectorAll('button:not([disabled]), input')].filter((n) => n.offsetParent !== null);
            if (!f.length) return;
            const a = f[0], z = f[f.length - 1];
            if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
            else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
        }
    });

    function ocupar(si) {
        state.ocupado = si;
        el.guardar.disabled = si; el.guardar.querySelector('.cu-spin').hidden = !si;
        el.guardarTxt.textContent = si ? 'Guardando…' : (state.editando ? 'Guardar cambios' : 'Guardar cuenta');
    }

    el.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (state.ocupado) return;
        mostrarError('');
        const correo = el.correo.value.trim().toLowerCase(), pass = el.pass.value, asignado = el.asignado.value.trim();
        if (!correo) { el.correo.focus(); fijarEstadoCorreo('mal', 'Escribe el correo.'); return; }
        if (!state.editando && pass.length < 8) { el.pass.focus(); mostrarError('La contraseña debe tener al menos 8 caracteres.'); return; }
        if (state.editando && pass && pass.length < 8) { el.pass.focus(); mostrarError('La contraseña debe tener al menos 8 caracteres.'); return; }
        if (!asignado) { el.asignado.focus(); mostrarError('Indica a quién se asigna la cuenta.'); return; }
        ocupar(true);
        try {
            if (!correoOk && !(await validarCorreo(true))) { el.correo.focus(); ocupar(false); return; }
            const d = await api('guardar', { id: state.editando ? state.editando.id : 0, correo, password: pass, asignado_a: asignado, notas: el.notas.value.trim() }, 'POST');
            if (!d.ok) {
                mostrarError(d.msg);
                if (d.campo === 'correo') fijarEstadoCorreo('mal', d.msg);
                ocupar(false); return;
            }
            const editaba = !!state.editando;
            aplicar(d);
            state.ocupado = false; el.guardar.disabled = false; el.guardar.querySelector('.cu-spin').hidden = true;
            cerrarModal();
            if (d.nueva_id) {
                const reg = state.nodos.get(d.nueva_id);
                if (reg) reg.nodo.scrollIntoView({ behavior: reducir ? 'auto' : 'smooth', block: 'nearest' });
            }
            toast(editaba ? 'Cuenta actualizada.' : 'Cuenta creada.');
        } catch (err) {
            mostrarError('No se pudo guardar. Revisa tu conexión e inténtalo de nuevo.');
            ocupar(false);
        }
    });

    // ---------- Inicio ----------
    anotarDominios();
    aplicar(window.CUENTAS_INICIAL || { cuentas: [], version: '' }, { primera: true });
    setLive('vivo', 'En vivo');
})();
