<?php
// =============================================
// REPORTE GENERAL DE BAJAS DE EQUIPO
// Archivo: pages/bajas_reporte_pdf.php
// Documento consolidado con todos los equipos dados de baja
// =============================================
require_once '../includes/config.php';
requireLogin();

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
    SELECT b.*, e.modelo, e.marca, a.nombre_area, u.nombre as tecnico_nombre
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
$totalValidadas  = count(array_filter($bajas, fn($b) => $b['estado_validacion'] === 'Validado'));
$totalPendientes = count(array_filter($bajas, fn($b) => $b['estado_validacion'] === 'Pendiente'));
$totalRechazadas = count(array_filter($bajas, fn($b) => $b['estado_validacion'] === 'Rechazado'));
$valorTotal = array_sum(array_column($bajas, 'valor_actual_estimado'));

$folio = 'REP-BAJAS-' . date('Ymd');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte General de Bajas — <?= $folio ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;600;700&family=Space+Mono:wght@400;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; color: #1a1a2e; background: #fff; font-size: 13px; line-height: 1.5; }

        .no-print { background: #f5f6fa; color: #1a1a2e; border-bottom: 1px solid #e2e5eb; padding: 12px 40px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .no-print button { background: #5b21b6; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-family: 'DM Sans', sans-serif; font-weight: 600; }
        .no-print button:hover { background: #4c1d95; }
        .no-print a { color: #5b21b6; font-size: 14px; text-decoration: none; }
        .no-print select { padding: 6px 10px; border-radius: 6px; border: 1px solid #d0d7de; font-family: 'DM Sans', sans-serif; }

        .documento { max-width: 900px; margin: 0 auto; padding: 40px; }

        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #004085; padding-bottom: 20px; margin-bottom: 24px; }
        .inst-nombre { font-size: 18px; font-weight: 700; color: #004085; }
        .inst-sub { font-size: 12px; color: #666; margin-top: 3px; }
        .doc-folio { text-align: right; }
        .folio-num { font-family: 'Space Mono', monospace; font-size: 18px; font-weight: 700; color: #004085; }
        .folio-fecha { font-size: 12px; color: #666; margin-top: 3px; }

        .doc-titulo { text-align: center; margin-bottom: 24px; }
        .doc-titulo h1 { font-size: 20px; font-weight: 700; color: #1a1a2e; text-transform: uppercase; letter-spacing: .05em; }
        .doc-titulo p { font-size: 13px; color: #666; margin-top: 4px; }

        .resumen-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 26px; }
        .resumen-item { border: 1px solid #e2e5eb; border-radius: 8px; padding: 12px; text-align: center; }
        .resumen-valor { font-size: 20px; font-weight: 700; color: #004085; }
        .resumen-label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #888; margin-top: 2px; }

        table.relacion { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        table.relacion th { background: #004085; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; padding: 8px 10px; text-align: left; }
        table.relacion td { padding: 8px 10px; border-bottom: 1px solid #e2e5eb; font-size: 12px; vertical-align: top; }
        table.relacion tr:nth-child(even) td { background: #f8f9ff; }
        .inv-mono { font-family: 'Space Mono', monospace; color: #004085; font-weight: 700; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; }
        .badge.validado { background: #f0fff4; color: #2ea043; border: 1px solid #2ea04355; }
        .badge.rechazado { background: #fff0f0; color: #da3633; border: 1px solid #da363355; }
        .badge.pendiente { background: #fffbf0; color: #e3a008; border: 1px solid #e3a00855; }

        .firmas-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 50px; }
        .firma-box { text-align: center; padding-top: 50px; border-top: 1px solid #333; }
        .firma-nombre { font-size: 12px; font-weight: 700; color: #1a1a2e; }
        .firma-cargo { font-size: 11px; color: #666; margin-top: 2px; }

        .doc-footer { border-top: 1px solid #ddd; padding-top: 12px; margin-top: 32px; display: flex; justify-content: space-between; font-size: 11px; color: #aaa; }

        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
            .documento { padding: 20px; }
            @page { margin: 1.5cm; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()"><span class="material-symbols-outlined mi-sm">print</span> Imprimir / Guardar como PDF</button>
        <a href="/pages/bajas.php"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">arrow_back</span> Volver a Bajas</a>
        <select onchange="location.href='/pages/bajas_reporte_pdf.php'+(this.value?'?validacion='+this.value:'')">
            <option value="">Todos los estados de validación</option>
            <option value="Pendiente" <?= $filtroValidacion==='Pendiente'?'selected':'' ?>>Solo Pendientes</option>
            <option value="Validado" <?= $filtroValidacion==='Validado'?'selected':'' ?>>Solo Validadas</option>
            <option value="Rechazado" <?= $filtroValidacion==='Rechazado'?'selected':'' ?>>Solo Rechazadas</option>
        </select>
        <span style="color:#6e7781;font-size:13px">Folio: <?= $folio ?></span>
    </div>

    <div class="documento">

        <div class="doc-header">
            <div>
                <div class="inst-nombre"><span class="material-symbols-outlined mi-md" style="vertical-align:-4px">computer</span> <?= SITE_NAME ?></div>
                <div class="inst-sub">Sistema Institucional de Gestión de Equipos de Cómputo</div>
            </div>
            <div class="doc-folio">
                <div class="folio-num"><?= $folio ?></div>
                <div class="folio-fecha">Fecha de emisión: <?= fechaES(date('Y-m-d')) ?></div>
                <div class="folio-fecha"><?= count($bajas) ?> registro(s)<?= $filtroValidacion ? " — {$filtroValidacion}" : '' ?></div>
            </div>
        </div>

        <div class="doc-titulo">
            <h1>Relación General de Equipos Dados de Baja</h1>
            <p>Documento consolidado para trámite administrativo del inventario institucional</p>
        </div>

        <div class="resumen-grid">
            <div class="resumen-item"><div class="resumen-valor"><?= $totalBajas ?></div><div class="resumen-label">Total</div></div>
            <div class="resumen-item"><div class="resumen-valor" style="color:#2ea043"><?= $totalValidadas ?></div><div class="resumen-label">Validadas</div></div>
            <div class="resumen-item"><div class="resumen-valor" style="color:#e3a008"><?= $totalPendientes ?></div><div class="resumen-label">Pendientes</div></div>
            <div class="resumen-item"><div class="resumen-valor" style="color:#da3633"><?= $totalRechazadas ?></div><div class="resumen-label">Rechazadas</div></div>
            <div class="resumen-item"><div class="resumen-valor">$<?= number_format($valorTotal, 2) ?></div><div class="resumen-label">Valor Est. Total</div></div>
        </div>

        <?php if (empty($bajas)): ?>
        <p style="text-align:center;color:#888;padding:40px 0">No hay bajas registradas<?= $filtroValidacion ? " con estado «{$filtroValidacion}»" : '' ?>.</p>
        <?php else: ?>
        <table class="relacion">
            <thead>
                <tr>
                    <th>No. Inventario</th><th>Modelo / Marca</th><th>Área</th><th>Motivo</th>
                    <th>Fecha Baja</th><th>Recomendación</th><th>Técnico</th><th>Validación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bajas as $b): ?>
                <tr>
                    <td class="inv-mono"><?= e($b['numero_inventario']) ?></td>
                    <td><?= e($b['modelo']) ?><?= $b['marca'] ? ' — '.e($b['marca']) : '' ?></td>
                    <td><?= e($b['nombre_area'] ?? '—') ?></td>
                    <td><?= e($b['motivo_baja']) ?></td>
                    <td><?= fechaES($b['fecha_baja']) ?></td>
                    <td><?= e($b['recomendacion']) ?></td>
                    <td><?= e($b['tecnico_nombre'] ?? '—') ?></td>
                    <td>
                        <?php $cls = strtolower($b['estado_validacion']); ?>
                        <span class="badge <?= $cls ?>"><?= e($b['estado_validacion']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="firmas-grid">
            <div class="firma-box"><div class="firma-nombre">Elaboró</div><div class="firma-cargo">Responsable de Tecnología</div></div>
            <div class="firma-box"><div class="firma-nombre">Revisó</div><div class="firma-cargo">Jefe de Área</div></div>
            <div class="firma-box"><div class="firma-nombre">Autoriza</div><div class="firma-cargo">Dirección / Administración</div></div>
        </div>

        <div class="doc-footer">
            <span>Generado por <?= SITE_NAME ?></span>
            <span>Folio: <?= $folio ?></span>
        </div>

    </div>

</body>
</html>
