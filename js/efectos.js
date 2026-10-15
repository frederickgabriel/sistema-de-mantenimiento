// Efectos globales (css/efectos.css): inclinación 3D de las tarjetas con el puntero y entrada escalonada
// al abrir cada página. Delegado en document, así sigue funcionando cuando ui.js refresca una zona sin recargar.
(function () {
    'use strict';
    var reducir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var root = document.documentElement;

    // ---------- Entrada escalonada (solo en la carga de la página) ----------
    function indexar(lista) { for (var i = 0; i < lista.length; i++) lista[i].style.setProperty('--efx-i', Math.min(i, 10)); }
    function entrada() {
        if (reducir) return;
        ['.dash-stats', '.stats-grid', '.kpi-row', '.employee-grid'].forEach(function (s) {
            document.querySelectorAll(s).forEach(function (g) { indexar(g.children); });
        });
        var main = document.querySelector('.main-content');
        if (main) indexar([].filter.call(main.children, function (n) { return n.matches('.card, .ch-card, .marbete-card, .baja-card'); }));
        document.querySelectorAll('tbody').forEach(function (tb) { indexar(tb.rows); });
        root.classList.add('efx-carga');
        setTimeout(function () { root.classList.remove('efx-carga'); }, 1100);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', entrada); else entrada();

    // ---------- Inclinación 3D (puntero fino, 1:1 con el cursor) ----------
    if (reducir || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    var SEL = '.dash-stat, .stat-card, .kpi-box, .employee-card';
    var MAX = 6, raf = 0, card = null, px = 0, py = 0;

    document.addEventListener('pointermove', function (e) {
        var c = e.target.closest ? e.target.closest(SEL) : null;
        if (!c) return;
        card = c; px = e.clientX; py = e.clientY;
        if (raf) return;
        raf = requestAnimationFrame(function () {
            raf = 0;
            var r = card.getBoundingClientRect();
            var x = (px - r.left) / r.width, y = (py - r.top) / r.height;
            card.classList.add('efx-mueve');
            card.style.setProperty('--ry', ((x - 0.5) * 2 * MAX).toFixed(2) + 'deg');
            card.style.setProperty('--rx', ((0.5 - y) * 2 * MAX).toFixed(2) + 'deg');
            card.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
            card.style.setProperty('--my', (y * 100).toFixed(1) + '%');
        });
    }, { passive: true });

    document.addEventListener('pointerout', function (e) {
        var c = e.target.closest ? e.target.closest(SEL) : null;
        if (!c || c.contains(e.relatedTarget)) return;
        c.classList.remove('efx-mueve');
        c.style.setProperty('--rx', '0deg'); c.style.setProperty('--ry', '0deg');
    });
})();
