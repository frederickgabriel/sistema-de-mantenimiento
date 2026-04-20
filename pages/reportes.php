<?php
// =============================================
// REPORTES — Descarga PDF con HTML puro
// Archivo: pages/reportes.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db = getDB();

// ---- Estadísticas globales ----
$stats = [
    'equipos'    => $db->query("SELECT COUNT(*) FROM Equipos")->fetchColumn(),
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

// Mantenimientos del mes actual
$mesActual = date('Y-m');
$mttosMes  = $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE DATE_FORMAT(fecha_realizacion,'%Y-%m')='{$mesActual}'")->fetchColumn();

// ¿Se solicitó PDF?
$generarPDF = isset($_GET['pdf']);

// Mantenimientos recientes (para el reporte)
$mantenimientos = $db->query("
    SELECT m.*, e.modelo, e.marca, a.nombre_area, u.nombre as tecnico
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = m.id_tecnico
    ORDER BY m.fecha_realizacion DESC
    LIMIT 50
")->fetchAll();

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

// ¿Modo PDF? Generar página imprimible
if ($generarPDF):
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Mantenimiento — <?= date('d/m/Y') ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; color: #1a1a2e; font-size: 13px; background: #fff; }
        .report-header { background: #004085; color: #fff; padding: 28px 40px; display: flex; justify-content: space-between; align-items: center; }
        .report-title { font-size: 22px; font-weight: 700; }
        .report-sub { font-size: 13px; opacity: 0.8; margin-top: 4px; }
        .report-date { font-size: 12px; opacity: 0.7; text-align: right; }
        .section { padding: 24px 40px; }
        .section-title { font-size: 15px; font-weight: 700; color: #004085; border-bottom: 2px solid #004085; padding-bottom: 6px; margin-bottom: 16px; }
        .stats-row { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
        .stat-box { flex: 1; min-width: 100px; background: #f4f6f9; border-radius: 8px; padding: 14px; text-align: center; }
        .stat-box .val { font-size: 28px; font-weight: 700; color: #004085; }
        .stat-box .lbl { font-size: 11px; color: #666; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        thead { background: #004085; color: #fff; }
        thead th { padding: 8px 10px; text-align: left; font-weight: 600; }
        tbody tr:nth-child(even) { background: #f8f9ff; }
        tbody td { padding: 7px 10px; border-bottom: 1px solid #e8ecf0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-p { background: #d0e8ff; color: #004085; }
        .badge-c { background: #ffe8c8; color: #7a3800; }
        .badge-ok { background: #d0f0dd; color: #155724; }
        .badge-warn { background: #fff3cd; color: #856404; }
        .badge-err { background: #fddcdc; color: #721c24; }
        .footer { text-align: center; font-size: 11px; color: #aaa; padding: 16px; border-top: 1px solid #e0e0e0; }
        @media print {
            .no-print { display: none; }
            body { font-size: 12px; }
            .report-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<div class="no-print" style="background:#f0f4ff;padding:12px 40px;display:flex;gap:12px;align-items:center">
    <button onclick="window.print()" style="background:#004085;color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-size:14px">🖨 Imprimir / Guardar PDF</button>
    <a href="/pages/reportes.php" style="color:#004085;font-size:14px">← Volver al sistema</a>
    <span style="color:#666;font-size:13px">Usa Ctrl+P → Guardar como PDF en tu navegador</span>
</div>

<div class="report-header">
    <div>
        <div class="report-title">🖥 Reporte de Mantenimiento de Equipos</div>
        <div class="report-sub">Sistema Institucional de Gestión de Cómputo</div>
    </div>
    <div class="report-date">
        Generado: <?= date('d/m/Y H:i') ?><br>
        Por: <?= e($_SESSION['usuario']['nombre']) ?>
    </div>
</div>

<div class="section">
    <div class="section-title">Resumen General</div>
    <div class="stats-row">
        <div class="stat-box"><div class="val"><?= $stats['equipos'] ?></div><div class="lbl">Total Equipos</div></div>
        <div class="stat-box"><div class="val" style="color:#155724"><?= $stats['activos'] ?></div><div class="lbl">Activos</div></div>
        <div class="stat-box"><div class="val" style="color:#856404"><?= $stats['reparacion'] ?></div><div class="lbl">En Reparación</div></div>
        <div class="stat-box"><div class="val" style="color:#666"><?= $stats['inactivos'] ?></div><div class="lbl">Inactivos</div></div>
        <div class="stat-box"><div class="val"><?= $stats['areas'] ?></div><div class="lbl">Áreas</div></div>
        <div class="stat-box"><div class="val"><?= $stats['mttos'] ?></div><div class="lbl">Total Mttos.</div></div>
        <div class="stat-box"><div class="val" style="color:#004085"><?= $stats['preventivos'] ?></div><div class="lbl">Preventivos</div></div>
        <div class="stat-box"><div class="val" style="color:#7a3800"><?= $stats['correctivos'] ?></div><div class="lbl">Correctivos</div></div>
        <div class="stat-box"><div class="val" style="color:#721c24"><?= $stats['tareas_p'] ?></div><div class="lbl">Tareas Pendientes</div></div>
        <div class="stat-box"><div class="val" style="color:#155724"><?= $stats['tareas_r'] ?></div><div class="lbl">Tareas Realizadas</div></div>
    </div>
</div>

<?php if (!empty($urgentes)): ?>
<div class="section" style="padding-top:0">
    <div class="section-title">⚠ Equipos con Mantenimiento Próximo o Vencido (30 días)</div>
    <table>
        <thead><tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Próx. Mantenimiento</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($urgentes as $u): ?>
        <tr>
            <td><?= e($u['numero_inventario']) ?></td>
            <td><?= e($u['modelo']) ?> <?= e($u['marca'] ?? '') ?></td>
            <td><?= e($u['nombre_area'] ?? '—') ?></td>
            <td><?= fechaES($u['proximo_mantenimiento']) ?></td>
            <td>
                <?php if ($u['dias'] < 0): ?>
                    <span class="badge badge-err">Vencido <?= abs((int)$u['dias']) ?>d</span>
                <?php elseif ($u['dias'] <= 7): ?>
                    <span class="badge badge-warn">Urgente (<?= $u['dias'] ?>d)</span>
                <?php else: ?>
                    <span class="badge badge-p">Próximo (<?= $u['dias'] ?>d)</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="section" style="padding-top:0">
    <div class="section-title">Historial de Mantenimientos Recientes (últimos 50)</div>
    <table>
        <thead>
            <tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Tipo</th><th>Fecha Inicio</th><th>Fecha Entrega</th><th>Próx. Cita</th><th>Técnico</th></tr>
        </thead>
        <tbody>
        <?php foreach ($mantenimientos as $m): ?>
        <tr>
            <td><?= e($m['numero_inventario']) ?></td>
            <td><?= e($m['modelo']) ?> <?= e($m['marca'] ?? '') ?></td>
            <td><?= e($m['nombre_area'] ?? '—') ?></td>
            <td>
                <?php if ($m['tipo_mantenimiento'] === 'Preventivo'): ?>
                    <span class="badge badge-p">Preventivo</span>
                <?php else: ?>
                    <span class="badge badge-c">Correctivo</span>
                <?php endif; ?>
            </td>
            <td><?= fechaES($m['fecha_realizacion']) ?></td>
            <td><?= fechaES($m['fecha_entrega']) ?></td>
            <td><?= fechaES($m['proximo_mantenimiento']) ?></td>
            <td><?= e($m['tecnico'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="footer">
    Reporte generado el <?= date('d \d\e F \d\e Y \a \l\a\s H:i') ?> — <?= SITE_NAME ?>
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
    <title>Reportes — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">📊 Reportes</div>
                <div class="page-subtitle">Vista previa y descarga de reportes en PDF</div>
            </div>
            <div class="page-actions">
                <a href="/pages/reportes.php?pdf=1" target="_blank" class="btn btn-primary">📥 Generar Reporte PDF</a>
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
                <div class="card-title">⚠ Equipos con Mantenimiento Próximo o Vencido</div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Próx. Mantenimiento</th><th>Días</th></tr></thead>
                    <tbody>
                    <?php foreach ($urgentes as $u): ?>
                    <tr>
                        <td class="text-mono"><?= e($u['numero_inventario']) ?></td>
                        <td><?= e($u['modelo']) ?></td>
                        <td class="text-secondary"><?= e($u['nombre_area'] ?? '—') ?></td>
                        <td><?= fechaES($u['proximo_mantenimiento']) ?></td>
                        <td>
                            <?php $dias = (int)$u['dias']; ?>
                            <?php if ($dias < 0): ?>
                                <span class="badge-estado badge-no-realizado">Vencido <?= abs($dias) ?>d</span>
                            <?php elseif ($dias <= 7): ?>
                                <span class="badge-estado badge-reparacion">⚠ <?= $dias ?>d</span>
                            <?php else: ?>
                                <span class="badge-estado badge-proceso">📅 <?= $dias ?>d</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vista previa del historial -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📋 Vista Previa — Últimos Mantenimientos</div>
                <a href="/pages/reportes.php?pdf=1" target="_blank" class="btn btn-primary btn-sm">📥 Descargar PDF completo</a>
            </div>
            <div class="table-wrapper">
                <?php if (empty($mantenimientos)): ?>
                    <div class="empty-state"><span class="empty-icon">📋</span><p>Sin registros aún.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Inventario</th><th>Modelo</th><th>Área</th><th>Tipo</th><th>Fecha</th><th>Próx. Cita</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mantenimientos as $m): ?>
                    <tr>
                        <td class="text-mono"><?= e($m['numero_inventario']) ?></td>
                        <td><?= e($m['modelo']) ?></td>
                        <td class="text-secondary"><?= e($m['nombre_area'] ?? '—') ?></td>
                        <td>
                            <?= $m['tipo_mantenimiento'] === 'Preventivo'
                                ? '<span class="badge-estado badge-proceso">🛡 Preventivo</span>'
                                : '<span class="badge-estado badge-reparacion">🔨 Correctivo</span>' ?>
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

    </main>
</div>
</body>
</html>
