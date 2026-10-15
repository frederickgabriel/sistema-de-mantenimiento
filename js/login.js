// Login (index.php): paralaje 3D del escenario, pestañas con indicador deslizante, pantalla de carga al enviar
// formularios o entrar con Google, y sacudida de la tarjeta cuando hay un error.
(function () {
    'use strict';
    var doc = document;
    var reducir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------- Paralaje del escenario (1:1 con el cursor; vuelve a su sitio con un resorte) ----------
    var panel = doc.querySelector('.login-left'), mundo = doc.querySelector('.lg-mundo');
    if (panel && mundo && !reducir && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        var raf = 0, px = 0, py = 0;
        panel.addEventListener('pointermove', function (e) {
            var r = panel.getBoundingClientRect();
            px = ((e.clientX - r.left) / r.width - 0.5) * 2;
            py = ((e.clientY - r.top) / r.height - 0.5) * 2;
            if (raf) return;
            raf = requestAnimationFrame(function () {
                raf = 0;
                mundo.classList.add('lg-sigue');
                mundo.style.setProperty('--px', px.toFixed(3)); mundo.style.setProperty('--py', py.toFixed(3));
            });
        });
        panel.addEventListener('pointerleave', function () {
            mundo.classList.remove('lg-sigue');
            mundo.style.setProperty('--px', 0); mundo.style.setProperty('--py', 0);
        });
    }

    // ---------- Pestañas ----------
    var nav = doc.querySelector('.tab-nav'), ind = doc.createElement('span');
    ind.className = 'tab-ind'; ind.setAttribute('aria-hidden', 'true');
    if (nav) nav.insertBefore(ind, nav.firstChild);

    function colocarIndicador(animar) {
        if (!nav) return;
        var act = nav.querySelector('.tab-btn.active');
        nav.classList.toggle('lg-sin-ind', !act); // pestaña "verificar": no hay botón activo
        if (!animar) nav.classList.add('lg-sin-trans');
        if (act) { ind.style.setProperty('--x', act.offsetLeft + 'px'); ind.style.setProperty('--w', act.offsetWidth + 'px'); }
        nav.classList.add('lg-ind-listo');
        if (!animar) requestAnimationFrame(function () { requestAnimationFrame(function () { nav.classList.remove('lg-sin-trans'); }); });
    }
    colocarIndicador(false);
    if (doc.fonts && doc.fonts.ready) doc.fonts.ready.then(function () { colocarIndicador(false); });
    window.addEventListener('resize', function () { colocarIndicador(false); });

    window.switchTab = function (name) {
        doc.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
        doc.querySelectorAll('.tab-btn').forEach(function (b) {
            var es = b.getAttribute('onclick') === "switchTab('" + name + "')";
            b.classList.toggle('active', es); b.setAttribute('aria-selected', es ? 'true' : 'false');
        });
        var pane = doc.getElementById('tab-' + name);
        if (pane) pane.classList.add('active');
        colocarIndicador(true);
        var primero = pane && pane.querySelector('input:not([type=hidden])');
        if (primero && !reducir) setTimeout(function () { primero.focus({ preventScroll: true }); }, 120);
    };

    // ---------- Error: sacudir la tarjeta ----------
    var tarjeta = doc.querySelector('.login-card');
    if (tarjeta && tarjeta.querySelector('.alert-error') && !reducir) {
        setTimeout(function () { tarjeta.classList.add('lg-sacude'); setTimeout(function () { tarjeta.classList.remove('lg-sacude'); }, 600); }, 900);
    }

    // ---------- Pantalla de carga al enviar ----------
    var MENSAJES = {
        login: 'Verificando tus credenciales…', registro: 'Enviando el código a tu correo…',
        verificar_registro: 'Verificando el código…', reenviar_registro: 'Enviando un código nuevo…',
    };
    var fin = null;
    doc.addEventListener('submit', function (e) {
        var f = e.target;
        if (!(f instanceof HTMLFormElement) || e.defaultPrevented) return;
        var accion = (f.querySelector('input[name="action"]') || {}).value;
        var btn = e.submitter || f.querySelector('[type="submit"]');
        if (btn) btn.classList.add('btn-cargando');
        if (MENSAJES[accion] && window.zCargando) fin = window.zCargando.mostrar(MENSAJES[accion]);
    });
    window.addEventListener('pageshow', function (e) { // volver con "Atrás": quitar velo y botones ocupados
        if (!e.persisted) return;
        if (fin) { fin(); fin = null; }
        doc.querySelectorAll('.btn-cargando').forEach(function (b) { b.classList.remove('btn-cargando'); });
    });

    // Google: lo llama el script de Google Identity Services (handleGoogleLogin en index.php)
    window.loginCargando = function (msg) { return window.zCargando ? window.zCargando.mostrar(msg) : function () {}; };
})();
