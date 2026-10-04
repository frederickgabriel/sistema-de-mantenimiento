<?php
// Aplica el tema (claro/oscuro) guardado ANTES de que se pinte la página.
// Se incluye en el <head>, justo antes de estilos.css: si se aplicara ya dentro del <body>
// (como antes, desde sidebar.php), el navegador alcanzaba a calcular los estilos claros y las
// transiciones de color de body/tarjetas/botones animaban el cambio a oscuro: un parpadeo.
?>
<script>
(function () {
    try {
        var guardado = localStorage.getItem('theme');
        var tema = guardado || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (tema === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    } catch (e) {}
})();
</script>
