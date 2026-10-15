<?php
// =============================================
// EXTRAS › CUENTAS — Solo administradores
// Archivo: pages/cuentas.php
// Asignar correos y contraseñas a personas fuera del programa. Todo se guarda y se refresca por AJAX
// (actions/cuentas.php + js/cuentas.js): la página no se recarga y los datos se sincronizan en tiempo real.
// =============================================
require_once '../includes/config.php';
requireAccesoCuentas();

$cuentasInicial = cuentasLista(); // ojo: sidebar.php usa $inicial para la letra del avatar
$dominios = ['populares' => CUENTAS_DOMINIOS_POPULARES, 'empresa' => CUENTAS_DOMINIOS_EMPRESA];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png"><link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png"><link rel="apple-touch-icon" href="/img/favicon/favicon-180.png"><link rel="shortcut icon" href="/img/favicon/favicon.ico"><title>Cuentas — <?= SITE_NAME ?></title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block"><?php include __DIR__ . '/../includes/tema_inicial.php'; ?><link rel="stylesheet" href="/css/estilos.css?v=32"><link rel="stylesheet" href="/css/cuentas.css?v=3"></head>
<body><div class="app-layout"><?php include '../includes/sidebar.php'; ?>
<main class="main-content">

<div class="page-header cu-header">
    <div class="cu-header-text">
        <div class="page-title"><span class="material-symbols-outlined mi-md">key</span> Cuentas</div>
        <div class="page-subtitle">Correos y contraseñas asignados a personas fuera del programa. Las contraseñas se guardan cifradas.</div>
        <div class="cu-live" id="cuLive" data-estado="conectando" role="status" aria-live="polite">
            <span class="cu-live-dot"></span><span id="cuLiveTxt">Conectando…</span>
        </div>
    </div>
    <div class="cu-vault" aria-hidden="true">
        <div class="cu-vault-stack">
            <span class="cu-vault-card c3"></span><span class="cu-vault-card c2"></span>
            <span class="cu-vault-card c1"><span class="material-symbols-outlined">lock</span></span>
        </div>
    </div>
</div>

<section class="cu-stats" id="cuStats" aria-label="Resumen"></section>

<div class="cu-toolbar">
    <label class="cu-search">
        <span class="material-symbols-outlined">search</span>
        <input type="search" id="cuBuscar" placeholder="Buscar por correo, persona o nota" autocomplete="off" aria-label="Buscar cuentas">
    </label>
    <div class="cu-chips" id="cuFiltros" role="group" aria-label="Filtrar por proveedor"></div>
    <button type="button" class="btn btn-primary cu-nueva" id="cuNueva">
        <span class="material-symbols-outlined mi-sm">add</span> Nueva cuenta
    </button>
</div>

<div class="cu-grid" id="cuGrid" aria-live="polite"></div>
<div class="cu-vacio" id="cuVacio" hidden></div>

</main></div>

<!-- Modal: crear / editar cuenta -->
<div class="modal-overlay" id="cuModal" aria-hidden="true">
    <div class="modal-box cu-modal" role="dialog" aria-modal="true" aria-labelledby="cuModalTitulo">
        <form id="cuForm" data-ajax="off" novalidate autocomplete="off">
            <div class="cu-modal-head">
                <div class="cu-modal-ico"><span class="material-symbols-outlined" id="cuModalIco">person_add</span></div>
                <div>
                    <div class="cu-modal-titulo" id="cuModalTitulo">Nueva cuenta</div>
                    <div class="cu-modal-sub" id="cuModalSub">Asigna un correo y una contraseña a una persona.</div>
                </div>
                <button type="button" class="cu-x" id="cuCerrar" aria-label="Cerrar"><span class="material-symbols-outlined">close</span></button>
            </div>

            <div class="cu-campo">
                <label for="cuCorreo">Correo</label>
                <div class="cu-input">
                    <span class="material-symbols-outlined cu-input-ico">alternate_email</span>
                    <input type="email" id="cuCorreo" inputmode="email" autocapitalize="off" spellcheck="false" placeholder="nombre@gmail.com" maxlength="160" required>
                    <span class="cu-estado" id="cuCorreoEstado" aria-hidden="true"></span>
                </div>
                <div class="cu-ayuda" id="cuCorreoAyuda">Gmail, Outlook, Hotmail, Yahoo, iCloud o de la empresa.</div>
                <div class="cu-dominios" id="cuDominios" role="group" aria-label="Elegir proveedor"></div>
            </div>

            <div class="cu-campo">
                <label for="cuPassword">Contraseña</label>
                <div class="cu-input">
                    <span class="material-symbols-outlined cu-input-ico">lock</span>
                    <input type="password" id="cuPassword" placeholder="Mínimo 8 caracteres" maxlength="120" autocomplete="new-password">
                    <button type="button" class="cu-mini" id="cuVerPass" aria-label="Mostrar contraseña" title="Mostrar / ocultar"><span class="material-symbols-outlined">visibility</span></button>
                    <button type="button" class="cu-mini" id="cuGenerar" aria-label="Generar contraseña segura" title="Generar contraseña segura"><span class="material-symbols-outlined">auto_fix_high</span></button>
                </div>
                <div class="cu-fuerza" id="cuFuerza" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                <div class="cu-ayuda" id="cuPassAyuda">Usa letras, números y símbolos.</div>
            </div>

            <div class="cu-fila">
                <div class="cu-campo">
                    <label for="cuAsignado">Asignada a</label>
                    <div class="cu-input">
                        <span class="material-symbols-outlined cu-input-ico">person</span>
                        <input type="text" id="cuAsignado" placeholder="Nombre de la persona" maxlength="120" required>
                    </div>
                </div>
                <div class="cu-campo">
                    <label for="cuNotas">Nota <small>(opcional)</small></label>
                    <div class="cu-input">
                        <span class="material-symbols-outlined cu-input-ico">notes</span>
                        <input type="text" id="cuNotas" placeholder="Para qué se usa" maxlength="300">
                    </div>
                </div>
            </div>

            <div class="cu-error" id="cuFormError" role="alert" hidden></div>

            <div class="cu-acciones">
                <button type="button" class="btn btn-ghost" id="cuCancelar">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="cuGuardar">
                    <span class="material-symbols-outlined mi-sm cu-spin" hidden>progress_activity</span>
                    <span id="cuGuardarTxt">Guardar cuenta</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.CUENTAS_INICIAL = <?= json_encode($cuentasInicial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.CUENTAS_DOMINIOS = <?= json_encode($dominios, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/cuentas.js?v=3" defer></script>
</body></html>
