// Notificaciones: campanita con contador + avisos flotantes a un costado de la pantalla (estilo
// WhatsApp Web) + aviso del navegador cuando la pestaña está en segundo plano.
// Consulta /actions/notificaciones.php cada pocos segundos (también en pestañas ocultas, aunque el
// navegador las limite a ~1 vez por minuto). Funciona mientras el sistema esté abierto en alguna pestaña.
(function () {
    const CADA_MS = 5000;
    const URL_API = '/actions/notificaciones.php';
    let ultimoId = null;       // mayor id ya visto (null = primera consulta: no mostrar avisos viejos)
    let panelAbierto = false;
    let ocupado = false;

    // ---------- Estilos ----------
    const css = document.createElement('style');
    css.textContent = `
    .nt-bell{position:fixed;top:84px;right:18px;z-index:950;width:42px;height:42px;border-radius:50%;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:var(--shadow);transition:transform .15s ease,background .15s ease}
    .nt-bell:hover{background:var(--bg-card2)}.nt-bell:active{transform:scale(.94)}
    .nt-bell.nt-suena .material-symbols-outlined{animation:nt-sacude .7s ease}
    @keyframes nt-sacude{0%,100%{transform:rotate(0)}20%{transform:rotate(14deg)}40%{transform:rotate(-12deg)}60%{transform:rotate(8deg)}80%{transform:rotate(-5deg)}}
    .nt-badge{position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:var(--danger);color:#fff;font-size:11px;font-weight:700;display:none;align-items:center;justify-content:center;border:2px solid var(--bg-card)}
    .nt-badge.on{display:flex}
    .nt-panel{position:fixed;top:134px;right:18px;z-index:951;width:360px;max-width:calc(100vw - 24px);max-height:min(70vh,520px);display:flex;flex-direction:column;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:0 12px 40px rgba(0,0,0,.28);opacity:0;transform:translateY(-6px) scale(.98);transform-origin:top right;pointer-events:none;transition:opacity .16s ease,transform .16s ease}
    .nt-panel.open{opacity:1;transform:none;pointer-events:auto}
    .nt-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 16px;border-bottom:1px solid var(--border);font-weight:700;color:var(--text-primary)}
    .nt-head button{background:none;border:0;color:var(--accent);font-size:12px;font-weight:600;cursor:pointer;padding:4px 6px;border-radius:6px}
    .nt-head button:hover{background:var(--accent-glow)}
    .nt-lista{overflow-y:auto;flex:1}
    .nt-item{display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border-light);cursor:pointer;text-decoration:none;color:inherit;transition:background .12s ease}
    .nt-item:hover{background:var(--bg-card2)}
    .nt-item.nueva{background:var(--accent-glow)}
    .nt-ico{flex:none;width:36px;height:36px;border-radius:50%;background:var(--accent-glow);color:var(--accent);display:flex;align-items:center;justify-content:center}
    .nt-txt{min-width:0;flex:1}.nt-tit{font-size:13.5px;font-weight:600;color:var(--text-primary)}
    .nt-msg{font-size:12.5px;color:var(--text-secondary);margin-top:2px;overflow-wrap:anywhere}
    .nt-fecha{font-size:11px;color:var(--text-muted,var(--text-secondary));margin-top:4px;opacity:.8}
    .nt-vacio{padding:34px 16px;text-align:center;color:var(--text-secondary);font-size:13px}
    .nt-pie{padding:10px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-secondary)}
    .nt-pie button{background:none;border:0;color:var(--accent);font-weight:600;cursor:pointer;padding:0;font-size:12px}
    .nt-avisos{position:fixed;top:84px;right:18px;z-index:2100;display:flex;flex-direction:column;gap:10px;width:340px;max-width:calc(100vw - 24px);pointer-events:none}
    .nt-aviso{pointer-events:auto;position:relative;display:flex;gap:12px;padding:12px 14px;background:var(--bg-card);border:1px solid var(--border);border-left:4px solid var(--accent);border-radius:var(--radius-md);box-shadow:0 10px 32px rgba(0,0,0,.3);cursor:pointer;transform:translateX(120%);opacity:0;transition:transform .35s cubic-bezier(.2,.9,.3,1),opacity .35s ease}
    .nt-aviso.in{transform:none;opacity:1}
    .nt-aviso.out{transform:translateX(120%);opacity:0}
    .nt-x{position:absolute;top:4px;right:6px;background:none;border:0;color:var(--text-secondary);cursor:pointer;font-size:16px;line-height:1;padding:4px}
    @media (max-width:768px){.nt-bell{top:7px;right:58px;width:38px;height:38px}.nt-panel{top:56px;right:8px}.nt-avisos{top:60px;right:8px}}
    @media (prefers-reduced-motion:reduce){.nt-aviso,.nt-panel{transition:none}.nt-bell.nt-suena .material-symbols-outlined{animation:none}}
    @media print{.nt-bell,.nt-panel,.nt-avisos{display:none!important}}`;
    document.head.appendChild(css);

    // ---------- DOM ----------
    const bell = document.createElement('button');
    bell.className = 'nt-bell';
    bell.type = 'button';
    bell.setAttribute('aria-label', 'Notificaciones');
    bell.innerHTML = '<span class="material-symbols-outlined">notifications</span><span class="nt-badge" id="ntBadge"></span>';
    const panel = document.createElement('div');
    panel.className = 'nt-panel';
    panel.innerHTML = '<div class="nt-head"><span>Notificaciones</span><button type="button" id="ntTodas">Marcar todas como leídas</button></div>' +
        '<div class="nt-lista" id="ntLista"><div class="nt-vacio">Cargando…</div></div>' +
        '<div class="nt-pie" id="ntPie"></div>';
    const avisos = document.createElement('div');
    avisos.className = 'nt-avisos';
    document.body.append(bell, panel, avisos);
    const badge = bell.querySelector('#ntBadge');

    // ---------- Utilidades ----------
    const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const icono = (n) => '<div class="nt-ico"><span class="material-symbols-outlined mi-sm">' + esc(n.icono || 'notifications') + '</span></div>';

    function hace(iso) {
        const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
        if (s < 60) return 'hace un momento';
        if (s < 3600) return 'hace ' + Math.floor(s / 60) + ' min';
        if (s < 86400) return 'hace ' + Math.floor(s / 3600) + ' h';
        return new Date(iso).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
    }

    function post(datos) {
        return fetch(URL_API, { method: 'POST', body: new URLSearchParams(datos), cache: 'no-store' }).then((r) => r.json());
    }

    function abrir(n) {
        post({ accion: 'leer', id: n.id }).then(pintar).catch(() => {});
        if (n.enlace) location.href = n.enlace;
    }

    function setContador(n) {
        badge.textContent = n > 99 ? '99+' : n;
        badge.classList.toggle('on', n > 0);
        document.title = document.title.replace(/^\(\d+\)\s*/, '');
        if (n > 0) document.title = '(' + n + ') ' + document.title;
    }

    function pintar(d) {
        if (!d || !d.ok) return;
        setContador(d.no_leidas);
        if (!d.lista) return;
        const lista = panel.querySelector('#ntLista');
        if (!d.lista.length) { lista.innerHTML = '<div class="nt-vacio">No tienes notificaciones.</div>'; return; }
        lista.innerHTML = '';
        d.lista.forEach((n) => {
            const el = document.createElement('div');
            el.className = 'nt-item' + (n.leida ? '' : ' nueva');
            el.innerHTML = icono(n) + '<div class="nt-txt"><div class="nt-tit">' + esc(n.titulo) + '</div>' +
                (n.mensaje ? '<div class="nt-msg">' + esc(n.mensaje) + '</div>' : '') +
                '<div class="nt-fecha">' + hace(n.fecha) + '</div></div>';
            el.addEventListener('click', () => abrir(n));
            lista.appendChild(el);
        });
    }

    function pintarPie() {
        const pie = panel.querySelector('#ntPie');
        if (!('Notification' in window)) { pie.textContent = ''; return; }
        if (Notification.permission === 'default') {
            pie.innerHTML = '<button type="button" id="ntPermiso">Activar avisos del navegador</button> para recibirlos aunque estés en otra ventana.';
            pie.querySelector('#ntPermiso').addEventListener('click', () => Notification.requestPermission().then(pintarPie));
        } else if (Notification.permission === 'denied') {
            pie.textContent = 'Los avisos del navegador están bloqueados para este sitio (puedes activarlos en la configuración del navegador).';
        } else {
            pie.textContent = 'Avisos del navegador activados.';
        }
    }

    // ---------- Avisos laterales ----------
    function mostrarAviso(n) {
        const el = document.createElement('div');
        el.className = 'nt-aviso';
        el.innerHTML = icono(n) + '<div class="nt-txt"><div class="nt-tit">' + esc(n.titulo) + '</div>' +
            (n.mensaje ? '<div class="nt-msg">' + esc(n.mensaje) + '</div>' : '') + '</div>' +
            '<button class="nt-x" type="button" aria-label="Cerrar">×</button>';
        const quitar = () => { el.classList.remove('in'); el.classList.add('out'); setTimeout(() => el.remove(), 400); };
        el.addEventListener('click', (e) => { if (e.target.closest('.nt-x')) { quitar(); return; } quitar(); abrir(n); });
        avisos.appendChild(el);
        requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('in')));
        let t = setTimeout(quitar, 7000);
        el.addEventListener('mouseenter', () => clearTimeout(t));
        el.addEventListener('mouseleave', () => { t = setTimeout(quitar, 3000); });
    }

    function avisoNavegador(n) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return false;
        try {
            const x = new Notification(n.titulo, { body: n.mensaje || '', icon: '/img/favicon/favicon-180.png', tag: 'zilara-' + n.id });
            x.onclick = () => { window.focus(); x.close(); abrir(n); };
            return true;
        } catch (e) { return false; }
    }

    function sonido() {
        try {
            const C = window.AudioContext || window.webkitAudioContext;
            const ctx = new C();
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine'; o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, ctx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
            o.connect(g).connect(ctx.destination);
            o.start(); o.stop(ctx.currentTime + 0.36);
            o.onended = () => ctx.close();
        } catch (e) { /* sin sonido si el navegador no deja reproducir aún */ }
    }

    // ---------- Consulta periódica ----------
    function consultar(conLista) {
        if (ocupado) return;
        ocupado = true;
        const q = '?desde=' + (ultimoId === null ? -1 : ultimoId) + (conLista || panelAbierto ? '&lista=1' : '');
        fetch(URL_API + q, { cache: 'no-store' })
            .then((r) => { if (r.status === 401) throw new Error('sesion'); return r.json(); })
            .then((d) => {
                if (!d.ok) return;
                if (ultimoId !== null && d.nuevas.length) {
                    bell.classList.remove('nt-suena'); void bell.offsetWidth; bell.classList.add('nt-suena');
                    sonido();
                    d.nuevas.forEach((n) => {
                        if (document.hidden || !document.hasFocus()) { if (!avisoNavegador(n)) mostrarAviso(n); }
                        else mostrarAviso(n);
                    });
                }
                ultimoId = Math.max(ultimoId === null ? 0 : ultimoId, d.max_id);
                pintar(d);
                if (d.nuevas && d.nuevas.length && !d.lista && panelAbierto) consultar(true);
            })
            .catch(() => {})
            .finally(() => { ocupado = false; });
    }

    // ---------- Arrastrar la campanita (la posición se recuerda en este navegador) ----------
    const CLAVE_POS = 'ntBellPos';
    let arrastro = false;

    function limitar(x, y) {
        return [
            Math.min(Math.max(4, x), window.innerWidth - bell.offsetWidth - 4),
            Math.min(Math.max(4, y), window.innerHeight - bell.offsetHeight - 4),
        ];
    }
    function colocar(x, y) {
        [x, y] = limitar(x, y);
        bell.style.left = x + 'px'; bell.style.top = y + 'px'; bell.style.right = 'auto';
        return [x, y];
    }
    // El panel se abre pegado a la campanita, hacia el lado donde hay espacio
    function posicionarPanel() {
        const r = bell.getBoundingClientRect();
        const w = Math.min(360, window.innerWidth - 24);
        const left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
        panel.style.left = left + 'px'; panel.style.right = 'auto';
        const abajo = r.top < window.innerHeight / 2;
        const maxH = Math.max(160, (abajo ? window.innerHeight - r.bottom : r.top) - 20);
        panel.style.maxHeight = Math.min(520, maxH) + 'px';
        if (abajo) { panel.style.top = (r.bottom + 8) + 'px'; panel.style.bottom = 'auto'; panel.style.transformOrigin = 'top right'; }
        else       { panel.style.bottom = (window.innerHeight - r.top + 8) + 'px'; panel.style.top = 'auto'; panel.style.transformOrigin = 'bottom right'; }
    }
    function restaurarPos() {
        try {
            const g = JSON.parse(localStorage.getItem(CLAVE_POS) || 'null');
            if (g && typeof g.x === 'number' && typeof g.y === 'number') colocar(g.x, g.y);
        } catch (e) { /* sin almacenamiento: queda la posición por defecto */ }
    }

    bell.style.touchAction = 'none';
    bell.addEventListener('pointerdown', (e) => {
        if (e.button !== undefined && e.button !== 0) return;
        const r = bell.getBoundingClientRect();
        const dx = e.clientX - r.left, dy = e.clientY - r.top, x0 = e.clientX, y0 = e.clientY;
        let moviendo = false;
        bell.setPointerCapture(e.pointerId);

        const mover = (ev) => {
            if (!moviendo && Math.hypot(ev.clientX - x0, ev.clientY - y0) < 5) return;
            moviendo = true; arrastro = true;
            bell.style.cursor = 'grabbing'; bell.style.transition = 'none';
            colocar(ev.clientX - dx, ev.clientY - dy);
            if (panelAbierto) posicionarPanel();
        };
        const soltar = () => {
            bell.removeEventListener('pointermove', mover);
            bell.removeEventListener('pointerup', soltar);
            bell.removeEventListener('pointercancel', soltar);
            bell.style.cursor = ''; bell.style.transition = '';
            if (moviendo) {
                const b = bell.getBoundingClientRect();
                try { localStorage.setItem(CLAVE_POS, JSON.stringify({ x: b.left, y: b.top })); } catch (e) {}
                setTimeout(() => { arrastro = false; }, 0); // evita que el "click" posterior abra el panel
            }
        };
        bell.addEventListener('pointermove', mover);
        bell.addEventListener('pointerup', soltar);
        bell.addEventListener('pointercancel', soltar);
    });
    window.addEventListener('resize', () => {
        if (bell.style.left) colocar(parseFloat(bell.style.left), parseFloat(bell.style.top));
        if (panelAbierto) posicionarPanel();
    });
    bell.addEventListener('dblclick', () => { // doble clic: vuelve a la posición original
        bell.style.left = bell.style.top = bell.style.right = '';
        try { localStorage.removeItem(CLAVE_POS); } catch (e) {}
        if (panelAbierto) posicionarPanel();
    });
    bell.title = 'Notificaciones (arrastra para moverla; doble clic para restablecer)';
    restaurarPos();

    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        if (arrastro) return;
        panelAbierto = !panelAbierto;
        if (panelAbierto) posicionarPanel();
        panel.classList.toggle('open', panelAbierto);
        if (panelAbierto) { pintarPie(); consultar(true); }
    });
    document.addEventListener('click', (e) => {
        if (panelAbierto && !panel.contains(e.target)) { panelAbierto = false; panel.classList.remove('open'); }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && panelAbierto) { panelAbierto = false; panel.classList.remove('open'); }
    });
    panel.querySelector('#ntTodas').addEventListener('click', () => post({ accion: 'todas' }).then(pintar).catch(() => {}));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) consultar(false); });

    consultar(false);
    setInterval(() => consultar(false), CADA_MS);
})();
