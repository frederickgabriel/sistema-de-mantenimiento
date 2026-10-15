// Pantallas de carga (css/cargando.css).
//  - Splash de bienvenida (#zSplash, solo lo pinta el servidor justo después de iniciar sesión): se cierra cuando
//    la página y las fuentes de íconos están listas, con un mínimo para que no parpadee.
//  - Barra superior + velo con cubo 3D al navegar a otra página (el velo solo aparece si tarda).
//  - API global para trabajos largos:  const fin = zCargando.mostrar('Generando documento…'); ... fin();
(function () {
    'use strict';
    var reducir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var doc = document;

    // ---------- Splash ----------
    var splash = doc.getElementById('zSplash');
    if (splash) {
        var txt = doc.getElementById('zSplashTxt'), barra = doc.getElementById('zSplashBarra');
        var frases = ['Preparando tu espacio de trabajo', 'Cargando equipos y departamentos', 'Sincronizando mantenimientos', 'Revisando tareas pendientes', 'Todo listo'];
        var MIN = reducir ? 0 : 1500, MAX = 7000, inicio = Date.now(), cerrado = false, progreso = 0.04, fase = 0;

        var avance = setInterval(function () {
            progreso = Math.min(0.9, progreso + (0.9 - progreso) * 0.12);
            barra.style.setProperty('--p', progreso.toFixed(3));
        }, 140);
        var rota = setInterval(function () {
            if (fase < frases.length - 2) { fase++; cambiarTexto(frases[fase]); }
        }, 520);

        function cambiarTexto(t) {
            txt.textContent = t; txt.style.animation = 'none'; void txt.offsetWidth; txt.style.animation = '';
        }
        function cerrar() {
            if (cerrado) return; cerrado = true;
            clearInterval(avance); clearInterval(rota);
            barra.style.setProperty('--p', 1); cambiarTexto(frases[frases.length - 1]);
            setTimeout(function () {
                splash.classList.add('salir');
                setTimeout(function () { splash.remove(); }, reducir ? 20 : 520);
            }, reducir ? 0 : 280);
        }
        var cargada = new Promise(function (ok) {
            if (doc.readyState === 'complete') ok(); else window.addEventListener('load', ok, { once: true });
        });
        var fuentes = (doc.fonts && doc.fonts.ready) ? doc.fonts.ready : Promise.resolve();
        Promise.all([cargada, fuentes]).then(function () {
            setTimeout(cerrar, Math.max(0, MIN - (Date.now() - inicio)));
        });
        setTimeout(cerrar, MAX); // tope de seguridad
    }

    // ---------- Velo de carga reutilizable ----------
    var tpl = doc.getElementById('zlEscena');
    var velo = null, vTxt = null, cuenta = 0;
    function crearVelo() {
        if (velo || !tpl) return;
        velo = doc.createElement('div');
        velo.className = 'zl-nav'; velo.setAttribute('role', 'status'); velo.setAttribute('aria-live', 'polite');
        velo.appendChild(tpl.content.cloneNode(true));
        velo.querySelector('.zl-escena').classList.add('zl-mini');
        vTxt = doc.createElement('div'); vTxt.className = 'zl-texto'; velo.appendChild(vTxt);
        doc.body.appendChild(velo);
    }
    function mostrarVelo(msg) {
        crearVelo(); if (!velo) return function () {};
        cuenta++; vTxt.textContent = msg || 'Cargando…';
        requestAnimationFrame(function () { velo.classList.add('on'); });
        var hecho = false;
        return function () { if (hecho) return; hecho = true; cuenta = Math.max(0, cuenta - 1); if (!cuenta) velo.classList.remove('on'); };
    }
    window.zCargando = { mostrar: mostrarVelo };

    // ---------- Barra superior al navegar ----------
    var top = doc.createElement('div'); top.className = 'zl-top'; top.setAttribute('aria-hidden', 'true');
    doc.body.appendChild(top);
    var tVelo = 0, tTope = 0, finVelo = null;

    function arrancarNavegacion() {
        top.classList.remove('fin'); void top.offsetWidth; top.classList.add('on');
        clearTimeout(tVelo);
        tVelo = setTimeout(function () { if (!finVelo) finVelo = mostrarVelo('Cargando…'); }, 450); // solo si tarda
        clearTimeout(tTope);
        tTope = setTimeout(detenerNavegacion, 9000); // p. ej. un enlace que descarga un archivo y no cambia de página
    }
    function detenerNavegacion() {
        clearTimeout(tVelo); clearTimeout(tTope);
        top.classList.remove('on'); top.classList.add('fin');
        if (finVelo) { finVelo(); finVelo = null; }
    }
    window.addEventListener('pageshow', detenerNavegacion); // volver con "Atrás" (bfcache)

    doc.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
        if (a.hasAttribute('data-vista') || a.hasAttribute('data-descarga') || a.hasAttribute('data-no-carga')) return;
        var u; try { u = new URL(a.href, location.href); } catch (err) { return; }
        if (u.origin !== location.origin || !/^https?:$/.test(u.protocol)) return;
        if (u.pathname === location.pathname && u.search === location.search) return; // ancla o misma página
        if (/\.(pdf|xlsx?|csv|zip|docx?)$/i.test(u.pathname)) return;
        arrancarNavegacion();
    });
})();
