<?php
// =============================================
// REPORTES — Mantenimientos y Equipos, PDF real (Dompdf) con branding configurable
// Archivo: pages/reportes.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db = getDB();

// ---- Estadísticas globales (tarjetas rápidas de la página interactiva) ----
$stats = [
    'equipos'    => $db->query("SELECT COUNT(*) FROM Equipos WHERE estado != 'Baja'")->fetchColumn(),
    'activos'    => $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Activo'")->fetchColumn(),
    'inactivos'  => $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Inactivo'")->fetchColumn(),
    'reparacion' => $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='En Reparacion'")->fetchColumn(),
    'areas'      => $db->query("SELECT COUNT(*) FROM Areas")->fetchColumn(),
    'mttos'      => $db->query("SELECT COUNT(*) FROM Mantenimientos")->fetchColumn(),
    'preventivos'=> $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE tipo_mantenimiento='Preventivo'")->fetchColumn(),
    'correctivos'=> $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE tipo_mantenimiento='Correctivo'")->fetchColumn(),
    'tareas_p'   => $db->query("SELECT COUNT(*) FROM Tareas WHERE estado='Pendiente'")->fetchColumn(),
    'tareas_r'   => $db->query("SELECT COUNT(*) FROM Tareas WHERE estado='Realizado'")->fetchColumn(),
];
$mesActual = date('Y-m');
$mttosMes  = $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE DATE_FORMAT(fecha_realizacion,'%Y-%m')='{$mesActual}'")->fetchColumn();

$generarPDF   = isset($_GET['pdf']);
$tipoReporte  = ($_GET['tipo'] ?? 'mantenimientos') === 'equipos' ? 'equipos' : 'mantenimientos';

// =============================================
// FILTROS — Mantenimientos (rango de fechas)
// =============================================
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : '';
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : '';
if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}
$hayPeriodo  = $desde !== '' && $hasta !== '';
$fechaInicio = $hayPeriodo ? $desde : null;
$fechaFin    = $hayPeriodo ? $hasta : null;
$periodoTexto = $hayPeriodo
    ? fechaES($fechaInicio) . ' — ' . fechaES($fechaFin)
    : 'Historial reciente (últimos 50 registros)';

