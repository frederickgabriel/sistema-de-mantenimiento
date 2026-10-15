<?php
// =============================================
// Marcado de las pantallas de carga (css/cargando.css, js/cargando.js). Lo usan includes/sidebar.php e index.php.
// =============================================
// Escena 3D de las pantallas de carga (css/cargando.css): cubo con íconos del sistema + anillos orbitales.
if (!function_exists('escenaCarga')) {
    function escenaCarga(): string {
        $caras = '';
        foreach (['computer', 'build', 'checklist', 'calendar_month', 'monitoring', 'verified_user'] as $ic) {
            $caras .= '<div class="zl-cara"><span class="material-symbols-outlined">' . $ic . '</span></div>';
        }
        return '<div class="zl-escena" aria-hidden="true"><div class="zl-sombra"></div><div class="zl-mundo">'
             . '<div class="zl-anillo zl-a2"></div><div class="zl-anillo zl-a1"></div>'
             . '<div class="zl-flota"><div class="zl-cubo">' . $caras . '</div><div class="zl-nucleo"></div></div>'
             . '</div></div>';
    }
}
