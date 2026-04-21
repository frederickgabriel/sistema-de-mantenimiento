<?php
// =============================================
// CALENDARIO DE EVENTOS
// Archivo: pages/calendario.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db = getDB();

// Mes y año actual (o el que venga por GET)
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('m');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');

// Normalizar mes
if ($mes < 1)  { $mes = 12; $anio--; }
if ($mes > 12) { $mes = 1;  $anio++; }

$mesAnterior = $mes - 1; $anioAnterior = $anio;
if ($mesAnterior < 1) { $mesAnterior = 12; $anioAnterior--; }
$mesSiguiente = $mes + 1; $anioSiguiente = $anio;
if ($mesSiguiente > 12) { $mesSiguiente = 1; $anioSiguiente++; }

$primerDia   = mktime(0,0,0, $mes, 1, $anio);
$diasEnMes   = (int)date('t', $primerDia);
$diaSemana   = (int)date('N', $primerDia); // 1=Lun, 7=Dom

$nombresMes  = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// ---- Cargar todos los eventos del mes ----
$inicio = "{$anio}-" . str_pad($mes,2,'0',STR_PAD_LEFT) . "-01";
$fin    = "{$anio}-" . str_pad($mes,2,'0',STR_PAD_LEFT) . "-{$diasEnMes}";

