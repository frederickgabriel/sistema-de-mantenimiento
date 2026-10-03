<?php
require_once '../includes/config.php';
requireLogin();
$db = getDB();

$totalEquipos     = $db->query("SELECT COUNT(*) FROM Equipos WHERE estado != 'Baja'")->fetchColumn();
$equiposActivos   = $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Activo'")->fetchColumn();
$equiposInactivos = $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Inactivo'")->fetchColumn();
$equiposRep       = $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='En Reparacion'")->fetchColumn();
$totalAreas       = $db->query("SELECT COUNT(*) FROM Areas")->fetchColumn();
$totalMttos       = $db->query("SELECT COUNT(*) FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario WHERE e.estado != 'Baja'")->fetchColumn();
$mttosHoy         = $db->query("SELECT COUNT(*) FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario WHERE e.estado != 'Baja' AND m.fecha_realizacion = CURDATE()")->fetchColumn();
$pendientes       = $db->query("SELECT COUNT(*) FROM Tareas t LEFT JOIN Equipos e ON e.numero_inventario=t.numero_inventario WHERE t.estado='Pendiente' AND (e.estado IS NULL OR e.estado != 'Baja')")->fetchColumn();
$preventivos      = $db->query("SELECT COUNT(*) FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario WHERE e.estado != 'Baja' AND m.tipo_mantenimiento='Preventivo'")->fetchColumn();
$correctivos      = $db->query("SELECT COUNT(*) FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario WHERE e.estado != 'Baja' AND m.tipo_mantenimiento='Correctivo'")->fetchColumn();

