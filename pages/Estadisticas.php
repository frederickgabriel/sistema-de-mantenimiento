<?php
// =============================================
// ESTADÍSTICAS DE MANTENIMIENTO
// Archivo: pages/estadisticas.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db = getDB();

// ============================================
// 1. Equipos por área (barras)
// ============================================
$equiposPorArea = $db->query("
    SELECT a.nombre_area, COUNT(e.numero_inventario) as total
    FROM Areas a
    LEFT JOIN Equipos e ON e.id_area = a.id_area
    GROUP BY a.id_area, a.nombre_area
    ORDER BY total DESC
")->fetchAll();

// ============================================
// 2. Bajas por área (pastel)
// ============================================
$bajasPorArea = $db->query("
    SELECT a.nombre_area, COUNT(b.id_baja) as total
    FROM Areas a
    INNER JOIN Equipos e  ON e.id_area   = a.id_area
    INNER JOIN Bajas b    ON b.numero_inventario = e.numero_inventario
    GROUP BY a.id_area, a.nombre_area
    ORDER BY total DESC
")->fetchAll();

// ============================================
// 3. Equipos que más se dañan (correctivos) (barras horizontal)
// ============================================
$equiposMasDanados = $db->query("
    SELECT m.numero_inventario, e.modelo, e.marca,
           COUNT(*) as total_correctivos
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    WHERE m.tipo_mantenimiento = 'Correctivo'
    GROUP BY m.numero_inventario, e.modelo, e.marca
    ORDER BY total_correctivos DESC
    LIMIT 10
")->fetchAll();

// ============================================
// 4. Mantenimientos por mes (barras agrupadas prev vs corr)
// ============================================
$mttosPorMes = $db->query("
    SELECT 
        DATE_FORMAT(fecha_realizacion, '%Y-%m') as mes,
        DATE_FORMAT(fecha_realizacion, '%b %Y')  as mes_label,
        SUM(tipo_mantenimiento = 'Preventivo')   as preventivos,
        SUM(tipo_mantenimiento = 'Correctivo')   as correctivos,
        COUNT(*)                                  as total
    FROM Mantenimientos
    WHERE fecha_realizacion >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY mes, mes_label
    ORDER BY mes ASC
")->fetchAll();

// ============================================
// 5. Estado de equipos (dona)
// ============================================
$estadoEquipos = $db->query("
    SELECT estado, COUNT(*) as total
    FROM Equipos
    GROUP BY estado
")->fetchAll();

// ============================================
// 6. Tipo de mantenimiento general (pastel)
// ============================================
$tipoMtto = $db->query("
    SELECT tipo_mantenimiento, COUNT(*) as total
    FROM Mantenimientos
    GROUP BY tipo_mantenimiento
")->fetchAll();

// ============================================
// 7. Resumen numérico
// ============================================
$resumen = [
    'total_equipos'    => $db->query("SELECT COUNT(*) FROM Equipos")->fetchColumn(),
    'total_mttos'      => $db->query("SELECT COUNT(*) FROM Mantenimientos")->fetchColumn(),
    'total_bajas'      => $db->query("SELECT COUNT(*) FROM Bajas")->fetchColumn(),
    'total_areas'      => $db->query("SELECT COUNT(*) FROM Areas")->fetchColumn(),
    'mttos_este_mes'   => $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE MONTH(fecha_realizacion)=MONTH(CURDATE()) AND YEAR(fecha_realizacion)=YEAR(CURDATE())")->fetchColumn(),
    'equipos_activos'  => $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Activo'")->fetchColumn(),
];

// ---- Preparar datos JSON para Chart.js ----
function jsonLabels(array $data, string $key): string {
    return json_encode(array_column($data, $key));
}
function jsonValues(array $data, string $key): string {
    return json_encode(array_map('intval', array_column($data, $key)));
}

// Colores del tema oscuro
$coloresBarra   = ['#58a6ff','#3fb950','#d29922','#f85149','#a371f7','#79c0ff','#56d364','#ff7b72'];
$coloresPastel  = ['#58a6ff','#3fb950','#d29922','#f85149','#a371f7','#79c0ff','#ff9e64','#56d364'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .stats-section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--text-muted);
            margin: 32px 0 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .stats-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .charts-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .chart-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 20px 22px;
        }

        .chart-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .chart-card-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 18px;
        }

        .chart-wrap {
            position: relative;
        }

        .chart-wrap-pie {
            max-width: 300px;
            margin: 0 auto;
        }

        .empty-chart {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: var(--text-muted);
            font-size: 13px;
            gap: 8px;
        }

        .ranking-list {
            list-style: none;
        }

        .ranking-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .ranking-item:last-child { border-bottom: none; }

        .ranking-num {
            font-family: var(--font-mono);
            font-size: 18px;
            font-weight: 700;
            color: var(--text-muted);
            width: 28px;
            text-align: center;
            flex-shrink: 0;
        }

        .ranking-num.top1 { color: #d29922; }
        .ranking-num.top2 { color: #8b949e; }
        .ranking-num.top3 { color: #c9730a; }

        .ranking-info { flex: 1; min-width: 0; }
        .ranking-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .ranking-sub  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

        .ranking-bar-wrap { width: 120px; flex-shrink: 0; }
        .ranking-bar-track { background: var(--bg-main); border-radius: 4px; height: 6px; overflow: hidden; }
        .ranking-bar-fill  { height: 100%; border-radius: 4px; background: var(--accent); }
        .ranking-val { font-size: 12px; font-weight: 700; font-family: var(--font-mono); color: var(--accent); text-align: right; margin-top: 3px; }

        @media (max-width: 900px) {
            .charts-grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">📈 Estadísticas</div>
                <div class="page-subtitle">Análisis visual del sistema de mantenimiento</div>
            </div>
        </div>

        <!-- Resumen numérico -->
        <div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:8px">
            <div class="stat-card">
                <div class="stat-label">Total Equipos</div>
                <div class="stat-value accent"><?= $resumen['total_equipos'] ?></div>
                <div class="stat-meta"><?= $resumen['equipos_activos'] ?> activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Áreas</div>
                <div class="stat-value"><?= $resumen['total_areas'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Mantenimientos</div>
                <div class="stat-value success"><?= $resumen['total_mttos'] ?></div>
                <div class="stat-meta">histórico total</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Este mes</div>
                <div class="stat-value warning"><?= $resumen['mttos_este_mes'] ?></div>
                <div class="stat-meta">mantenimientos</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Bajas</div>
                <div class="stat-value danger"><?= $resumen['total_bajas'] ?></div>
                <div class="stat-meta">equipos dados de baja</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Tasa de Bajas</div>
                <div class="stat-value">
                    <?= $resumen['total_equipos'] > 0
                        ? round(($resumen['total_bajas'] / ($resumen['total_equipos'] + $resumen['total_bajas'])) * 100, 1)
                        : 0 ?>%
                </div>
                <div class="stat-meta">del inventario total</div>
            </div>
        </div>

        <!-- ========================
             SECCIÓN 1: EQUIPOS
             ======================== -->
        <div class="stats-section-title">🖥 Distribución de Equipos</div>

        <div class="charts-grid-2">

            <!-- Equipos por área — barras -->
            <div class="chart-card">
                <div class="chart-card-title">📊 Equipos por Área</div>
                <div class="chart-card-sub">Cantidad de equipos registrados en cada sala o área</div>
                <?php if (empty($equiposPorArea)): ?>
                    <div class="empty-chart"><span style="font-size:32px">🏫</span>Sin datos de áreas</div>
                <?php else: ?>
                <div class="chart-wrap" style="height:260px">
                    <canvas id="chartEquiposPorArea"></canvas>
                </div>
                <?php endif; ?>
            </div>

            <!-- Estado de equipos — dona -->
            <div class="chart-card">
                <div class="chart-card-title">🍩 Estado Actual de Equipos</div>
                <div class="chart-card-sub">Distribución por estado: Activo, Inactivo, En Reparación, Baja</div>
                <?php if (empty($estadoEquipos)): ?>
                    <div class="empty-chart"><span style="font-size:32px">🖥</span>Sin datos</div>
                <?php else: ?>
                <div class="chart-wrap chart-wrap-pie" style="height:260px">
                    <canvas id="chartEstadoEquipos"></canvas>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ========================
             SECCIÓN 2: MANTENIMIENTOS
             ======================== -->
        <div class="stats-section-title">🔧 Análisis de Mantenimientos</div>

        <div class="chart-card" style="margin-bottom:20px">
            <div class="chart-card-title">📅 Mantenimientos por Mes (últimos 12 meses)</div>
            <div class="chart-card-sub">Comparativa mensual entre mantenimientos Preventivos y Correctivos</div>
            <?php if (empty($mttosPorMes)): ?>
                <div class="empty-chart"><span style="font-size:32px">📅</span>Sin registros de mantenimientos aún</div>
            <?php else: ?>
            <div class="chart-wrap" style="height:280px">
                <canvas id="chartMttosPorMes"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <div class="charts-grid-2">

            <!-- Tipo de mantenimiento — pastel -->
            <div class="chart-card">
                <div class="chart-card-title">🥧 Tipo de Mantenimiento</div>
                <div class="chart-card-sub">Proporción de mantenimientos Preventivos vs Correctivos</div>
                <?php if (empty($tipoMtto)): ?>
                    <div class="empty-chart"><span style="font-size:32px">🔧</span>Sin datos</div>
                <?php else: ?>
                <div class="chart-wrap chart-wrap-pie" style="height:260px">
                    <canvas id="chartTipoMtto"></canvas>
                </div>
                <?php endif; ?>
            </div>

            <!-- Equipos que más se dañan — ranking -->
            <div class="chart-card">
                <div class="chart-card-title">⚠ Equipos con Más Mantenimientos Correctivos</div>
                <div class="chart-card-sub">Top 10 equipos que han requerido más reparaciones</div>
                <?php if (empty($equiposMasDanados)): ?>
                    <div class="empty-chart"><span style="font-size:32px">✅</span>Ningún equipo con correctivos</div>
                <?php else:
                    $maxCorrectivos = (int)$equiposMasDanados[0]['total_correctivos'];
                ?>
                <ul class="ranking-list">
                    <?php foreach ($equiposMasDanados as $i => $eq):
                        $pct = $maxCorrectivos > 0 ? round(($eq['total_correctivos'] / $maxCorrectivos) * 100) : 0;
                        $numClass = $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : ''));
                        $medal = $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ''));
                    ?>
                    <li class="ranking-item">
                        <div class="ranking-num <?= $numClass ?>"><?= $medal ?: '#'.($i+1) ?></div>
                        <div class="ranking-info">
                            <div class="ranking-name"><?= e($eq['numero_inventario']) ?></div>
                            <div class="ranking-sub"><?= e($eq['modelo']) ?> <?= e($eq['marca'] ?? '') ?></div>
                        </div>
                        <div class="ranking-bar-wrap">
                            <div class="ranking-bar-track">
                                <div class="ranking-bar-fill" style="width:<?= $pct ?>%;background:<?= $i===0 ? 'var(--danger)' : ($i<=2 ? 'var(--warning)' : 'var(--accent)') ?>"></div>
                            </div>
                            <div class="ranking-val"><?= $eq['total_correctivos'] ?> correctivo<?= $eq['total_correctivos'] != 1 ? 's' : '' ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

        </div>

        <!-- ========================
             SECCIÓN 3: BAJAS
             ======================== -->
        <div class="stats-section-title">📛 Análisis de Bajas</div>

        <div class="charts-grid-2">

            <!-- Bajas por área — barras -->
            <div class="chart-card">
                <div class="chart-card-title">📊 Bajas de Equipos por Área</div>
                <div class="chart-card-sub">Áreas donde ocurren más bajas definitivas de equipos</div>
                <?php if (empty($bajasPorArea)): ?>
                    <div class="empty-chart"><span style="font-size:32px">✅</span>No hay bajas registradas</div>
                <?php else: ?>
                <div class="chart-wrap" style="height:260px">
                    <canvas id="chartBajasPorArea"></canvas>
                </div>
                <?php endif; ?>
            </div>

            <!-- Motivo de bajas — pastel -->
            <div class="chart-card">
                <div class="chart-card-title">🥧 Motivos de Baja</div>
                <div class="chart-card-sub">Causas principales por las que se dan de baja los equipos</div>
                <?php
                try {
                    $motivosBaja = $db->query("
                        SELECT motivo_baja, COUNT(*) as total
                        FROM Bajas
                        GROUP BY motivo_baja
                        ORDER BY total DESC
                    ")->fetchAll();
                } catch (Exception $e) { $motivosBaja = []; }
                ?>
                <?php if (empty($motivosBaja)): ?>
                    <div class="empty-chart"><span style="font-size:32px">📛</span>No hay bajas registradas</div>
                <?php else: ?>
                <div class="chart-wrap chart-wrap-pie" style="height:260px">
                    <canvas id="chartMotivosBaja"></canvas>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </main>
</div>

<script>
// ============================================
// Configuración global de Chart.js — tema oscuro
// ============================================
Chart.defaults.color          = '#8b949e';
Chart.defaults.borderColor    = '#30363d';
Chart.defaults.font.family    = "'DM Sans', sans-serif";
Chart.defaults.font.size      = 12;
Chart.defaults.plugins.legend.labels.padding     = 16;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyleWidth = 10;

const COLORES = [
    '#58a6ff','#3fb950','#d29922','#f85149',
    '#a371f7','#79c0ff','#ff9e64','#56d364',
    '#ffa657','#ff7b72'
];

function makeGradient(ctx, color) {
    const g = ctx.createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, color + 'cc');
    g.addColorStop(1, color + '22');
    return g;
}

// ============================================
// 1. Equipos por Área — Barras verticales
// ============================================
<?php if (!empty($equiposPorArea)): ?>
(function() {
    const ctx  = document.getElementById('chartEquiposPorArea');
    const labels = <?= jsonLabels($equiposPorArea, 'nombre_area') ?>;
    const data   = <?= jsonValues($equiposPorArea, 'total') ?>;
    const colors = labels.map((_,i) => COLORES[i % COLORES.length]);

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Equipos',
                data,
                backgroundColor: colors.map(c => c + '99'),
                borderColor: colors,
                borderWidth: 2,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: '#21262d' }, ticks: { maxRotation: 30 } },
                y: { grid: { color: '#21262d' }, beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
})();
<?php endif; ?>

// ============================================
// 2. Estado de Equipos — Dona
// ============================================
<?php if (!empty($estadoEquipos)): ?>
(function() {
    const ctx    = document.getElementById('chartEstadoEquipos');
    const labels = <?= jsonLabels($estadoEquipos, 'estado') ?>;
    const data   = <?= jsonValues($estadoEquipos, 'total') ?>;
    const colors = ['#3fb950','#8b949e','#d29922','#f85149'];

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data, backgroundColor: colors, borderColor: '#161b22', borderWidth: 3, hoverOffset: 8 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.raw} equipo${ctx.raw !== 1 ? 's' : ''}`
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

// ============================================
// 3. Mantenimientos por Mes — Barras agrupadas
// ============================================
<?php if (!empty($mttosPorMes)): ?>
(function() {
    const ctx    = document.getElementById('chartMttosPorMes');
    const labels = <?= jsonLabels($mttosPorMes, 'mes_label') ?>;
    const prev   = <?= jsonValues($mttosPorMes, 'preventivos') ?>;
    const corr   = <?= jsonValues($mttosPorMes, 'correctivos') ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: '🛡 Preventivos',
                    data: prev,
                    backgroundColor: '#3fb95099',
                    borderColor: '#3fb950',
                    borderWidth: 2,
                    borderRadius: 5,
                    borderSkipped: false,
                },
                {
                    label: '🔨 Correctivos',
                    data: corr,
                    backgroundColor: '#f8514999',
                    borderColor: '#f85149',
                    borderWidth: 2,
                    borderRadius: 5,
                    borderSkipped: false,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                x: { grid: { color: '#21262d' } },
                y: { grid: { color: '#21262d' }, beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
})();
<?php endif; ?>

// ============================================
// 4. Tipo de Mantenimiento — Pastel
// ============================================
<?php if (!empty($tipoMtto)): ?>
(function() {
    const ctx    = document.getElementById('chartTipoMtto');
    const labels = <?= jsonLabels($tipoMtto, 'tipo_mantenimiento') ?>;
    const data   = <?= jsonValues($tipoMtto, 'total') ?>;

    new Chart(ctx, {
        type: 'pie',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: ['#3fb95099','#f8514999'],
                borderColor:     ['#3fb950',  '#f85149'],
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                            const pct   = total > 0 ? Math.round(ctx.raw/total*100) : 0;
                            return ` ${ctx.label}: ${ctx.raw} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

// ============================================
// 5. Bajas por Área — Barras horizontales
// ============================================
<?php if (!empty($bajasPorArea)): ?>
(function() {
    const ctx    = document.getElementById('chartBajasPorArea');
    const labels = <?= jsonLabels($bajasPorArea, 'nombre_area') ?>;
    const data   = <?= jsonValues($bajasPorArea, 'total') ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Bajas',
                data,
                backgroundColor: '#f8514999',
                borderColor:     '#f85149',
                borderWidth: 2,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: '#21262d' }, beginAtZero: true, ticks: { stepSize: 1 } },
                y: { grid: { color: '#21262d' } }
            }
        }
    });
})();
<?php endif; ?>

// ============================================
// 6. Motivos de Baja — Pastel
// ============================================
<?php if (!empty($motivosBaja)): ?>
(function() {
    const ctx    = document.getElementById('chartMotivosBaja');
    const labels = <?= jsonLabels($motivosBaja, 'motivo_baja') ?>;
    const data   = <?= jsonValues($motivosBaja, 'total') ?>;
    const colors = COLORES.slice(0, labels.length);

    new Chart(ctx, {
        type: 'pie',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colors.map(c => c + '99'),
                borderColor:     colors,
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                            const pct   = total > 0 ? Math.round(ctx.raw/total*100) : 0;
                            return ` ${ctx.label}: ${ctx.raw} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>
</script>
</body>
</html>