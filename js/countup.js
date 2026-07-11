// Animación de conteo para cifras KPI (.stat-value, .kpi-val).
// Solo anima valores puramente numéricos; el resto se deja intacto.
(function () {
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function animateCount(el, target, duration) {
        var start = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3); // ease-out
            el.textContent = Math.round(start + (target - start) * eased);
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = target;
            }
        }
        requestAnimationFrame(step);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var nodes = document.querySelectorAll('.stat-value, .kpi-val');
        nodes.forEach(function (el) {
            var text = el.textContent.trim();
            if (!/^\d+$/.test(text)) return; // solo números puros
            var target = parseInt(text, 10);
            if (reduceMotion) {
                el.textContent = target;
                return;
            }
            animateCount(el, target, 700);
        });
    });
})();