if ($hayPeriodo) {
    $stmtMttos = $db->prepare("
        SELECT m.*, e.modelo, e.marca, a.nombre_area, u.nombre as tecnico
        FROM Mantenimientos m
        JOIN Equipos e ON e.numero_inventario = m.numero_inventario
        LEFT JOIN Areas a ON e.id_area = a.id_area
        LEFT JOIN Usuarios u ON u.id_usuario = m.id_tecnico
        WHERE m.fecha_realizacion BETWEEN ? AND ?
        ORDER BY m.fecha_realizacion DESC
    ");
    $stmtMttos->execute([$fechaInicio, $fechaFin]);
    $mantenimientos = $stmtMttos->fetchAll();
} else {
    $mantenimientos = $db->query("
        SELECT m.*, e.modelo, e.marca, a.nombre_area, u.nombre as tecnico
        FROM Mantenimientos m
        JOIN Equipos e ON e.numero_inventario = m.numero_inventario
        LEFT JOIN Areas a ON e.id_area = a.id_area
        LEFT JOIN Usuarios u ON u.id_usuario = m.id_tecnico
        ORDER BY m.fecha_realizacion DESC
        LIMIT 50
    ")->fetchAll();
}

// Equipos con próximo mantenimiento próximo o vencido
$urgentes = $db->query("
    SELECT e.numero_inventario, e.modelo, e.marca, a.nombre_area, m.proximo_mantenimiento,
           DATEDIFF(m.proximo_mantenimiento, CURDATE()) AS dias
    FROM Equipos e
    JOIN (SELECT numero_inventario, MAX(id_mantenimiento) as lid FROM Mantenimientos GROUP BY numero_inventario) lm ON e.numero_inventario = lm.numero_inventario
    JOIN Mantenimientos m ON m.id_mantenimiento = lm.lid
    LEFT JOIN Areas a ON e.id_area = a.id_area
    WHERE m.proximo_mantenimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY m.proximo_mantenimiento ASC
")->fetchAll();

// =============================================
// FILTROS — Equipos (área / estado)
// =============================================
$areasSelect       = $db->query("SELECT id_area, nombre_area FROM Areas ORDER BY nombre_area")->fetchAll();
$estadosValidosRep  = ['Activo', 'Inactivo', 'En Reparacion'];
$filtroAreaRep      = (int)($_GET['area'] ?? 0);
$filtroEstadoRep    = in_array($_GET['estado'] ?? '', $estadosValidosRep, true) ? $_GET['estado'] : '';
$hayFiltroEquipos   = $filtroAreaRep || $filtroEstadoRep;

$condsEqRep = ["e.estado != 'Baja'"]; $paramsEqRep = [];
if ($filtroAreaRep)   { $condsEqRep[] = "e.id_area=?"; $paramsEqRep[] = $filtroAreaRep; }
if ($filtroEstadoRep) { $condsEqRep[] = "e.estado=?";  $paramsEqRep[] = $filtroEstadoRep; }
$stmtEqRep = $db->prepare("SELECT e.*, a.nombre_area FROM Equipos e LEFT JOIN Areas a ON e.id_area=a.id_area WHERE " . implode(' AND ', $condsEqRep) . " ORDER BY e.numero_inventario");
$stmtEqRep->execute($paramsEqRep);
$equiposRep = $stmtEqRep->fetchAll();

$equiposRepStats = [
    'total'      => count($equiposRep),
    'activos'    => count(array_filter($equiposRep, fn($e) => $e['estado'] === 'Activo')),
    'inactivos'  => count(array_filter($equiposRep, fn($e) => $e['estado'] === 'Inactivo')),
    'reparacion' => count(array_filter($equiposRep, fn($e) => $e['estado'] === 'En Reparacion')),
];

$filtroEquiposTexto = 'Inventario completo';
if ($hayFiltroEquipos) {
    $partes = [];
    if ($filtroAreaRep) {
        foreach ($areasSelect as $a) if ((int)$a['id_area'] === $filtroAreaRep) $partes[] = $a['nombre_area'];
    }
    if ($filtroEstadoRep) $partes[] = $filtroEstadoRep === 'En Reparacion' ? 'En Reparación' : $filtroEstadoRep;
    $filtroEquiposTexto = implode(' · ', $partes);
}

// =============================================
// MODO PDF — Equipos: HTML + CSS puro (impresión / "Guardar como PDF" del navegador),
// mismo estilo institucional que las Bajas — sin Dompdf ni Composer.
// =============================================
if ($generarPDF && $tipoReporte === 'equipos'):
    require_once '../includes/pdf/branding.php'; // solo lectura de marca (logo/nombre), sin Dompdf
    $marca = getBranding();
    $logoPath = brandingLogoPath();
    $logoSrc = (!empty($marca['mostrar_logo']) && $logoPath && is_file($logoPath))
        ? '/uploads/marca/' . e($marca['logo'])
        : '/img/hytta.png';
    $folioEq = 'REP-EQUIPOS-' . date('Ymd');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png">
    <link rel="apple-touch-icon" href="/img/favicon/favicon-180.png">
    <link rel="shortcut icon" href="/img/favicon/favicon.ico">
    <title>Inventario de Equipos — <?= $folioEq ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', Arial, sans-serif; color: #000; background: #74777a; font-size: 11px; }

        .no-print { background: #1a1a2e; color: #fff; padding: 12px 24px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; position: sticky; top: 0; z-index: 10; }
        .no-print button { background: #5b21b6; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; font-family: 'DM Sans', sans-serif; font-weight: 600; }
        .no-print button:hover { background: #4c1d95; }
        .no-print a { color: #cbd5ff; font-size: 13px; text-decoration: none; }
        .no-print .folio-tag { margin-left: auto; color: #aab; font-size: 12px; font-family: monospace; }

        .hoja { max-width: 1000px; margin: 24px auto; background: #fff; padding: 18px; border: 1px solid #000; }

        table.fmt { width: 100%; border-collapse: collapse; }
        table.fmt td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }

        .fmt-logo-cell { width: 18%; text-align: center; padding: 6px; }
        .fmt-logo-cell img { max-width: 100%; max-height: 58px; }
        .fmt-title-cell { width: 62%; text-align: center; font-weight: 700; font-size: 13px; padding: 8px; }
        .fmt-code-cell { width: 20%; padding: 0; }
        .fmt-code-cell table { width: 100%; border-collapse: collapse; }
        .fmt-code-cell td { border: 1px solid #000; font-size: 9px; padding: 3px 5px; }
        .fmt-code-cell .lbl { color: #333; }
        .fmt-code-cell .val { font-weight: 700; text-align: right; }

        /* Barra "contador / fecha de conteo" */
        .fmt-conteo { border: 1px solid #000; border-top: none; padding: 6px 10px; font-size: 10px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; background: #f2f2f2; }
        .fmt-conteo b { font-weight: 700; }

        /* Hoja de recuento: tabla estilo hoja de cálculo, encabezado oscuro */
        table.fmt-hoja { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.fmt-hoja caption { background: #17253f; color: #fff; font-weight: 700; font-size: 12px; padding: 8px; text-transform: uppercase; letter-spacing: .04em; caption-side: top; }
        table.fmt-hoja thead th { background: #17253f; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; padding: 7px 5px; border: 1px solid #0a1526; text-align: center; }
        table.fmt-hoja tbody td { border-left: 1px solid #ccc; border-right: 1px solid #ccc; border-bottom: 1px dotted #999; padding: 5px; font-size: 10px; text-align: center; }
        table.fmt-hoja tbody td.izq { text-align: left; }
        table.fmt-hoja tbody tr:nth-child(even) td { background: #f7f9fc; }

        .fmt-nota { font-size: 8.5px; color: #333; margin-top: 14px; text-align: center; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .hoja { margin: 0; border: none; max-width: none; }
            @page { size: letter landscape; margin: 1cm; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">print</span> Imprimir / Guardar como PDF</button>
        <a href="/pages/reportes.php"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">arrow_back</span> Volver a Reportes</a>
        <span class="folio-tag">Folio: <?= $folioEq ?></span>
    </div>

    <div class="hoja">

        <table class="fmt">
            <tr>
                <td class="fmt-logo-cell"><img src="<?= $logoSrc ?>" alt="Logo"></td>
                <td class="fmt-title-cell">INVENTARIO DE EQUIPOS DE CÓMPUTO</td>
                <td class="fmt-code-cell">
                    <table>
                        <tr><td class="lbl">Folio:</td><td class="val"><?= e($folioEq) ?></td></tr>
                        <tr><td class="lbl">Fecha:</td><td class="val"><?= date('d/m/Y') ?></td></tr>
                        <tr><td class="lbl">Equipos:</td><td class="val"><?= $equiposRepStats['total'] ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <div class="fmt-conteo">
            <span><b>Generado por:</b> <?= e($_SESSION['usuario']['nombre']) ?></span>
            <span><b>Filtro:</b> <?= e($filtroEquiposTexto) ?></span>
            <span><b>Fecha de conteo:</b> <?= date('Y') ?>, <?= date('m') ?>, <?= date('d') ?> (Año, mes, día)</span>
        </div>

        <table class="fmt-hoja">
            <caption>Hoja de Recuento de Inventario</caption>
            <thead>
                <tr>
                    <th>No. Inventario</th>
                    <th>Serie</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Estado</th>
                    <th>Procesador</th>
                    <th>RAM</th>
                    <th>Disco</th>
                    <th>Área</th>
                    <th>Usuario Responsable</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($equiposRep)): ?>
                <tr><td colspan="10" style="padding:16px;color:#666">No hay equipos registrados con estos filtros.</td></tr>
                <?php else: foreach ($equiposRep as $eq): ?>
                <tr>
                    <td><?= e($eq['numero_inventario']) ?></td>
                    <td><?= e($eq['numero_serie'] ?: 'Sin Serie') ?></td>
                    <td><?= e($eq['marca'] ?: '—') ?></td>
                    <td class="izq"><?= e($eq['modelo']) ?></td>
                    <td><?= $eq['estado'] === 'En Reparacion' ? 'En Reparación' : e($eq['estado']) ?></td>
                    <td><?= e($eq['procesador'] ?: '—') ?></td>
                    <td><?= e($eq['ram'] ?: '—') ?></td>
                    <td><?= e($eq['disco'] ?: '—') ?></td>
                    <td class="izq"><?= e($eq['nombre_area'] ?? '—') ?></td>
                    <td class="izq"><?= e($eq['usuario_dueno'] ?: '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <p class="fmt-nota">Folio: <?= e($folioEq) ?> · <?= e($marca['nombre_empresa'] ?? SITE_NAME) ?> · Generado: <?= date('d/m/Y H:i') ?> por <?= e($_SESSION['usuario']['nombre']) ?></p>

    </div>

</body>
</html>
    <?php
    exit;
endif;

// =============================================
// MODO PDF — Mantenimientos: HTML + CSS puro (impresión / "Guardar como PDF" del
// navegador), mismo estilo que Equipos y Bajas — sin Dompdf ni Composer.
// =============================================
if ($generarPDF):
    require_once '../includes/pdf/branding.php'; // solo lectura de marca (logo/nombre), sin Dompdf
    $marca = getBranding();
    $logoPath = brandingLogoPath();
    $logoSrc = (!empty($marca['mostrar_logo']) && $logoPath && is_file($logoPath))
        ? '/uploads/marca/' . e($marca['logo'])
        : '/img/hytta.png';
    $folioM = 'REP-MTTO-' . date('Ymd');
    $totalM = count($mantenimientos);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png">
    <link rel="apple-touch-icon" href="/img/favicon/favicon-180.png">
    <link rel="shortcut icon" href="/img/favicon/favicon.ico">
    <title>Reporte de Mantenimientos — <?= $folioM ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', Arial, sans-serif; color: #000; background: #74777a; font-size: 11px; }

        .no-print { background: #1a1a2e; color: #fff; padding: 12px 24px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; position: sticky; top: 0; z-index: 10; }
        .no-print button { background: #5b21b6; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; font-family: 'DM Sans', sans-serif; font-weight: 600; }
        .no-print button:hover { background: #4c1d95; }
        .no-print a { color: #cbd5ff; font-size: 13px; text-decoration: none; }
        .no-print .folio-tag { margin-left: auto; color: #aab; font-size: 12px; font-family: monospace; }

        .hoja { max-width: 1000px; margin: 24px auto; background: #fff; padding: 18px; border: 1px solid #000; }

        table.fmt { width: 100%; border-collapse: collapse; }
        table.fmt td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }

        .fmt-logo-cell { width: 18%; text-align: center; padding: 6px; }
        .fmt-logo-cell img { max-width: 100%; max-height: 58px; }
        .fmt-title-cell { width: 62%; text-align: center; font-weight: 700; font-size: 13px; padding: 8px; }
        .fmt-code-cell { width: 20%; padding: 0; }
        .fmt-code-cell table { width: 100%; border-collapse: collapse; }
        .fmt-code-cell td { border: 1px solid #000; font-size: 9px; padding: 3px 5px; }
        .fmt-code-cell .lbl { color: #333; }
        .fmt-code-cell .val { font-weight: 700; text-align: right; }

        .fmt-conteo { border: 1px solid #000; border-top: none; padding: 6px 10px; font-size: 10px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; background: #f2f2f2; }
        .fmt-conteo b { font-weight: 700; }

        .fmt-stats { display: table; width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 14px; }
        .fmt-stats .celda { display: table-cell; border: 1px solid #000; text-align: center; padding: 10px 6px; }
        .fmt-stats .val { font-size: 20px; font-weight: 700; }
        .fmt-stats .lbl { font-size: 8.5px; color: #333; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }

        table.fmt-hoja { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.fmt-hoja caption { background: #17253f; color: #fff; font-weight: 700; font-size: 12px; padding: 8px; text-transform: uppercase; letter-spacing: .04em; caption-side: top; }
        table.fmt-hoja thead th { background: #17253f; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; padding: 7px 5px; border: 1px solid #0a1526; text-align: center; }
        table.fmt-hoja tbody td { border-left: 1px solid #ccc; border-right: 1px solid #ccc; border-bottom: 1px dotted #999; padding: 5px; font-size: 10px; text-align: center; }
        table.fmt-hoja tbody td.izq { text-align: left; }
        table.fmt-hoja tbody tr:nth-child(even) td { background: #f7f9fc; }

        .fmt-badge { display: inline-block; padding: 2px 7px; border-radius: 8px; font-size: 8.5px; font-weight: 700; }
        .b-blue   { background: #d0e8ff; color: #004085; }
        .b-orange { background: #ffe8c8; color: #7a3800; }
        .b-red    { background: #fddcdc; color: #721c24; }

        .fmt-nota { font-size: 8.5px; color: #333; margin-top: 14px; text-align: center; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .hoja { margin: 0; border: none; max-width: none; }
            @page { size: letter landscape; margin: 1cm; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">print</span> Imprimir / Guardar como PDF</button>
        <a href="/pages/reportes.php"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">arrow_back</span> Volver a Reportes</a>
        <span class="folio-tag">Folio: <?= $folioM ?></span>
    </div>

    <div class="hoja">

        <table class="fmt">
            <tr>
                <td class="fmt-logo-cell"><img src="<?= $logoSrc ?>" alt="Logo"></td>
                <td class="fmt-title-cell">REPORTE DE MANTENIMIENTOS</td>
                <td class="fmt-code-cell">
                    <table>
                        <tr><td class="lbl">Folio:</td><td class="val"><?= e($folioM) ?></td></tr>
                        <tr><td class="lbl">Fecha:</td><td class="val"><?= date('d/m/Y') ?></td></tr>
                        <tr><td class="lbl">Registros:</td><td class="val"><?= $totalM ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <div class="fmt-conteo">
            <span><b>Generado por:</b> <?= e($_SESSION['usuario']['nombre']) ?></span>
            <span><b>Periodo:</b> <?= e($periodoTexto) ?></span>
            <span><b>Fecha de generación:</b> <?= date('Y') ?>, <?= date('m') ?>, <?= date('d') ?> (Año, mes, día)</span>
        </div>

        <div class="fmt-stats">
            <div class="celda"><div class="val"><?= $totalM ?></div><div class="lbl">Total Mantenimientos</div></div>
            <div class="celda"><div class="val" style="color:#004085"><?= count(array_filter($mantenimientos, fn($m) => $m['tipo_mantenimiento'] === 'Preventivo')) ?></div><div class="lbl">Preventivos</div></div>
            <div class="celda"><div class="val" style="color:#7a3800"><?= count(array_filter($mantenimientos, fn($m) => $m['tipo_mantenimiento'] === 'Correctivo')) ?></div><div class="lbl">Correctivos</div></div>
            <div class="celda"><div class="val" style="color:#1a7f37"><?= count(array_filter($mantenimientos, fn($m) => $m['estado'] === 'Completado')) ?></div><div class="lbl">Completados</div></div>
        </div>

        <?php if (!empty($urgentes)): ?>
        <table class="fmt-hoja">
            <caption>Equipos con Mantenimiento Próximo o Vencido (30 días)</caption>
            <thead>
                <tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Próx. Mantenimiento</th><th>Estado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($urgentes as $u): $dias = (int)$u['dias'];
                    $tono = $dias < 0 ? 'b-red' : ($dias <= 7 ? 'b-orange' : 'b-blue');
                    $txt  = $dias < 0 ? 'Vencido ' . abs($dias) . 'd' : ($dias <= 7 ? 'Urgente (' . $dias . 'd)' : 'Próximo (' . $dias . 'd)'); ?>
                <tr>
                    <td><?= e($u['numero_inventario']) ?></td>
                    <td class="izq"><?= e($u['modelo']) ?><?= $u['marca'] ? ' — ' . e($u['marca']) : '' ?></td>
                    <td class="izq"><?= e($u['nombre_area'] ?? '—') ?></td>
                    <td><?= fechaES($u['proximo_mantenimiento']) ?></td>
                    <td><span class="fmt-badge <?= $tono ?>"><?= e($txt) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <table class="fmt-hoja">
            <caption>Historial de Mantenimientos — <?= e($periodoTexto) ?></caption>
            <thead>
                <tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Tipo</th><th>Fecha Inicio</th><th>Fecha Entrega</th><th>Próx. Cita</th><th>Técnico</th></tr>
            </thead>
            <tbody>
                <?php if (empty($mantenimientos)): ?>
                <tr><td colspan="8" style="padding:16px;color:#666">No hay mantenimientos registrados en este periodo.</td></tr>
                <?php else: foreach ($mantenimientos as $m):
                    $tono = $m['tipo_mantenimiento'] === 'Preventivo' ? 'b-blue' : 'b-orange'; ?>
                <tr>
                    <td><?= e($m['numero_inventario']) ?></td>
                    <td class="izq"><?= e($m['modelo']) ?><?= $m['marca'] ? ' — ' . e($m['marca']) : '' ?></td>
                    <td class="izq"><?= e($m['nombre_area'] ?? '—') ?></td>
                    <td><span class="fmt-badge <?= $tono ?>"><?= e($m['tipo_mantenimiento']) ?></span></td>
                    <td><?= fechaES($m['fecha_realizacion']) ?></td>
                    <td><?= fechaES($m['fecha_entrega']) ?></td>
                    <td><?= fechaES($m['proximo_mantenimiento']) ?></td>
                    <td class="izq"><?= e($m['tecnico'] ?? '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <p class="fmt-nota">Folio: <?= e($folioM) ?> · <?= e($marca['nombre_empresa'] ?? SITE_NAME) ?> · Generado: <?= date('d/m/Y H:i') ?> por <?= e($_SESSION['usuario']['nombre']) ?></p>

    </div>

</body>
</html>
    <?php
    exit;
endif;
// Fin modo PDF
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png">
    <link rel="apple-touch-icon" href="/img/favicon/favicon-180.png">
    <link rel="shortcut icon" href="/img/favicon/favicon.ico">
    <title>Reportes — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="/css/estilos.css?v=12">
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title"><span class="material-symbols-outlined mi-md">bar_chart</span> Reportes</div>
                <div class="page-subtitle">Vista previa y descarga de reportes en PDF — Mantenimientos y Equipos</div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('modalPeriodoPDF')"><span class="material-symbols-outlined mi-sm">download</span> Generar Reporte PDF</button>
            </div>
        </div>

        <!-- Stats rápidas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Equipos</div>
                <div class="stat-value accent"><?= $stats['equipos'] ?></div>
                <div class="stat-meta"><?= $stats['activos'] ?> activos · <?= $stats['reparacion'] ?> en reparación</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Mantenimientos este mes</div>
                <div class="stat-value warning"><?= $mttosMes ?></div>
                <div class="stat-meta"><?= $stats['mttos'] ?> en total histórico</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Preventivos / Correctivos</div>
                <div class="stat-value success"><?= $stats['preventivos'] ?> / <?= $stats['correctivos'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Tareas Realizadas</div>
                <div class="stat-value"><?= $stats['tareas_r'] ?> <span style="font-size:18px;color:var(--text-muted)">/ <?= $stats['tareas_r'] + $stats['tareas_p'] ?></span></div>
            </div>
        </div>

        <!-- Alertas de mantenimientos urgentes -->
        <?php if (!empty($urgentes)): ?>
        <div class="card" style="margin-bottom:24px">
            <div class="card-header">
                <div class="card-title"><span class="material-symbols-outlined mi-md">warning</span> Equipos con Mantenimiento Próximo o Vencido</div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Próx. Mantenimiento</th><th>Días</th></tr></thead>
                    <tbody>
                    <?php foreach ($urgentes as $u): ?>
                    <tr>
                        <td class="text-mono"><span class="text-clip" title="<?= e($u['numero_inventario']) ?>" style="max-width:140px"><?= e($u['numero_inventario']) ?></span></td>
                        <td><span class="text-clip" title="<?= e($u['modelo']) ?>" style="max-width:180px"><?= e($u['modelo']) ?></span></td>
                        <td class="text-secondary"><span class="text-clip" title="<?= e($u['nombre_area'] ?? '') ?>"><?= e($u['nombre_area'] ?? '—') ?></span></td>
                        <td><?= fechaES($u['proximo_mantenimiento']) ?></td>
                        <td>
                            <?php $dias = (int)$u['dias']; ?>
                            <?php if ($dias < 0): ?>
                                <span class="badge-estado badge-no-realizado">Vencido <?= abs($dias) ?>d</span>
                            <?php elseif ($dias <= 7): ?>
                                <span class="badge-estado badge-reparacion"><span class="material-symbols-outlined mi-sm">warning</span> <?= $dias ?>d</span>
                            <?php else: ?>
                                <span class="badge-estado badge-proceso"><span class="material-symbols-outlined mi-sm">calendar_month</span> <?= $dias ?>d</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vista previa — Mantenimientos -->
        <div class="card" style="margin-bottom:24px">
            <div class="card-header">
                <div class="card-title"><span class="material-symbols-outlined mi-md">checklist</span> Vista Previa — Últimos Mantenimientos</div>
            </div>
            <div class="table-wrapper">
                <?php if (empty($mantenimientos)): ?>
                    <div class="empty-state"><span class="empty-icon material-symbols-outlined">checklist</span><p>Sin registros aún.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Inventario</th><th>Modelo</th><th>Área</th><th>Tipo</th><th>Fecha</th><th>Próx. Cita</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($mantenimientos, 0, 50) as $m): ?>
                    <tr>
                        <td class="text-mono"><span class="text-clip" title="<?= e($m['numero_inventario']) ?>" style="max-width:140px"><?= e($m['numero_inventario']) ?></span></td>
                        <td><span class="text-clip" title="<?= e($m['modelo']) ?>" style="max-width:180px"><?= e($m['modelo']) ?></span></td>
                        <td class="text-secondary"><span class="text-clip" title="<?= e($m['nombre_area'] ?? '') ?>"><?= e($m['nombre_area'] ?? '—') ?></span></td>
                        <td>
                            <?= $m['tipo_mantenimiento'] === 'Preventivo'
                                ? '<span class="badge-estado badge-proceso"><span class="material-symbols-outlined mi-sm">shield</span> Preventivo</span>'
                                : '<span class="badge-estado badge-reparacion"><span class="material-symbols-outlined mi-sm">handyman</span> Correctivo</span>' ?>
                        </td>
                        <td class="text-secondary"><?= fechaES($m['fecha_realizacion']) ?></td>
                        <td><?php
                            if ($m['proximo_mantenimiento']) {
                                $d = (int)((strtotime($m['proximo_mantenimiento']) - time()) / 86400);
                                $c = $d < 0 ? 'danger' : ($d <= 14 ? 'warning' : 'success');
                                echo "<span class=\"text-{$c}\">" . fechaES($m['proximo_mantenimiento']) . "</span>";
                            } else echo '—';
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vista previa — Equipos -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="material-symbols-outlined mi-md">computer</span> Vista Previa — Inventario de Equipos</div>
                <span class="text-muted" style="font-size:13px"><?= $equiposRepStats['total'] ?> equipos</span>
            </div>
            <div class="table-wrapper">
                <?php if (empty($equiposRep)): ?>
                    <div class="empty-state"><span class="empty-icon material-symbols-outlined">computer</span><p>Sin equipos registrados.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Inventario</th><th>Modelo</th><th>Área</th><th>Dueño</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($equiposRep, 0, 50) as $eq): ?>
                    <tr>
                        <td class="text-mono"><span class="text-clip" title="<?= e($eq['numero_inventario']) ?>" style="max-width:140px"><?= e($eq['numero_inventario']) ?></span></td>
                        <td><span class="text-clip" title="<?= e($eq['modelo']) ?>" style="max-width:180px"><?= e($eq['modelo']) ?></span></td>
                        <td class="text-secondary"><span class="text-clip" title="<?= e($eq['nombre_area'] ?? '') ?>"><?= e($eq['nombre_area'] ?? '—') ?></span></td>
                        <td class="text-secondary"><span class="text-clip" title="<?= e($eq['usuario_dueno'] ?? '') ?>" style="max-width:140px"><?= e($eq['usuario_dueno'] ?: '—') ?></span></td>
                        <td><?= badgeEstado($eq['estado']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- Modal: Generar Reporte PDF -->
<div class="modal-overlay" id="modalPeriodoPDF">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><span class="material-symbols-outlined mi-md">download</span> Generar Reporte PDF</div>
            <button class="modal-close" onclick="closeModal('modalPeriodoPDF')"><span class="material-symbols-outlined mi-sm">close</span></button>
        </div>
        <div class="modal-body">
            <form method="GET" action="/pages/reportes.php" target="_blank" id="formReportePDF">
                <input type="hidden" name="pdf" value="1">

                <div class="form-group">
                    <label>Tipo de Reporte</label>
                    <select name="tipo" id="selTipoReporte" onchange="cambiarTipoReporte()">
                        <option value="mantenimientos">Mantenimientos</option>
                        <option value="equipos">Equipos</option>
                    </select>
                </div>

                <div id="camposMantenimientos">
                    <p class="page-subtitle" style="margin:-4px 0 16px">Elige las fechas exactas (día, mes y año) que quieres incluir en el PDF.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Desde</label>
                            <input type="date" id="fReporteDesde" name="desde" value="<?= e(date('Y-m-d', strtotime('-5 months'))) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Hasta</label>
                            <input type="date" id="fReporteHasta" name="hasta" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                    </div>
                </div>

                <div id="camposEquipos" style="display:none">
                    <p class="page-subtitle" style="margin:-4px 0 16px">Filtra opcionalmente por área o estado (déjalo en blanco para el inventario completo).</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Área</label>
                            <select name="area">
                                <option value="">Todas las áreas</option>
                                <?php foreach ($areasSelect as $a): ?>
                                <option value="<?= $a['id_area'] ?>"><?= e($a['nombre_area']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="estado">
                                <option value="">Todos los estados</option>
                                <option value="Activo">Activo</option>
                                <option value="Inactivo">Inactivo</option>
                                <option value="En Reparacion">En Reparación</option>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full"><span class="material-symbols-outlined mi-sm">download</span> Descargar PDF</button>
                <a href="/pages/reportes.php?pdf=1&tipo=mantenimientos" target="_blank" class="btn btn-ghost btn-full" id="linkTodoHistorial" style="margin-top:10px">Descargar todo el historial reciente</a>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

function cambiarTipoReporte() {
    var esM = document.getElementById('selTipoReporte').value === 'mantenimientos';
    document.getElementById('camposMantenimientos').style.display = esM ? '' : 'none';
    document.getElementById('camposEquipos').style.display = esM ? 'none' : '';
    document.getElementById('fReporteDesde').required = esM;
    document.getElementById('fReporteHasta').required = esM;
    var linkTodo = document.getElementById('linkTodoHistorial');
    linkTodo.hidden = !esM;
}

// El campo "Hasta" viene precargado con la fecha de hoy. Si el usuario solo cambia
// "Desde" (p.ej. para ver un único día) y no toca "Hasta", el rango terminaba siendo
// "desde esa fecha hasta hoy" y el reporte traía de más. Mientras el usuario no edite
// "Hasta" manualmente, la mantenemos igual a "Desde" para que un solo cambio de fecha
// filtre exactamente ese día.
(function () {
    var fDesde = document.getElementById('fReporteDesde');
    var fHasta = document.getElementById('fReporteHasta');
    if (!fDesde || !fHasta) return;
    var hastaTocada = false;
    fHasta.addEventListener('input', function () { hastaTocada = true; });
    fDesde.addEventListener('input', function () {
        if (!hastaTocada) fHasta.value = fDesde.value;
    });
})();
</script>
</body>
</html>