$urgentes = $db->query("
    SELECT e.numero_inventario, e.modelo, a.nombre_area, m.proximo_mantenimiento,
           DATEDIFF(m.proximo_mantenimiento, CURDATE()) AS dias
    FROM Equipos e
    JOIN (SELECT numero_inventario, MAX(id_mantenimiento) as last_id FROM Mantenimientos GROUP BY numero_inventario) lm ON e.numero_inventario=lm.numero_inventario
    JOIN Mantenimientos m ON m.id_mantenimiento=lm.last_id
    LEFT JOIN Areas a ON e.id_area=a.id_area
    WHERE e.estado != 'Baja' AND m.proximo_mantenimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY m.proximo_mantenimiento ASC LIMIT 10
")->fetchAll();

$ultimos = $db->query("
    SELECT m.*, e.modelo, a.nombre_area
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario=m.numero_inventario
    LEFT JOIN Areas a ON e.id_area=a.id_area
    ORDER BY m.fecha_registro DESC LIMIT 8
")->fetchAll();

$tareasRecientes = $db->query("
    SELECT t.*, e.modelo FROM Tareas t
    LEFT JOIN Equipos e ON e.numero_inventario=t.numero_inventario
    ORDER BY t.fecha_creacion DESC LIMIT 5
")->fetchAll();

// Mantenimientos realizados por mes, últimos 6 meses (gráfico de actividad)
$porMesStmt = $db->query("
    SELECT DATE_FORMAT(m.fecha_realizacion,'%b %y') as mes_label,
           DATE_FORMAT(m.fecha_realizacion,'%Y-%m') as mes_order,
           COUNT(*) as total
    FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario
    WHERE e.estado != 'Baja' AND m.fecha_realizacion >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY mes_order, mes_label ORDER BY mes_order ASC
")->fetchAll();
$actividadLabels  = array_column($porMesStmt, 'mes_label');
$actividadValores = array_map('intval', array_column($porMesStmt, 'total'));

// Progreso general de tareas por estado (para el gráfico circular) — excluye tareas de equipos dados de baja
$tareasPorEstado = $db->query("
    SELECT t.estado, COUNT(*) c
    FROM Tareas t LEFT JOIN Equipos e ON e.numero_inventario=t.numero_inventario
    WHERE e.estado IS NULL OR e.estado != 'Baja'
    GROUP BY t.estado
")->fetchAll(PDO::FETCH_KEY_PAIR);
$tRealizado      = (int)($tareasPorEstado['Realizado'] ?? 0);
$tProceso        = (int)($tareasPorEstado['En Proceso'] ?? 0);
$tNoRealizado    = (int)($tareasPorEstado['No Realizado'] ?? 0);
$tTotalTareas    = max($tRealizado + $tProceso + $pendientes + $tNoRealizado, 1);
$pctRealizado    = round($tRealizado / $tTotalTareas * 100);

// Equipo de trabajo: técnicos con más carga y su tarea más reciente
$equipoTrabajo = $db->query("
    SELECT u.id_usuario, u.nombre, u.cargo, u.foto_perfil,
           (SELECT t.nombre_tarea FROM Tareas t WHERE t.id_usuario_asignado = u.id_usuario ORDER BY t.fecha_creacion DESC LIMIT 1) AS ultima_tarea,
           (SELECT t.estado FROM Tareas t WHERE t.id_usuario_asignado = u.id_usuario ORDER BY t.fecha_creacion DESC LIMIT 1) AS ultimo_estado,
           (SELECT COUNT(*) FROM Tareas WHERE id_usuario_asignado = u.id_usuario) AS tareas_total
    FROM Usuarios u
    WHERE u.rol = 'usuario' AND u.activo = 1
    ORDER BY tareas_total DESC, u.nombre ASC
    LIMIT 4
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png">
    <link rel="apple-touch-icon" href="/img/favicon/favicon-180.png">
    <link rel="shortcut icon" href="/img/favicon/favicon.ico">
    <title>Dashboard — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="/css/estilos.css?v=25">
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <div><div class="page-title">Panel de Control</div><div class="page-subtitle">Resumen del sistema — <?= date('d \d\e F \d\e Y') ?></div></div>
            <div class="page-actions"><a href="/pages/reportes.php" class="btn btn-ghost"><span class="material-symbols-outlined mi-sm">bar_chart</span> Descargar Reporte PDF</a></div>
        </div>

        <!-- Aviso solicitudes de rol pendientes (solo admin) -->
        <?php if (esAdmin()):
            $solPend = getDB()->query("SELECT COUNT(*) FROM SolicitudesRol WHERE estado='Pendiente'")->fetchColumn();
            if ($solPend > 0): ?>
        <div class="alerta-urgente" style="border-color:rgba(139,92,246,.35);background:rgba(139,92,246,.07);margin-bottom:16px">
            <div class="alerta-icon material-symbols-outlined">admin_panel_settings</div>
            <div>
                <div class="alerta-title" style="color:var(--purple)">
                    <?= $solPend ?> solicitud<?= $solPend > 1 ? 'es' : '' ?> de rol Admin pendiente<?= $solPend > 1 ? 's' : '' ?> de revisión
                </div>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:3px">
                    Un usuario solicita permisos de administrador y espera tu respuesta.
                </div>
            </div>
            <div style="margin-left:auto;flex-shrink:0">
                <a href="/pages/admin_roles.php" class="btn btn-ghost btn-sm" style="border-color:rgba(139,92,246,.45);color:var(--purple)">Ver solicitudes <span class="material-symbols-outlined mi-sm">arrow_forward</span></a>
            </div>
        </div>
        <?php endif; endif; ?>

        <?php if (count($urgentes) > 0): ?>
        <div class="alerta-urgente">
            <div class="alerta-icon material-symbols-outlined">notifications</div>
            <div>
                <div class="alerta-title"><span class="material-symbols-outlined mi-md">warning</span> Equipos con mantenimiento pendiente o urgente</div>
                <ul class="alerta-list">
                    <?php foreach ($urgentes as $u): ?>
                    <li><strong><?= e($u['numero_inventario']) ?></strong> — <?= e($u['modelo']) ?> (<?= e($u['nombre_area'] ?? 'Sin área') ?>):
                        <?php if ($u['dias'] < 0): ?><span style="color:var(--danger)">Vencido hace <?= abs((int)$u['dias']) ?> día(s)</span>
                        <?php else: ?><span style="color:var(--warning)">En <?= (int)$u['dias'] ?> día(s) — <?= fechaES($u['proximo_mantenimiento']) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="dash-stats">
            <div class="dash-stat" style="--stat-color:var(--accent);--stat-bg:var(--accent-glow)">
                <div class="dash-stat-top">
                    <div class="dash-stat-label">Total Equipos</div>
                    <div class="dash-stat-icon"><span class="material-symbols-outlined mi-md">devices</span></div>
                </div>
                <div class="dash-stat-value"><?= $totalEquipos ?></div>
                <div class="dash-stat-meta"><?= $equiposActivos ?> activos · <?= $equiposRep ?> en reparación</div>
            </div>
            <div class="dash-stat" style="--stat-color:var(--success);--stat-bg:rgba(26,127,55,.12)">
                <div class="dash-stat-top">
                    <div class="dash-stat-label">Mantenimientos</div>
                    <div class="dash-stat-icon"><span class="material-symbols-outlined mi-md">build_circle</span></div>
                </div>
                <div class="dash-stat-value"><?= $totalMttos ?></div>
                <div class="dash-stat-meta"><?= $mttosHoy ?> realizado<?= $mttosHoy == 1 ? '' : 's' ?> hoy</div>
            </div>
            <div class="dash-stat" style="--stat-color:var(--warning);--stat-bg:rgba(154,103,0,.12)">
                <div class="dash-stat-top">
                    <div class="dash-stat-label">Tareas Pendientes</div>
                    <div class="dash-stat-icon"><span class="material-symbols-outlined mi-md">pending_actions</span></div>
                </div>
                <div class="dash-stat-value"><?= $pendientes ?></div>
                <div class="dash-stat-meta"><a href="/pages/tareas.php">Ver todas <span class="material-symbols-outlined mi-sm">arrow_forward</span></a></div>
            </div>
            <div class="dash-stat" style="--stat-color:var(--info);--stat-bg:rgba(9,105,218,.12)">
                <div class="dash-stat-top">
                    <div class="dash-stat-label">Áreas / Salones</div>
                    <div class="dash-stat-icon"><span class="material-symbols-outlined mi-md">meeting_room</span></div>
                </div>
                <div class="dash-stat-value"><?= $totalAreas ?></div>
                <div class="dash-stat-meta"><a href="/pages/equipos.php">Gestionar <span class="material-symbols-outlined mi-sm">arrow_forward</span></a></div>
            </div>
        </div>

        <div class="dash-grid-main">
            <div class="card">
                <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">insights</span> Mantenimientos por Mes — últimos 6 meses</div></div>
                <div class="card-body">
                    <?php if (empty($actividadValores)): ?>
                        <div class="empty-state"><span class="empty-icon material-symbols-outlined">insights</span><p>Sin mantenimientos en los últimos 6 meses.</p></div>
                    <?php else: ?>
                    <div class="ch-wrap" style="height:230px"><canvas id="chartActividad"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">donut_large</span> Progreso de Tareas</div></div>
                <div class="card-body">
                    <div class="dash-donut-wrap">
                        <canvas id="chartTareas"></canvas>
                        <div class="dash-donut-center">
                            <div class="dash-donut-pct"><?= $pctRealizado ?>%</div>
                            <div class="dash-donut-lbl">Realizado</div>
                        </div>
                    </div>
                    <div class="dash-legend">
                        <div class="dash-legend-item"><span class="dash-legend-dot" style="background:var(--success)"></span> Realizado <strong><?= $tRealizado ?></strong></div>
                        <div class="dash-legend-item"><span class="dash-legend-dot" style="background:var(--accent)"></span> En Proceso <strong><?= $tProceso ?></strong></div>
                        <div class="dash-legend-item"><span class="dash-legend-dot" style="background:var(--warning)"></span> Pendiente <strong><?= $pendientes ?></strong></div>
                        <div class="dash-legend-item"><span class="dash-legend-dot" style="background:var(--danger)"></span> No Realizado <strong><?= $tNoRealizado ?></strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><span class="material-symbols-outlined mi-md">groups</span> Equipo de Trabajo</div>
                    <?php if (esAdmin()): ?><a href="/pages/empleados.php" class="btn btn-ghost btn-sm">Ver todos</a><?php endif; ?>
                </div>
                <?php if (empty($equipoTrabajo)): ?>
                    <div class="empty-state"><span class="empty-icon material-symbols-outlined">groups</span><p>Sin técnicos registrados.</p></div>
                <?php else: ?>
                <div class="team-list">
                    <?php foreach ($equipoTrabajo as $tec): ?>
                    <div class="team-row">
                        <?= avatarChip($tec['foto_perfil'], $tec['nombre'], 40) ?>
                        <div class="team-info">
                            <span class="team-name text-clip" title="<?= e($tec['nombre']) ?>"><?= e($tec['nombre']) ?></span>
                            <span class="team-task text-clip" title="<?= e($tec['ultima_tarea'] ?? '') ?>"><?= $tec['ultima_tarea'] ? e($tec['ultima_tarea']) : 'Sin tareas asignadas' ?></span>
                        </div>
                        <?php if ($tec['ultimo_estado']): ?><?= badgeTarea($tec['ultimo_estado']) ?><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">checklist</span> Tareas Recientes</div><a href="/pages/tareas.php" class="btn btn-ghost btn-sm">Ver todas</a></div>
                <?php if (empty($tareasRecientes)): ?>
                    <div class="empty-state"><span class="empty-icon material-symbols-outlined">inbox</span><p>Sin tareas.</p></div>
                <?php else: ?>
                <div class="task-list">
                    <?php foreach ($tareasRecientes as $t):
                        $iconMap  = ['Pendiente' => ['hourglass_empty','var(--warning)','rgba(154,103,0,.12)'], 'En Proceso' => ['autorenew','var(--accent)','var(--accent-glow)'], 'Realizado' => ['check_circle','var(--success)','rgba(26,127,55,.12)'], 'No Realizado' => ['cancel','var(--danger)','rgba(207,34,46,.12)']];
                        [$ico,$col,$bg] = $iconMap[$t['estado']] ?? ['task','var(--accent)','var(--accent-glow)'];
                    ?>
                    <div class="task-row">
                        <div class="task-icon" style="color:<?= $col ?>;background:<?= $bg ?>"><span class="material-symbols-outlined mi-sm"><?= $ico ?></span></div>
                        <div class="task-info">
                            <span class="task-name text-clip" title="<?= e($t['nombre_tarea']) ?>"><?= e($t['nombre_tarea']) ?></span>
                            <span class="task-date">Vence: <?= fechaES($t['fecha_programada']) ?></span>
                        </div>
                        <?= badgeTarea($t['estado']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">build</span> Últimos Mantenimientos</div><a href="/pages/mantenimientos.php" class="btn btn-ghost btn-sm">Ver historial completo</a></div>
            <div class="table-wrapper">
                <?php if (empty($ultimos)): ?>
                    <div class="empty-state"><span class="empty-icon material-symbols-outlined">build</span><p>Sin mantenimientos aún.</p></div>
                <?php else: ?>
                <table><thead><tr><th>No. Inventario</th><th>Modelo</th><th>Área</th><th>Tipo</th><th>Fecha</th><th>Próx. Mantenimiento</th></tr></thead><tbody>
                <?php foreach ($ultimos as $m): ?>
                <tr>
                    <td class="text-mono"><span class="text-clip" title="<?= e($m['numero_inventario']) ?>" style="max-width:140px"><?= e($m['numero_inventario']) ?></span></td>
                    <td><span class="text-clip" title="<?= e($m['modelo']) ?>" style="max-width:180px"><?= e($m['modelo']) ?></span></td>
                    <td class="text-secondary"><span class="text-clip" title="<?= e($m['nombre_area'] ?? '') ?>"><?= e($m['nombre_area'] ?? '—') ?></span></td>
                    <td><?= $m['tipo_mantenimiento']==='Preventivo' ? '<span class="badge-estado badge-proceso"><span class="material-symbols-outlined mi-sm">shield</span> Preventivo</span>' : '<span class="badge-estado badge-reparacion"><span class="material-symbols-outlined mi-sm">handyman</span> Correctivo</span>' ?></td>
                    <td class="text-secondary"><?= fechaES($m['fecha_realizacion']) ?></td>
                    <td><?php
                        if ($m['proximo_mantenimiento']) {
                            $dias = (int)((strtotime($m['proximo_mantenimiento'])-time())/86400);
                            $c = $dias<0?'danger':($dias<=7?'warning':'success');
                            echo "<span class=\"text-{$c}\">".fechaES($m['proximo_mantenimiento'])."</span>";
                            if ($dias<0) echo " <small>(vencido)</small>";
                            elseif ($dias<=7) echo " <small>(en {$dias}d)</small>";
                        } else echo '—';
                    ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color       = '#57606a';
Chart.defaults.borderColor = '#edeff3';
Chart.defaults.font.family = "DM Sans, sans-serif";

const TIP = { backgroundColor:'#1a1a2e', borderColor:'#2d2d44', borderWidth:1, padding:10, titleColor:'#fff', bodyColor:'#fff' };

if (document.getElementById('chartActividad'))
    new Chart(document.getElementById('chartActividad'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($actividadLabels) ?>,
            datasets: [{
                label: 'Mantenimientos',
                data: <?= json_encode($actividadValores) ?>,
                backgroundColor: 'rgba(26,127,55,.75)',
                borderColor: 'rgba(26,127,55,1)',
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 46
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: TIP },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { color: '#edeff3' }, ticks: { precision: 0 }, beginAtZero: true }
            }
        }
    });

new Chart(document.getElementById('chartTareas'), {
    type: 'doughnut',
    data: {
        labels: ['Realizado', 'En Proceso', 'Pendiente', 'No Realizado'],
        datasets: [{
            data: [<?= $tRealizado ?>, <?= $tProceso ?>, <?= $pendientes ?>, <?= $tNoRealizado ?>],
            backgroundColor: ['rgba(26,127,55,.85)', 'rgba(91,33,182,.85)', 'rgba(154,103,0,.85)', 'rgba(207,34,46,.85)'],
            borderColor: '#ffffff',
            borderWidth: 3
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '72%',
        plugins: { legend: { display: false }, tooltip: TIP }
    }
});
</script>
<script src="/js/countup.js"></script>
</body>
</html>