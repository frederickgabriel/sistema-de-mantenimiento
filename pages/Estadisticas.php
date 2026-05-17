<?php
require_once '../includes/config.php';
requireLogin();

$db = getDB();

// 1. Equipos por área
$equiposPorArea = $db->query("
    SELECT a.nombre_area, COUNT(e.numero_inventario) as total
    FROM Areas a LEFT JOIN Equipos e ON e.id_area = a.id_area
    GROUP BY a.id_area, a.nombre_area ORDER BY total DESC
")->fetchAll();

// 2. Bajas por área
try {
    $bajasPorArea = $db->query("
        SELECT a.nombre_area, COUNT(b.id_baja) as total
        FROM Areas a
        INNER JOIN Equipos e ON e.id_area = a.id_area
        INNER JOIN Bajas b ON b.numero_inventario = e.numero_inventario
        GROUP BY a.id_area, a.nombre_area ORDER BY total DESC
    ")->fetchAll();
} catch (Exception $e) { $bajasPorArea = []; }

// 3. Top equipos con más mantenimientos
$topEquipos = $db->query("
    SELECT m.numero_inventario, e.modelo,
           COUNT(*) as total,
           SUM(m.tipo_mantenimiento='Preventivo') as preventivos,
           SUM(m.tipo_mantenimiento='Correctivo') as correctivos
    FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario
    GROUP BY m.numero_inventario, e.modelo ORDER BY total DESC LIMIT 10
")->fetchAll();

// 4. Mantenimientos por mes (últimos 12 meses)
$porMes = $db->query("
    SELECT DATE_FORMAT(fecha_realizacion,'%b %Y') as mes_label,
           DATE_FORMAT(fecha_realizacion,'%Y-%m') as mes_order,
           COUNT(*) as total,
           SUM(tipo_mantenimiento='Preventivo') as preventivos,
           SUM(tipo_mantenimiento='Correctivo') as correctivos
    FROM Mantenimientos
    WHERE fecha_realizacion >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY mes_order, mes_label ORDER BY mes_order ASC
")->fetchAll();

// 5. Estado equipos
$estadosEq = $db->query("SELECT estado, COUNT(*) as total FROM Equipos GROUP BY estado")->fetchAll();

// 6. Motivos de baja
try {
    $motivosBaja = $db->query("SELECT motivo_baja, COUNT(*) as total FROM Bajas GROUP BY motivo_baja ORDER BY total DESC")->fetchAll();
} catch (Exception $e) { $motivosBaja = []; }

// KPIs
$kpi = [
    'total'       => $db->query("SELECT COUNT(*) FROM Mantenimientos")->fetchColumn(),
    'preventivos' => $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE tipo_mantenimiento='Preventivo'")->fetchColumn(),
    'correctivos' => $db->query("SELECT COUNT(*) FROM Mantenimientos WHERE tipo_mantenimiento='Correctivo'")->fetchColumn(),
    'equipos'     => $db->query("SELECT COUNT(*) FROM Equipos")->fetchColumn(),
    'activos'     => $db->query("SELECT COUNT(*) FROM Equipos WHERE estado='Activo'")->fetchColumn(),
];
try {
    $kpi['bajas'] = $db->query("SELECT COUNT(*) FROM Bajas")->fetchColumn();
    $masCorrectivo = $db->query("
        SELECT m.numero_inventario, e.modelo, COUNT(*) as total
        FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario
        WHERE m.tipo_mantenimiento='Correctivo'
        GROUP BY m.numero_inventario, e.modelo ORDER BY total DESC LIMIT 1
    ")->fetch();
} catch (Exception $e) { $kpi['bajas'] = 0; $masCorrectivo = null; }

$areaMasEq = $db->query("
    SELECT a.nombre_area, COUNT(e.numero_inventario) as total
    FROM Areas a LEFT JOIN Equipos e ON e.id_area=a.id_area
    GROUP BY a.id_area ORDER BY total DESC LIMIT 1
")->fetch();

// JSON para Chart.js
$chartData = json_encode([
    'equiposPorArea' => ['labels' => array_column($equiposPorArea,'nombre_area'), 'data' => array_map('intval', array_column($equiposPorArea,'total'))],
    'bajasPorArea'   => ['labels' => array_column($bajasPorArea,'nombre_area'),   'data' => array_map('intval', array_column($bajasPorArea,'total'))],
    'topEquipos'     => ['labels' => array_map(fn($r)=>$r['numero_inventario'].' ('.$r['modelo'].')', $topEquipos), 'preventivos' => array_map('intval',array_column($topEquipos,'preventivos')), 'correctivos' => array_map('intval',array_column($topEquipos,'correctivos'))],
    'porMes'         => ['labels' => array_column($porMes,'mes_label'), 'preventivos' => array_map('intval',array_column($porMes,'preventivos')), 'correctivos' => array_map('intval',array_column($porMes,'correctivos')), 'total' => array_map('intval',array_column($porMes,'total'))],
    'estados'        => ['labels' => array_column($estadosEq,'estado'), 'data' => array_map('intval',array_column($estadosEq,'total'))],
    'motivosBaja'    => ['labels' => array_column($motivosBaja,'motivo_baja'), 'data' => array_map('intval',array_column($motivosBaja,'total'))],
], JSON_UNESCAPED_UNICODE);
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
        .section-sep {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: var(--text-muted);
            margin: 28px 0 16px; display: flex; align-items: center; gap: 10px;
        }
        .section-sep::after { content:''; flex:1; height:1px; background:var(--border); }

        .g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .g1 { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px; }

        .ch-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 20px 22px;
        }
        .ch-title { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 3px; }
        .ch-sub   { font-size: 12px; color: var(--text-muted); margin-bottom: 16px; }
        .ch-wrap  { position: relative; }
        .no-data  { text-align:center; padding:32px; color:var(--text-muted); font-size:13px; }

        .kpi-row  { display: grid; grid-template-columns: repeat(auto-fit,minmax(155px,1fr)); gap:14px; margin-bottom:28px; }
        .kpi-box  { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-md); padding:16px 18px; text-align:center; }
        .kpi-ico  { font-size:24px; margin-bottom:6px; }
        .kpi-val  { font-family:var(--font-mono); font-size:28px; font-weight:700; line-height:1; margin-bottom:3px; }
        .kpi-lbl  { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
        .kpi-sub  { font-size:11px; color:var(--text-secondary); margin-top:5px; padding-top:5px; border-top:1px solid var(--border-light); }

        @media(max-width:768px){ .g2{ grid-template-columns:1fr; } }
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

        <!-- KPIs -->
        <div class="kpi-row">
            <div class="kpi-box">
                <div class="kpi-ico">🔧</div>
                <div class="kpi-val" style="color:var(--accent)"><?= $kpi['total'] ?></div>
                <div class="kpi-lbl">Total Mantenimientos</div>
                <div class="kpi-sub">🛡 <?= $kpi['preventivos'] ?> prev · 🔨 <?= $kpi['correctivos'] ?> corr</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-ico">🛡</div>
                <div class="kpi-val" style="color:var(--success)"><?= $kpi['preventivos'] ?></div>
                <div class="kpi-lbl">Preventivos</div>
                <div class="kpi-sub"><?= $kpi['total'] > 0 ? round(($kpi['preventivos']/$kpi['total'])*100) : 0 ?>% del total</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-ico">🔨</div>
                <div class="kpi-val" style="color:var(--warning)"><?= $kpi['correctivos'] ?></div>
                <div class="kpi-lbl">Correctivos</div>
                <div class="kpi-sub"><?= $kpi['total'] > 0 ? round(($kpi['correctivos']/$kpi['total'])*100) : 0 ?>% del total</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-ico">🖥</div>
                <div class="kpi-val" style="color:var(--purple)"><?= $kpi['equipos'] ?></div>
                <div class="kpi-lbl">Total Equipos</div>
                <div class="kpi-sub">✅ <?= $kpi['activos'] ?> activos</div>
            </div>
            <div class="kpi-box">
                <div class="kpi-ico">📛</div>
                <div class="kpi-val" style="color:var(--danger)"><?= $kpi['bajas'] ?></div>
                <div class="kpi-lbl">Bajas Registradas</div>
                <div class="kpi-sub">equipos fuera de servicio</div>
            </div>
            <?php if ($masCorrectivo): ?>
            <div class="kpi-box">
                <div class="kpi-ico">⚠</div>
                <div class="kpi-val" style="color:var(--danger);font-size:18px"><?= e($masCorrectivo['numero_inventario']) ?></div>
                <div class="kpi-lbl">Más Correctivos</div>
                <div class="kpi-sub"><?= e(mb_substr($masCorrectivo['modelo'],0,20)) ?> · <?= $masCorrectivo['total'] ?>x</div>
            </div>
            <?php endif; ?>
            <?php if ($areaMasEq): ?>
            <div class="kpi-box">
                <div class="kpi-ico">🏫</div>
                <div class="kpi-val" style="color:var(--info);font-size:16px;word-break:break-word"><?= e(mb_substr($areaMasEq['nombre_area'],0,18)) ?></div>
                <div class="kpi-lbl">Área con más Equipos</div>
                <div class="kpi-sub"><?= $areaMasEq['total'] ?> equipos</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sección 1: Inventario -->
        <div class="section-sep">🖥 Inventario y Distribución</div>
        <div class="g2">
            <div class="ch-card">
                <div class="ch-title">📊 Equipos por Área</div>
                <div class="ch-sub">Cantidad de equipos en cada área o salón</div>
                <div class="ch-wrap" style="height:260px"><canvas id="cArea"></canvas></div>
            </div>
            <div class="ch-card">
                <div class="ch-title">🟢 Estado de Equipos</div>
                <div class="ch-sub">Distribución actual por estado</div>
                <div class="ch-wrap" style="height:260px"><canvas id="cEstados"></canvas></div>
            </div>
        </div>

        <!-- Sección 2: Mantenimientos -->
        <div class="section-sep">🔧 Mantenimientos</div>
        <div class="g1">
            <div class="ch-card">
                <div class="ch-title">📅 Mantenimientos por Mes — Últimos 12 meses</div>
                <div class="ch-sub">Preventivos (verde) vs Correctivos (rojo) mes a mes</div>
                <?php if (empty($porMes)): ?>
                    <div class="no-data">No hay mantenimientos en los últimos 12 meses</div>
                <?php else: ?>
                <div class="ch-wrap" style="height:280px"><canvas id="cMes"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="g1">
            <div class="ch-card">
                <div class="ch-title">🔩 Top 10 Equipos con más Mantenimientos</div>
                <div class="ch-sub">Equipos que han requerido más intervenciones técnicas</div>
                <?php if (empty($topEquipos)): ?>
                    <div class="no-data">Sin mantenimientos registrados</div>
                <?php else: ?>
                <div class="ch-wrap" style="height:<?= max(200, count($topEquipos)*42) ?>px"><canvas id="cTop"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sección 3: Bajas -->
        <div class="section-sep">📛 Bajas de Equipos</div>
        <div class="g2">
            <div class="ch-card">
                <div class="ch-title">🏫 Bajas por Área</div>
                <div class="ch-sub">Áreas con mayor número de equipos dados de baja</div>
                <?php if (empty($bajasPorArea) || array_sum(array_column($bajasPorArea,'total')) == 0): ?>
                    <div class="no-data">📭 No hay bajas registradas aún</div>
                <?php else: ?>
                <div class="ch-wrap" style="height:260px"><canvas id="cBajasArea"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="ch-card">
                <div class="ch-title">❓ Motivos de Baja</div>
                <div class="ch-sub">Razones por las que se dieron de baja los equipos</div>
                <?php if (empty($motivosBaja)): ?>
                    <div class="no-data">📭 No hay bajas registradas aún</div>
                <?php else: ?>
                <div class="ch-wrap" style="height:260px"><canvas id="cMotivos"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<script>
const D = <?= $chartData ?>;

// Config global
Chart.defaults.color       = '#8b949e';
Chart.defaults.borderColor = '#30363d';
Chart.defaults.font.family = "DM Sans, sans-serif";
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.padding = 14;
Chart.defaults.plugins.legend.labels.boxWidth = 10;

const TIP = { backgroundColor:'#1c2333', borderColor:'#30363d', borderWidth:1, padding:10 };

const PAL = [
    'rgba(88,166,255,.8)','rgba(63,185,80,.8)','rgba(210,153,34,.8)',
    'rgba(248,81,73,.8)','rgba(163,113,247,.8)','rgba(121,192,255,.8)',
    'rgba(255,163,72,.8)','rgba(255,121,121,.8)','rgba(72,191,255,.8)','rgba(255,214,0,.8)'
];
const PAL_BD = PAL.map(c => c.replace('.8)',  '1)'));

const scaleOpts = {
    x: { grid:{color:'#21262d'}, ticks:{color:'#8b949e'} },
    y: { grid:{color:'#21262d'}, ticks:{color:'#8b949e', precision:0}, beginAtZero:true }
};

// 1. Equipos por Área (barra vertical)
if (document.getElementById('cArea') && D.equiposPorArea.data.length)
    new Chart(document.getElementById('cArea'), {
        type: 'bar',
        data: {
            labels: D.equiposPorArea.labels,
            datasets: [{ label:'Equipos', data: D.equiposPorArea.data,
                backgroundColor: D.equiposPorArea.data.map((_,i)=>PAL[i%PAL.length]),
                borderColor:     D.equiposPorArea.data.map((_,i)=>PAL_BD[i%PAL.length]),
                borderWidth:1, borderRadius:6 }]
        },
        options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:TIP }, scales: scaleOpts }
    });

// 2. Estados Equipos (donut)
if (document.getElementById('cEstados') && D.estados.data.length) {
    const colMap = { 'Activo':'rgba(63,185,80,.85)', 'Inactivo':'rgba(139,148,158,.85)', 'En Reparacion':'rgba(210,153,34,.85)', 'Baja':'rgba(248,81,73,.85)' };
    new Chart(document.getElementById('cEstados'), {
        type: 'doughnut',
        data: { labels: D.estados.labels, datasets:[{
            data: D.estados.data,
            backgroundColor: D.estados.labels.map(l => colMap[l] || PAL[0]),
            borderColor:'#161b22', borderWidth:3
        }]},
        options: { responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{position:'bottom'}, tooltip:TIP } }
    });
}

// 3. Mantenimientos por Mes (barras apiladas + línea)
if (document.getElementById('cMes') && D.porMes.labels.length)
    new Chart(document.getElementById('cMes'), {
        type: 'bar',
        data: {
            labels: D.porMes.labels,
            datasets: [
                { label:'🛡 Preventivos', data: D.porMes.preventivos, backgroundColor:'rgba(63,185,80,.75)', borderColor:'rgba(63,185,80,1)', borderWidth:1, borderRadius:4 },
                { label:'🔨 Correctivos', data: D.porMes.correctivos, backgroundColor:'rgba(248,81,73,.75)',  borderColor:'rgba(248,81,73,1)',  borderWidth:1, borderRadius:4 },
                { label:'📊 Total', data: D.porMes.total, type:'line',
                  borderColor:'rgba(88,166,255,1)', backgroundColor:'rgba(88,166,255,.06)',
                  borderWidth:2, pointBackgroundColor:'rgba(88,166,255,1)', pointRadius:4,
                  fill:true, tension:0.3, yAxisID:'y' }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:true, position:'top'}, tooltip:TIP },
            scales: {
                x: { ...scaleOpts.x, stacked:true },
                y: { ...scaleOpts.y, stacked:true }
            }
        }
    });

// 4. Top Equipos (barras horizontales apiladas)
if (document.getElementById('cTop') && D.topEquipos.labels.length)
    new Chart(document.getElementById('cTop'), {
        type: 'bar',
        data: {
            labels: D.topEquipos.labels,
            datasets: [
                { label:'🛡 Preventivos', data: D.topEquipos.preventivos, backgroundColor:'rgba(63,185,80,.75)', borderColor:'rgba(63,185,80,1)', borderWidth:1, borderRadius:4 },
                { label:'🔨 Correctivos', data: D.topEquipos.correctivos, backgroundColor:'rgba(248,81,73,.75)',  borderColor:'rgba(248,81,73,1)',  borderWidth:1, borderRadius:4 }
            ]
        },
        options: {
            indexAxis:'y', responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:true, position:'top'}, tooltip:TIP },
            scales: {
                x: { ...scaleOpts.x, stacked:true, beginAtZero:true },
                y: { grid:{color:'transparent'}, ticks:{color:'#8b949e'}, stacked:true }
            }
        }
    });

// 5. Bajas por Área (pie)
if (document.getElementById('cBajasArea') && D.bajasPorArea.data.some(v=>v>0))
    new Chart(document.getElementById('cBajasArea'), {
        type: 'pie',
        data: { labels: D.bajasPorArea.labels, datasets:[{ data: D.bajasPorArea.data, backgroundColor: PAL, borderColor:'#161b22', borderWidth:3 }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'bottom'}, tooltip:TIP } }
    });

// 6. Motivos de Baja (donut)
if (document.getElementById('cMotivos') && D.motivosBaja.data.length)
    new Chart(document.getElementById('cMotivos'), {
        type: 'doughnut',
        data: { labels: D.motivosBaja.labels, datasets:[{ data: D.motivosBaja.data,
            backgroundColor:['rgba(248,81,73,.8)','rgba(210,153,34,.8)','rgba(163,113,247,.8)','rgba(88,166,255,.8)','rgba(63,185,80,.8)','rgba(255,163,72,.8)'],
            borderColor:'#161b22', borderWidth:3 }] },
        options: { responsive:true, maintainAspectRatio:false, cutout:'55%', plugins:{ legend:{position:'bottom'}, tooltip:TIP } }
    });
</script>
</body>
</html>