// Próximos mantenimientos del mes
$evMttos = $db->prepare("
    SELECT m.proximo_mantenimiento as fecha, e.numero_inventario, e.modelo, a.nombre_area,
           'mantenimiento' as tipo
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    WHERE m.proximo_mantenimiento BETWEEN ? AND ?
    AND m.id_mantenimiento IN (
        SELECT MAX(id_mantenimiento) FROM Mantenimientos GROUP BY numero_inventario
    )
");
$evMttos->execute([$inicio, $fin]);
$evMttos = $evMttos->fetchAll();

// Fechas de realización de mantenimientos del mes
$evRealizados = $db->prepare("
    SELECT m.fecha_realizacion as fecha, e.numero_inventario, e.modelo, a.nombre_area,
           m.tipo_mantenimiento, 'realizado' as tipo
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    WHERE m.fecha_realizacion BETWEEN ? AND ?
");
$evRealizados->execute([$inicio, $fin]);
$evRealizados = $evRealizados->fetchAll();

// Tareas programadas del mes
$evTareas = $db->prepare("
    SELECT t.fecha_programada as fecha, t.nombre_tarea, t.estado, t.prioridad,
           e.modelo, 'tarea' as tipo
    FROM Tareas t
    LEFT JOIN Equipos e ON e.numero_inventario = t.numero_inventario
    WHERE t.fecha_programada BETWEEN ? AND ?
");
$evTareas->execute([$inicio, $fin]);
$evTareas = $evTareas->fetchAll();

// Fechas de entregas de mantenimiento
$evEntregas = $db->prepare("
    SELECT m.fecha_entrega as fecha, e.numero_inventario, e.modelo, a.nombre_area,
           'entrega' as tipo
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    WHERE m.fecha_entrega BETWEEN ? AND ?
");
$evEntregas->execute([$inicio, $fin]);
$evEntregas = $evEntregas->fetchAll();

// Agrupar todos los eventos por fecha (día como clave)
$eventos = [];
foreach ($evMttos as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $eventos[$d][] = ['tipo' => 'proximo', 'texto' => "🔔 Próx: {$ev['numero_inventario']} — {$ev['modelo']}", 'color' => 'ev-warning'];
}
foreach ($evRealizados as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $icon = $ev['tipo_mantenimiento'] === 'Preventivo' ? '🛡' : '🔨';
    $eventos[$d][] = ['tipo' => 'realizado', 'texto' => "{$icon} {$ev['numero_inventario']} — {$ev['tipo_mantenimiento']}", 'color' => 'ev-success'];
}
foreach ($evTareas as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $icon = $ev['estado'] === 'Realizado' ? '✅' : ($ev['prioridad'] === 'Alta' ? '🔴' : '📋');
    $eventos[$d][] = ['tipo' => 'tarea', 'texto' => "{$icon} Tarea: {$ev['nombre_tarea']}", 'color' => 'ev-info'];
}
foreach ($evEntregas as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $eventos[$d][] = ['tipo' => 'entrega', 'texto' => "📦 Entrega: {$ev['numero_inventario']}", 'color' => 'ev-purple'];
}

// Lista de eventos del mes ordenados por fecha (para panel lateral)
$todosEventos = [];
foreach ($evMttos     as $ev) $todosEventos[] = ['fecha' => $ev['fecha'], 'tipo' => 'proximo',   'desc' => "Próx. mantenimiento: {$ev['numero_inventario']} — {$ev['modelo']} ({$ev['nombre_area']})"];
foreach ($evRealizados as $ev) $todosEventos[] = ['fecha' => $ev['fecha'], 'tipo' => 'realizado', 'desc' => "Mantenimiento {$ev['tipo_mantenimiento']}: {$ev['numero_inventario']} — {$ev['modelo']}"];
foreach ($evTareas    as $ev) $todosEventos[] = ['fecha' => $ev['fecha'], 'tipo' => 'tarea',     'desc' => "Tarea [{$ev['estado']}]: {$ev['nombre_tarea']}" . ($ev['modelo'] ? " ({$ev['modelo']})" : '')];
foreach ($evEntregas  as $ev) $todosEventos[] = ['fecha' => $ev['fecha'], 'tipo' => 'entrega',   'desc' => "Entrega equipo: {$ev['numero_inventario']} — {$ev['modelo']}"];
usort($todosEventos, fn($a,$b) => strcmp($a['fecha'], $b['fecha']));

$hoy = date('Y-m-d');
$diaHoy = (date('m') == $mes && date('Y') == $anio) ? (int)date('j') : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
    <style>
        /* ---- Calendario ---- */
        .cal-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 24px;
            align-items: start;
        }

        .cal-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .cal-title {
            font-family: var(--font-mono);
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .cal-dow {
            background: var(--bg-card2);
            padding: 10px 4px;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }

        .cal-cell {
            min-height: 90px;
            padding: 6px;
            border-right: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
            background: var(--bg-card);
            vertical-align: top;
            position: relative;
            transition: background .15s;
        }

        .cal-cell:nth-child(7n) { border-right: none; }
        .cal-cell.empty { background: var(--bg-main); opacity: .4; }
        .cal-cell.hoy { background: rgba(88,166,255,.06); outline: 2px solid var(--accent) inset; }
        .cal-cell:hover:not(.empty) { background: var(--bg-hover); }

        .cal-day-num {
            font-family: var(--font-mono);
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cal-cell.hoy .cal-day-num { color: var(--accent); }

        .cal-day-badge {
            width: 22px; height: 22px;
            background: var(--accent);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        .cal-ev {
            display: block;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 10px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: default;
        }

        .ev-warning { background: rgba(210,153,34,.2);  color: var(--warning); }
        .ev-success { background: rgba(63,185,80,.2);   color: var(--success); }
        .ev-info    { background: rgba(88,166,255,.2);  color: var(--info); }
        .ev-purple  { background: rgba(163,113,247,.2); color: var(--purple); }
        .ev-danger  { background: rgba(248,81,73,.2);   color: var(--danger); }

        .ev-more {
            font-size: 10px;
            color: var(--text-muted);
            padding: 1px 5px;
            cursor: pointer;
        }

        /* Panel lateral de eventos */
        .ev-panel { position: sticky; top: 24px; }

        .ev-list-item {
            display: flex;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .ev-list-item:last-child { border-bottom: none; }

        .ev-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
        }
        .dot-proximo  { background: var(--warning); }
        .dot-realizado{ background: var(--success); }
        .dot-tarea    { background: var(--info); }
        .dot-entrega  { background: var(--purple); }

        .ev-list-fecha { font-size: 11px; color: var(--text-muted); margin-bottom: 2px; }
        .ev-list-desc  { font-size: 13px; color: var(--text-primary); line-height: 1.4; }

        /* Leyenda */
        .leyenda {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .leyenda-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .leyenda-dot {
            width: 10px; height: 10px;
            border-radius: 2px;
        }

        @media (max-width: 900px) {
            .cal-layout { grid-template-columns: 1fr; }
            .cal-cell { min-height: 60px; }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">📅 Calendario</div>
                <div class="page-subtitle">Mantenimientos, tareas y fechas importantes</div>
            </div>
        </div>

        <!-- Leyenda -->
        <div class="leyenda">
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--success)"></div> Mantenimiento realizado</div>
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--warning)"></div> Próximo mantenimiento</div>
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--info)"></div> Tarea programada</div>
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--purple)"></div> Entrega de equipo</div>
        </div>

        <div class="cal-layout">

            <!-- CALENDARIO -->
            <div>
                <!-- Navegación mes -->
                <div class="cal-nav">
                    <a href="?mes=<?= $mesAnterior ?>&anio=<?= $anioAnterior ?>" class="btn btn-ghost btn-sm">← Anterior</a>
                    <span class="cal-title"><?= $nombresMes[$mes] ?> <?= $anio ?></span>
                    <a href="?mes=<?= $mesSiguiente ?>&anio=<?= $anioSiguiente ?>" class="btn btn-ghost btn-sm">Siguiente →</a>
                </div>

                <!-- Grid del calendario -->
                <div class="cal-grid">
                    <!-- Días de la semana -->
                    <?php foreach(['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dow): ?>
                        <div class="cal-dow"><?= $dow ?></div>
                    <?php endforeach; ?>

                    <!-- Celdas vacías al inicio -->
                    <?php for ($i = 1; $i < $diaSemana; $i++): ?>
                        <div class="cal-cell empty"></div>
                    <?php endfor; ?>

                    <!-- Días del mes -->
                    <?php for ($dia = 1; $dia <= $diasEnMes; $dia++): ?>
                        <?php
                        $esHoy   = ($dia === $diaHoy);
                        $evsDia  = $eventos[$dia] ?? [];
                        $maxShow = 3;
                        $resto   = max(0, count($evsDia) - $maxShow);
                        ?>
                        <div class="cal-cell <?= $esHoy ? 'hoy' : '' ?>">
                            <div class="cal-day-num">
                                <?php if ($esHoy): ?>
                                    <div class="cal-day-badge"><?= $dia ?></div>
                                <?php else: ?>
                                    <span><?= $dia ?></span>
                                <?php endif; ?>
                            </div>
                            <?php foreach(array_slice($evsDia, 0, $maxShow) as $ev): ?>
                                <span class="cal-ev <?= $ev['color'] ?>" title="<?= e($ev['texto']) ?>">
                                    <?= e($ev['texto']) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($resto > 0): ?>
                                <span class="ev-more">+<?= $resto ?> más</span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>

                    <!-- Celdas vacías al final para completar última semana -->
                    <?php
                    $ultimoDiaSemana = (int)date('N', mktime(0,0,0,$mes,$diasEnMes,$anio));
                    for ($i = $ultimoDiaSemana; $i < 7; $i++):
                    ?>
                        <div class="cal-cell empty"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- PANEL LATERAL: Lista de eventos del mes -->
            <div class="ev-panel">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title" style="font-size:14px">📌 Eventos de <?= $nombresMes[$mes] ?></div>
                        <span class="text-muted" style="font-size:12px"><?= count($todosEventos) ?> eventos</span>
                    </div>
                    <div class="card-body" style="padding:0 16px;max-height:520px;overflow-y:auto">
                        <?php if (empty($todosEventos)): ?>
                            <div class="empty-state" style="padding:28px 0">
                                <span class="empty-icon" style="font-size:32px">📭</span>
                                <p>Sin eventos este mes.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($todosEventos as $ev): ?>
                            <div class="ev-list-item">
                                <div class="ev-dot dot-<?= $ev['tipo'] ?>"></div>
                                <div>
                                    <div class="ev-list-fecha"><?= fechaES($ev['fecha']) ?></div>
                                    <div class="ev-list-desc"><?= e($ev['desc']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Resumen del mes -->
                <div class="card" style="margin-top:16px">
                    <div class="card-header">
                        <div class="card-title" style="font-size:14px">📊 Resumen del mes</div>
                    </div>
                    <div class="card-body">
                        <?php
                        $countTipos = [
                            'Próximos mantenimientos' => count($evMttos),
                            'Mantenimientos realizados'=> count($evRealizados),
                            'Tareas programadas'       => count($evTareas),
                            'Entregas'                 => count($evEntregas),
                        ];
                        $colors = ['warning','success','info','purple'];
                        $i = 0;
                        foreach ($countTipos as $label => $cnt):
                            $c = $colors[$i++];
                        ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border-light)">
                            <span style="font-size:13px;color:var(--text-secondary)"><?= $label ?></span>
                            <span style="font-family:var(--font-mono);font-size:15px;font-weight:700;color:var(--<?= $c ?>)"><?= $cnt ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>