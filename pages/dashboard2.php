<?php
// =============================================
// DASHBOARD PRINCIPAL
// Archivo: pages/dashboard.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db = getDB();

// ---- Estadísticas generales ----
$totalEquipos    = $db->query("SELECT COUNT(*) FROM Equipos")->fetchColumn();
$equiposActivos  = $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Activo'")->fetchColumn();
$equiposInactivos= $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Inactivo'")->fetchColumn();
$equiposRep      = $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='En Reparacion'")->fetchColumn();
$totalAreas      = $db->query("SELECT COUNT(*) FROM Areas")->fetchColumn();
$totalMttos      = $db->query("SELECT COUNT(*) FROM Mantenimientos")->fetchColumn();
$mttosHoy        = $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE fecha_realizacion = CURDATE()")->fetchColumn();
$pendientes      = $db->query("SELECT COUNT(*) FROM Tareas WHERE estado='Pendiente'")->fetchColumn();

// ---- Equipos con mantenimiento urgente (vencido o próximo en 7 días) ----
$urgentes = $db->query("
    SELECT e.numero_inventario, e.modelo, a.nombre_area, m.proximo_mantenimiento,
           DATEDIFF(m.proximo_mantenimiento, CURDATE()) AS dias
    FROM Equipos e
    JOIN (
        SELECT numero_inventario, MAX(id_mantenimiento) as last_id
        FROM Mantenimientos GROUP BY numero_inventario
    ) lm ON e.numero_inventario = lm.numero_inventario
    JOIN Mantenimientos m ON m.id_mantenimiento = lm.last_id
    LEFT JOIN Areas a ON e.id_area = a.id_area
    WHERE m.proximo_mantenimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY m.proximo_mantenimiento ASC
    LIMIT 10
")->fetchAll();

// ---- Últimos mantenimientos ----
$ultimos = $db->query("
    SELECT m.*, e.modelo, a.nombre_area, u.nombre as tecnico
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = m.id_tecnico
    ORDER BY m.fecha_registro DESC
    LIMIT 8
")->fetchAll();

// ---- Tipos de mantenimiento (para mini chart) ----
$preventivos = $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE tipo_mantenimiento='Preventivo'")->fetchColumn();
$correctivos  = $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE tipo_mantenimiento='Correctivo'")->fetchColumn();

// ---- Tareas recientes ----
$tareasRecientes = $db->query("
    SELECT t.*, e.modelo 
    FROM Tareas t
    LEFT JOIN Equipos e ON e.numero_inventario = t.numero_inventario
    ORDER BY t.fecha_creacion DESC LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <!-- Header -->
        <div class="page-header">
            <div>
                <div class="page-title">Panel de Control</div>
                <div class="page-subtitle">Resumen del sistema — <?= date('d \d\e F \d\e Y') ?></div>
            </div>
            <div class="page-actions">
                <a href="/pages/reportes.php" class="btn btn-ghost">📊 Descargar Reporte PDF</a>
            </div>
        </div>

        <!-- Alertas urgentes -->
        <?php if (count($urgentes) > 0): ?>
        <div class="alerta-urgente">
            <div class="alerta-icon">🔔</div>
            <div>
                <div class="alerta-title">⚠ Equipos con mantenimiento pendiente o urgente</div>
                <ul class="alerta-list">
                    <?php foreach ($urgentes as $u): ?>
                    <li>
                        <strong><?= e($u['numero_inventario']) ?></strong> — <?= e($u['modelo']) ?>
                        (<?= e($u['nombre_area'] ?? 'Sin área') ?>):
                        <?php if ($u['dias'] < 0): ?>
                            <span style="color:var(--danger)">Vencido hace <?= abs((int)$u['dias']) ?> día(s)</span>
                        <?php else: ?>
                            <span style="color:var(--warning)">En <?= (int)$u['dias'] ?> día(s) — <?= fechaES($u['proximo_mantenimiento']) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Equipos</div>
                <div class="stat-value accent"><?= $totalEquipos ?></div>
                <div class="stat-meta"><?= $equiposActivos ?> activos · <?= $equiposRep ?> en reparación</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Mantenimientos Hoy</div>
                <div class="stat-value warning"><?= $mttosHoy ?></div>
                <div class="stat-meta"><?= $totalMttos ?> total histórico</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Tareas Pendientes</div>
                <div class="stat-value danger"><?= $pendientes ?></div>
                <div class="stat-meta"><a href="/pages/tareas.php">Ver todas →</a></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Áreas / Salones</div>
                <div class="stat-value success"><?= $totalAreas ?></div>
                <div class="stat-meta"><a href="/pages/equipos.php">Gestionar →</a></div>
            </div>
        </div>

        <!-- Dos columnas: Gráfica + Tareas recientes -->
        <div class="grid-2">

            <!-- Mini estadísticas visuales -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">📈 Distribución de Equipos</div>
                </div>
                <div class="card-body">
                    <?php
                    $total = max($totalEquipos, 1);
                    $bars = [
                        ['Activos',        $equiposActivos,  'success', $total],
                        ['Inactivos',      $equiposInactivos,'danger',  $total],
                        ['En Reparación',  $equiposRep,      'warning', $total],
                        ['Preventivos',    $preventivos,     'accent',  max($totalMttos,1)],
                        ['Correctivos',    $correctivos,     'danger',  max($totalMttos,1)],
                    ];
                    foreach ($bars as [$label, $val, $color, $max]): 
                        $pct = $max > 0 ? round(($val / $max) * 100) : 0;
                    ?>
                    <div class="chart-bar-wrap">
                        <div class="chart-bar-label">
                            <span><?= $label ?></span>
                            <span><?= $val ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="chart-bar-track">
                            <div class="chart-bar-fill <?= $color ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tareas recientes -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">📋 Tareas Recientes</div>
                    <a href="/pages/tareas.php" class="btn btn-ghost btn-sm">Ver todas</a>
                </div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($tareasRecientes)): ?>
                        <div class="empty-state"><span class="empty-icon">📭</span><p>Sin tareas registradas.</p></div>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Tarea</th><th>Estado</th><th>Fecha</th></tr></thead>
                            <tbody>
                            <?php foreach ($tareasRecientes as $t): ?>
                                <tr>
                                    <td><?= e($t['nombre_tarea']) ?><?php if ($t['modelo']): ?><br><small class="text-muted"><?= e($t['modelo']) ?></small><?php endif; ?></td>
                                    <td><?= badgeTarea($t['estado']) ?></td>
                                    <td class="text-secondary" style="font-size:12px"><?= fechaES($t['fecha_programada']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Últimos mantenimientos -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">🔧 Últimos Mantenimientos Realizados</div>
                <a href="/pages/mantenimientos.php" class="btn btn-ghost btn-sm">Ver historial completo</a>
            </div>
            <div class="table-wrapper">
                <?php if (empty($ultimos)): ?>
                    <div class="empty-state"><span class="empty-icon">🔧</span><p>Aún no hay mantenimientos registrados.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>No. Inventario</th><th>Modelo</th><th>Área</th>
                            <th>Tipo</th><th>Fecha</th><th>Próx. Mantenimiento</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ultimos as $m): ?>
                        <tr>
                            <td class="text-mono"><?= e($m['numero_inventario']) ?></td>
                            <td><?= e($m['modelo']) ?></td>
                            <td class="text-secondary"><?= e($m['nombre_area'] ?? '—') ?></td>
                            <td>
                                <?php if ($m['tipo_mantenimiento'] === 'Preventivo'): ?>
                                    <span class="badge-estado badge-proceso">🛡 Preventivo</span>
                                <?php else: ?>
                                    <span class="badge-estado badge-reparacion">🔨 Correctivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-secondary"><?= fechaES($m['fecha_realizacion']) ?></td>
                            <td>
                                <?php
                                if ($m['proximo_mantenimiento']) {
                                    $dias = (int)((strtotime($m['proximo_mantenimiento']) - time()) / 86400);
                                    $color = $dias < 0 ? 'danger' : ($dias <= 7 ? 'warning' : 'success');
                                    echo "<span class=\"text-{$color}\">" . fechaES($m['proximo_mantenimiento']) . "</span>";
                                    if ($dias < 0) echo " <small>(vencido)</small>";
                                    elseif ($dias <= 7) echo " <small>(en {$dias}d)</small>";
                                } else { echo '—'; }
                                ?>
                            </td>
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
