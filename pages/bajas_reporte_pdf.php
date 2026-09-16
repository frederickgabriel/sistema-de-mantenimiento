<?php
// =============================================
// REPORTE GENERAL DE BAJAS DE EQUIPO
// Archivo: pages/bajas_reporte_pdf.php
// Documento consolidado con todos los equipos dados de baja.
// HTML + CSS puro (impresión / "Guardar como PDF" del navegador) — sin librerías de
// PDF ni Composer, para que funcione tal cual en cualquier hosting compartido.
// =============================================
require_once '../includes/config.php';
requireLogin();
require_once '../includes/pdf/branding.php'; // solo lectura de marca (logo/nombre), sin Dompdf

$db = getDB();

$filtroValidacion = $_GET['validacion'] ?? '';
$validos = ['Pendiente', 'Validado', 'Rechazado'];
$where = '';
$params = [];
if (in_array($filtroValidacion, $validos)) {
    $where = 'WHERE b.estado_validacion = ?';
    $params = [$filtroValidacion];
}

$stmt = $db->prepare("
    SELECT b.*, e.modelo, e.marca, e.numero_serie, a.nombre_area, u.nombre as tecnico_nombre
    FROM Bajas b
    JOIN Equipos e ON e.numero_inventario = b.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = b.id_tecnico_responsable
    {$where}
    ORDER BY b.fecha_baja DESC
");
$stmt->execute($params);
$bajas = $stmt->fetchAll();

$totalBajas = count($bajas);
$valorTotal = array_sum(array_column($bajas, 'valor_actual_estimado'));
$folio = 'REP-BAJAS-' . date('Ymd');

// ---- Marca: logo del programa (si aún no se configuró uno en Marca / Reportes,
// se usa el logo real del sistema como respaldo, para que nunca salga vacío) ----
$marca = getBranding();
$logoPath = brandingLogoPath();
$logoSrc = (!empty($marca['mostrar_logo']) && $logoPath && is_file($logoPath))
    ? '/uploads/marca/' . e($marca['logo'])
    : '/img/hytta.png';
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
    <title>Reporte General de Bajas — <?= $folio ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', Arial, sans-serif; color: #000; background: #74777a; font-size: 11px; }

        .no-print { background: #1a1a2e; color: #fff; padding: 12px 24px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; position: sticky; top: 0; z-index: 10; }
        .no-print button { background: #5b21b6; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; font-family: 'DM Sans', sans-serif; font-weight: 600; }
        .no-print button:hover { background: #4c1d95; }
        .no-print a { color: #cbd5ff; font-size: 13px; text-decoration: none; }
        .no-print select { padding: 6px 10px; border-radius: 6px; border: none; font-family: 'DM Sans', sans-serif; }
        .no-print .folio-tag { margin-left: auto; color: #aab; font-size: 12px; font-family: monospace; }

        /* ---- Hoja tamaño carta, con el aspecto de un formato institucional ---- */
        .hoja { max-width: 800px; margin: 24px auto; background: #fff; padding: 18px; border: 1px solid #000; }

        table.fmt { width: 100%; border-collapse: collapse; }
        table.fmt td, table.fmt th { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }

        /* Encabezado: logo | título | folio */
        .fmt-header td { border: 1px solid #000; }
        .fmt-logo-cell { width: 22%; text-align: center; padding: 6px; }
        .fmt-logo-cell img { max-width: 100%; max-height: 62px; }
        .fmt-title-cell { width: 56%; text-align: center; font-weight: 700; font-size: 13px; line-height: 1.35; padding: 8px; }
        .fmt-code-cell { width: 22%; padding: 0; }
        .fmt-code-cell table { width: 100%; border-collapse: collapse; }
        .fmt-code-cell td { border: 1px solid #000; font-size: 9px; padding: 3px 5px; }
        .fmt-code-cell .lbl { color: #333; }
        .fmt-code-cell .val { font-weight: 700; text-align: right; }

        /* Fila de fecha DIA/MES/AÑO */
        .fmt-fecha td { border: 1px solid #000; text-align: center; font-weight: 700; font-size: 12px; padding: 5px; }
        .fmt-fecha .fmt-fecha-label { background: #d9d9d9; font-weight: 700; text-align: left; font-size: 10px; }
        .fmt-fecha .fmt-fecha-sub { background: #f2f2f2; font-weight: 400; font-size: 8px; padding: 2px; }

        /* Secciones con barra de título gris */
        .fmt-section-title { background: #bfbfbf; text-align: center; font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em; padding: 5px; }
        .fmt-dato-label { width: 26%; font-weight: 400; font-size: 10px; background: #f7f7f7; }
        .fmt-dato-valor { font-weight: 700; text-align: center; font-size: 11px; }

        /* Tabla de bienes */
        table.fmt-items { width: 100%; border-collapse: collapse; margin-top: -1px; }
        table.fmt-items th { background: #d9d9d9; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; padding: 5px 4px; border: 1px solid #000; text-align: center; }
        table.fmt-items td { border: 1px solid #000; padding: 4px; font-size: 10px; text-align: center; }
        table.fmt-items td.izq { text-align: left; }
        .fmt-empty-rows td { height: 20px; }

        /* Observaciones: caja en blanco para anotar a mano */
        .fmt-obs-box { border: 1px solid #000; border-top: none; min-height: 70px; padding: 6px; font-size: 10px; }

        /* Firmas */
        table.fmt-firmas { width: 100%; border-collapse: collapse; margin-top: 26px; }
        table.fmt-firmas td { border: none; width: 50%; text-align: center; padding: 0 20px; font-size: 10px; font-weight: 700; }
        .fmt-firma-linea { border-top: 1px solid #000; margin: 0 10px 4px; }

        .fmt-nota { font-size: 8.5px; color: #333; margin-top: 14px; text-align: center; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .hoja { margin: 0; border: none; max-width: none; box-shadow: none; }
            @page { size: letter portrait; margin: 1cm; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">print</span> Imprimir / Guardar como PDF</button>
        <a href="/pages/bajas.php"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">arrow_back</span> Volver a Bajas</a>
        <select onchange="location.href='/pages/bajas_reporte_pdf.php'+(this.value?'?validacion='+this.value:'')">
            <option value="">Todos los estados de validación</option>
            <option value="Pendiente" <?= $filtroValidacion==='Pendiente'?'selected':'' ?>>Solo Pendientes</option>
            <option value="Validado" <?= $filtroValidacion==='Validado'?'selected':'' ?>>Solo Validadas</option>
            <option value="Rechazado" <?= $filtroValidacion==='Rechazado'?'selected':'' ?>>Solo Rechazadas</option>
        </select>
        <span class="folio-tag">Folio: <?= $folio ?></span>
    </div>

    <div class="hoja">

        <table class="fmt fmt-header">
            <tr>
                <td class="fmt-logo-cell"><img src="<?= $logoSrc ?>" alt="Logo"></td>
                <td class="fmt-title-cell">FORMATO DE RELACIÓN GENERAL<br>DE EQUIPOS DADOS DE BAJA</td>
                <td class="fmt-code-cell">
                    <table>
                        <tr><td class="lbl">Folio:</td><td class="val"><?= e($folio) ?></td></tr>
                        <tr><td class="lbl">Generado:</td><td class="val"><?= date('d/m/Y') ?></td></tr>
                        <tr><td class="lbl">Registros:</td><td class="val"><?= $totalBajas ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="fmt fmt-fecha">
            <tr>
                <td class="fmt-fecha-label" rowspan="2" style="width:14%">FECHA:</td>
                <td style="width:28.6%"><?= date('d') ?></td>
                <td style="width:28.6%"><?= date('m') ?></td>
                <td style="width:28.6%"><?= date('Y') ?></td>
            </tr>
            <tr>
                <td class="fmt-fecha-sub">DÍA</td>
                <td class="fmt-fecha-sub">MES</td>
                <td class="fmt-fecha-sub">AÑO</td>
            </tr>
        </table>

        <table class="fmt" style="margin-top:-1px">
            <tr><td colspan="2" class="fmt-section-title">DATOS GENERALES DEL REPORTE</td></tr>
            <tr><td class="fmt-dato-label">GENERADO POR</td><td class="fmt-dato-valor"><?= e($_SESSION['usuario']['nombre']) ?></td></tr>
            <tr><td class="fmt-dato-label">CARGO</td><td class="fmt-dato-valor"><?= e($_SESSION['usuario']['cargo'] ?? '—') ?></td></tr>
        </table>

        <table class="fmt" style="margin-top:-1px">
            <tr><td class="fmt-section-title">CARACTERÍSTICAS DE LOS BIENES INFORMÁTICOS</td></tr>
        </table>
        <table class="fmt-items">
            <thead>
                <tr>
                    <th style="width:11%">No. Inventario</th>
                    <th style="width:9%">No. Serie</th>
                    <th style="width:12%">Marca</th>
                    <th style="width:14%">Modelo</th>
                    <th style="width:12%">Área</th>
                    <th style="width:10%">Fecha Baja</th>
                    <th style="width:10%">Valor Estimado</th>
                    <th style="width:12%">Motivo de Baja</th>
                    <th style="width:10%">Recomendación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bajas)): ?>
                <tr><td colspan="9" style="padding:16px;color:#666">No hay bajas registradas<?= $filtroValidacion ? " con estado «{$filtroValidacion}»" : '' ?>.</td></tr>
                <?php else: foreach ($bajas as $b): ?>
                <tr>
                    <td><?= e($b['numero_inventario']) ?></td>
                    <td><?= e($b['numero_serie'] ?: 'Sin Serie') ?></td>
                    <td><?= e($b['marca'] ?: '—') ?></td>
                    <td class="izq"><?= e($b['modelo']) ?></td>
                    <td class="izq"><?= e($b['nombre_area'] ?? '—') ?></td>
                    <td><?= fechaES($b['fecha_baja']) ?></td>
                    <td><?= $b['valor_actual_estimado'] ? '$' . number_format($b['valor_actual_estimado'], 2) : '—' ?></td>
                    <td class="izq"><?= e($b['motivo_baja']) ?></td>
                    <td><?= e($b['recomendacion']) ?></td>
                </tr>
                <?php endforeach;
                    for ($i = 0; $i < max(0, 3 - count($bajas)); $i++): ?>
                <tr class="fmt-empty-rows"><td colspan="9">&nbsp;</td></tr>
                <?php endfor;
                endif; ?>
            </tbody>
        </table>

        <table class="fmt" style="margin-top:14px">
            <tr><td class="fmt-section-title">OBSERVACIONES</td></tr>
        </table>
        <div class="fmt-obs-box">&nbsp;</div>

        <table class="fmt-firmas">
            <tr>
                <td>
                    <div class="fmt-firma-linea"></div>
                    Responsable de Tecnología
                </td>
                <td>
                    <div class="fmt-firma-linea"></div>
                    Recibe Conforme — Dirección / Administración
                </td>
            </tr>
        </table>

        <p class="fmt-nota">NOTA: Este es un reporte consolidado de equipos dados de baja. Para el dictamen técnico individual de cada equipo (diagnóstico, análisis económico y validación), consulte su documento correspondiente.</p>

    </div>

</body>
</html>
