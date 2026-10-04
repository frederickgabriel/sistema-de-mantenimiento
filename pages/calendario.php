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
    WHERE e.estado != 'Baja' AND m.proximo_mantenimiento BETWEEN ? AND ?
    AND m.id_mantenimiento IN (
        SELECT MAX(id_mantenimiento) FROM Mantenimientos GROUP BY numero_inventario
    )
");
$evMttos->execute([$inicio, $fin]);
$evMttos = $evMttos->fetchAll();

// Fechas de realización de mantenimientos del mes
$evRealizados = $db->prepare("
    SELECT m.fecha_realizacion as fecha, e.numero_inventario, e.modelo, a.nombre_area,
           m.tipo_mantenimiento, m.estado, u.nombre as tecnico, 'realizado' as tipo
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = m.id_tecnico
    WHERE m.fecha_realizacion BETWEEN ? AND ?
");
$evRealizados->execute([$inicio, $fin]);
$evRealizados = $evRealizados->fetchAll();

// Tareas programadas del mes
$evTareas = $db->prepare("
    SELECT t.fecha_programada as fecha, t.nombre_tarea, t.estado, t.prioridad,
           e.modelo, e.numero_inventario, ua.nombre as asignado, 'tarea' as tipo
    FROM Tareas t
    LEFT JOIN Equipos e ON e.numero_inventario = t.numero_inventario
    LEFT JOIN Usuarios ua ON ua.id_usuario = t.id_usuario_asignado
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
    $eventos[$d][] = ['tipo' => 'proximo', 'icon' => 'notifications', 'texto' => "Próx: {$ev['numero_inventario']} — {$ev['modelo']}", 'color' => 'ev-warning'];
}
foreach ($evRealizados as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $icon = $ev['tipo_mantenimiento'] === 'Preventivo' ? 'shield' : 'handyman';
    $eventos[$d][] = ['tipo' => 'realizado', 'icon' => $icon, 'texto' => "{$ev['numero_inventario']} — {$ev['tipo_mantenimiento']}", 'color' => 'ev-success'];
}
foreach ($evTareas as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $icon = $ev['estado'] === 'Realizado' ? 'check_circle' : ($ev['prioridad'] === 'Alta' ? 'circle' : 'checklist');
    $eventos[$d][] = ['tipo' => 'tarea', 'icon' => $icon, 'texto' => "Tarea: {$ev['nombre_tarea']}", 'color' => 'ev-info'];
}
foreach ($evEntregas as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $eventos[$d][] = ['tipo' => 'entrega', 'icon' => 'inventory_2', 'texto' => "Entrega: {$ev['numero_inventario']}", 'color' => 'ev-purple'];
}

// Detalle por día para la vista previa (se manda como JSON al navegador)
$urlMtto = fn($inv) => '/pages/mantenimientos.php?equipo=' . urlencode($inv);
$detalle = [];
foreach ($evMttos as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $detalle[$d][] = ['tipo' => 'proximo', 'icon' => 'notifications', 'titulo' => 'Próximo mantenimiento',
        'equipo' => "{$ev['numero_inventario']} — {$ev['modelo']}", 'area' => $ev['nombre_area'] ?? '',
        'estado' => '', 'href' => $urlMtto($ev['numero_inventario'])];
}
foreach ($evRealizados as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $detalle[$d][] = ['tipo' => 'realizado', 'icon' => $ev['tipo_mantenimiento'] === 'Preventivo' ? 'shield' : 'handyman',
        'titulo' => "Mantenimiento {$ev['tipo_mantenimiento']}", 'equipo' => "{$ev['numero_inventario']} — {$ev['modelo']}",
        'area' => $ev['nombre_area'] ?? '', 'persona' => $ev['tecnico'] ?? '',
        'estado' => $ev['estado'] === 'Completado' ? 'Completado' : 'En proceso', 'href' => $urlMtto($ev['numero_inventario'])];
}
foreach ($evTareas as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $detalle[$d][] = ['tipo' => 'tarea', 'icon' => $ev['estado'] === 'Realizado' ? 'check_circle' : 'checklist',
        'titulo' => $ev['nombre_tarea'], 'equipo' => $ev['modelo'] ? "{$ev['numero_inventario']} — {$ev['modelo']}" : '',
        'persona' => $ev['asignado'] ?? '', 'estado' => $ev['estado'], 'prioridad' => $ev['prioridad'],
        'href' => '/pages/tareas.php'];
}
foreach ($evEntregas as $ev) {
    $d = (int)date('j', strtotime($ev['fecha']));
    $detalle[$d][] = ['tipo' => 'entrega', 'icon' => 'inventory_2', 'titulo' => 'Entrega de equipo',
        'equipo' => "{$ev['numero_inventario']} — {$ev['modelo']}", 'area' => $ev['nombre_area'] ?? '',
        'estado' => '', 'href' => $urlMtto($ev['numero_inventario'])];
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
$nombresDow = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
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
    <title>Calendario — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="/css/estilos.css?v=26">
    <style>
        /* ---- Calendario ---- */
        .cal-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
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
            transition: background .15s, box-shadow .15s;
            min-width: 0;
        }

        .cal-cell:nth-child(7n) { border-right: none; }
        .cal-cell.empty { background: var(--bg-main); opacity: .4; }
        .cal-cell.hoy { background: rgba(91,33,182,.05); outline: 2px solid var(--accent) inset; }
        .cal-cell[role="button"] { cursor: pointer; }
        .cal-cell:hover:not(.empty) { background: var(--bg-hover); }
        .cal-cell[role="button"]:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; z-index: 1; }
        /* Día seleccionado */
        .cal-cell.sel { background: var(--accent-glow); box-shadow: inset 0 0 0 2px var(--accent); }
        .cal-cell.sel .cal-day-num { color: var(--accent); }

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
            cursor: inherit;
        }

        .ev-warning { background: rgba(154,103,0,.15);  color: var(--warning); }
        .ev-success { background: rgba(26,127,55,.15);   color: var(--success); }
        .ev-info    { background: rgba(9,105,218,.15);  color: var(--info); }
        .ev-purple  { background: rgba(139,92,246,.18); color: var(--purple); }
        .ev-danger  { background: rgba(207,34,46,.15);   color: var(--danger); }

        .ev-more {
            font-size: 10px;
            color: var(--text-muted);
            padding: 1px 5px;
        }

        /* Puntos de eventos (se usan en pantallas angostas en lugar de las etiquetas) */
        .cal-dots { display: none; gap: 3px; flex-wrap: wrap; margin-top: 4px; }
        .cal-dots i { width: 6px; height: 6px; border-radius: 50%; }

        /* Panel lateral */
        .ev-panel { position: sticky; top: 24px; display: flex; flex-direction: column; gap: 16px; }

        /* ---- Detalle del día (vista previa) ---- */
        .dia-card { overflow: hidden; }
        .dia-head { display: flex; align-items: center; gap: 12px; padding: 16px 16px 14px; border-bottom: 1px solid var(--border-light); }
        .dia-num {
            flex-shrink: 0; width: 48px; height: 52px; border-radius: 12px;
            background: var(--accent-glow); color: var(--accent);
            display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.05;
        }
        .dia-num b { font-family: var(--font-mono); font-size: 20px; }
        .dia-num small { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .dia-titulo { flex: 1; min-width: 0; }
        .dia-titulo strong { display: block; font-size: 15px; color: var(--text-primary); text-transform: capitalize; }
        .dia-titulo span { font-size: 12px; color: var(--text-muted); }
        .dia-nav { display: flex; gap: 4px; }
        .dia-nav button {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card);
            color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s, transform .15s var(--ease-out);
        }
        .dia-nav button:hover:not(:disabled) { background: var(--bg-hover); color: var(--text-primary); }
        .dia-nav button:active:not(:disabled) { transform: scale(.92); }
        .dia-nav button:disabled { opacity: .4; cursor: not-allowed; }
        .dia-nav .material-symbols-outlined { font-size: 18px; }
        .dia-cuerpo { padding: 6px 12px 12px; max-height: 420px; overflow-y: auto; }
        .dia-cuerpo.cambia { animation: diaCambia .24s var(--ease-out); }
        @keyframes diaCambia { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

        .dia-item {
            --tc: var(--accent);
            display: flex; gap: 10px; padding: 10px; margin-top: 8px; border-radius: 12px;
            border: 1px solid var(--border-light); background: var(--bg-main); text-decoration: none; color: inherit;
            animation: diaItem .26s var(--ease-out) both; animation-delay: calc(var(--i, 0) * 40ms);
            transition: border-color .15s, background .15s, transform .15s var(--ease-out);
        }
        .dia-item:hover { border-color: var(--tc); background: var(--bg-hover); }
        .dia-item:active { transform: scale(.985); }
        .dia-item:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        @keyframes diaItem { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        .dia-item.t-proximo   { --tc: var(--warning); }
        .dia-item.t-realizado { --tc: var(--success); }
        .dia-item.t-tarea     { --tc: var(--info); }
        .dia-item.t-entrega   { --tc: var(--purple); }
        .dia-ico {
            flex-shrink: 0; width: 34px; height: 34px; border-radius: 10px;
            background: color-mix(in srgb, var(--tc) 16%, transparent); color: var(--tc);
            display: flex; align-items: center; justify-content: center;
        }
        .dia-ico .material-symbols-outlined { font-size: 19px; }
        .dia-info { flex: 1; min-width: 0; }
        .dia-info strong { display: block; font-size: 13px; color: var(--text-primary); line-height: 1.35; overflow-wrap: anywhere; }
        .dia-info .sub { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-secondary); margin-top: 2px; overflow-wrap: anywhere; }
        .dia-info .sub .material-symbols-outlined { font-size: 14px; color: var(--text-muted); flex-shrink: 0; }
        .dia-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
        .dia-tag { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; background: color-mix(in srgb, var(--tc) 14%, transparent); color: var(--tc); }
        .dia-tag.neutro { background: var(--bg-hover); color: var(--text-secondary); }
        .dia-ir { align-self: center; color: var(--text-muted); flex-shrink: 0; }
        .dia-ir .material-symbols-outlined { font-size: 18px; transition: transform .15s var(--ease-out); }
        .dia-item:hover .dia-ir .material-symbols-outlined { transform: translateX(3px); color: var(--tc); }

        .dia-vacio { text-align: center; padding: 30px 12px 22px; color: var(--text-muted); }
        .dia-vacio .material-symbols-outlined { font-size: 34px; display: block; margin-bottom: 8px; opacity: .7; }
        .dia-vacio p { font-size: 13px; line-height: 1.5; }

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

        @media (max-width: 1100px) {
            .cal-layout { grid-template-columns: 1fr 300px; }
        }
        @media (max-width: 900px) {
            .cal-layout { grid-template-columns: 1fr; }
            .cal-cell { min-height: 56px; }
            .cal-ev, .ev-more { display: none; }
            .cal-dots { display: flex; }
            .ev-panel { position: static; }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title"><span class="material-symbols-outlined mi-md">calendar_month</span> Calendario</div>
                <div class="page-subtitle">Mantenimientos, tareas y fechas importantes — selecciona un día para ver su detalle</div>
            </div>
        </div>

        <!-- Leyenda -->
        <div class="leyenda">
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--success)"></div> Mantenimiento realizado</div>
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--warning)"></div> Próximo mantenimiento</div>
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--info)"></div> Tarea programada</div>
            <div class="leyenda-item"><div class="leyenda-dot" style="background:var(--purple)"></div> Entrega de equipo</div>
        </div>

        <div class="cal-layout" id="ajaxFiltroZona"
             data-mes="<?= $mes ?>" data-anio="<?= $anio ?>" data-dias="<?= $diasEnMes ?>" data-hoy="<?= $diaHoy ?>"
             data-mes-nombre="<?= e($nombresMes[$mes]) ?>">

            <!-- CALENDARIO -->
            <div>
                <!-- Navegación mes -->
                <div class="cal-nav">
                    <a href="?mes=<?= $mesAnterior ?>&anio=<?= $anioAnterior ?>" class="btn btn-ghost btn-sm" onclick="return ajaxFiltro(this.href)"><span class="material-symbols-outlined mi-sm">arrow_back</span> Anterior</a>
                    <span class="cal-title"><?= $nombresMes[$mes] ?> <?= $anio ?></span>
                    <a href="?mes=<?= $mesSiguiente ?>&anio=<?= $anioSiguiente ?>" class="btn btn-ghost btn-sm" onclick="return ajaxFiltro(this.href)">Siguiente <span class="material-symbols-outlined mi-sm">arrow_forward</span></a>
                </div>

                <!-- Grid del calendario -->
                <div class="cal-grid" id="calGrid" role="grid" aria-label="Calendario de <?= e($nombresMes[$mes]) ?> <?= $anio ?>">
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
                        $dow     = (int)date('N', mktime(0,0,0,$mes,$dia,$anio));
                        $n       = count($evsDia);
                        $aria    = "{$nombresDow[$dow]} {$dia} de {$nombresMes[$mes]}" . ($esHoy ? ', hoy' : '') . ', ' . ($n ? "{$n} " . ($n === 1 ? 'evento' : 'eventos') : 'sin eventos');
                        ?>
                        <div class="cal-cell <?= $esHoy ? 'hoy' : '' ?>" role="button" tabindex="0" data-dia="<?= $dia ?>" aria-label="<?= e($aria) ?>" aria-pressed="false">
                            <div class="cal-day-num">
                                <?php if ($esHoy): ?>
                                    <div class="cal-day-badge"><?= $dia ?></div>
                                <?php else: ?>
                                    <span><?= $dia ?></span>
                                <?php endif; ?>
                            </div>
                            <?php foreach(array_slice($evsDia, 0, $maxShow) as $ev): ?>
                                <span class="cal-ev <?= $ev['color'] ?>">
                                    <span class="material-symbols-outlined mi-xs" style="vertical-align:-2px"><?= e($ev['icon']) ?></span> <?= e($ev['texto']) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($resto > 0): ?>
                                <span class="ev-more">+<?= $resto ?> más</span>
                            <?php endif; ?>
                            <?php if ($evsDia): ?>
                                <div class="cal-dots" aria-hidden="true">
                                    <?php foreach (array_slice($evsDia, 0, 6) as $ev): ?>
                                        <i class="dot-<?= $ev['tipo'] ?>"></i>
                                    <?php endforeach; ?>
                                </div>
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

            <!-- PANEL LATERAL -->
            <div class="ev-panel">

                <!-- Detalle del día seleccionado (lo llena el script de abajo) -->
                <div class="card dia-card" id="diaCard" aria-live="polite">
                    <div class="dia-head">
                        <div class="dia-num" id="diaNum"><b>—</b><small>día</small></div>
                        <div class="dia-titulo"><strong id="diaTitulo">Selecciona un día</strong><span id="diaSub">Toca una fecha del calendario</span></div>
                        <div class="dia-nav">
                            <button type="button" id="diaPrev" aria-label="Día anterior"><span class="material-symbols-outlined">chevron_left</span></button>
                            <button type="button" id="diaNext" aria-label="Día siguiente"><span class="material-symbols-outlined">chevron_right</span></button>
                        </div>
                    </div>
                    <div class="dia-cuerpo" id="diaCuerpo"></div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title" style="font-size:14px"><span class="material-symbols-outlined mi-md">push_pin</span> Eventos de <?= $nombresMes[$mes] ?></div>
                        <span class="text-muted" style="font-size:12px"><?= count($todosEventos) ?> eventos</span>
                    </div>
                    <div class="card-body" style="padding:0 16px;max-height:300px;overflow-y:auto">
                        <?php if (empty($todosEventos)): ?>
                            <div class="empty-state" style="padding:28px 0">
                                <span class="empty-icon material-symbols-outlined" style="font-size:32px">inbox</span>
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
                <div class="card">
                    <div class="card-header">
                        <div class="card-title" style="font-size:14px"><span class="material-symbols-outlined mi-md">bar_chart</span> Resumen del mes</div>
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

            <script type="application/json" id="calDatos"><?= json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT) ?></script>
        </div>
    </main>
</div>

<script>
// ---- Detalle del día: al seleccionar una fecha se muestra lo asignado ese día ----
(function () {
    const DOW = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    const TIPO_TXT = { proximo: 'Próximo mantenimiento', realizado: 'Mantenimiento', tarea: 'Tarea', entrega: 'Entrega' };
    let zona, datos, seleccionado = 0;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const celda = (d) => zona.querySelector('.cal-cell[data-dia="' + d + '"]');

    function tagsDe(ev) {
        const t = [];
        if (ev.estado) t.push('<span class="dia-tag">' + esc(ev.estado) + '</span>');
        if (ev.prioridad) t.push('<span class="dia-tag neutro">Prioridad ' + esc(ev.prioridad.toLowerCase()) + '</span>');
        return t.length ? '<div class="dia-tags">' + t.join('') + '</div>' : '';
    }
    function itemHtml(ev, i) {
        const sub = (icono, txt) => txt ? '<div class="sub"><span class="material-symbols-outlined">' + icono + '</span>' + esc(txt) + '</div>' : '';
        return '<a class="dia-item t-' + esc(ev.tipo) + '" style="--i:' + i + '" href="' + esc(ev.href) + '" title="Abrir en ' + (ev.tipo === 'tarea' ? 'Tareas' : 'Mantenimientos') + '">' +
            '<span class="dia-ico"><span class="material-symbols-outlined">' + esc(ev.icon) + '</span></span>' +
            '<span class="dia-info"><strong>' + esc(ev.titulo) + '</strong>' +
            sub('computer', ev.equipo) + sub('meeting_room', ev.area) + sub('person', ev.persona) + tagsDe(ev) + '</span>' +
            '<span class="dia-ir"><span class="material-symbols-outlined">chevron_right</span></span></a>';
    }

    function mostrar(d, enfocar) {
        const total = +zona.dataset.dias;
        if (d < 1 || d > total) return;
        seleccionado = d;
        zona.querySelectorAll('.cal-cell.sel').forEach((c) => { c.classList.remove('sel'); c.setAttribute('aria-pressed', 'false'); });
        const c = celda(d);
        if (c) { c.classList.add('sel'); c.setAttribute('aria-pressed', 'true'); if (enfocar) c.focus({ preventScroll: true }); }

        const fecha = new Date(+zona.dataset.anio, +zona.dataset.mes - 1, d);
        const lista = datos[d] ? Object.values(datos[d]) : [];
        const esHoy = +zona.dataset.hoy === d;
        zona.querySelector('#diaNum').innerHTML = '<b>' + d + '</b><small>' + zona.dataset.mesNombre.slice(0, 3) + '</small>';
        zona.querySelector('#diaTitulo').textContent = DOW[fecha.getDay()] + (esHoy ? ' · hoy' : '');
        zona.querySelector('#diaSub').textContent = lista.length
            ? lista.length + (lista.length === 1 ? ' actividad asignada' : ' actividades asignadas')
            : 'Sin actividades asignadas';
        zona.querySelector('#diaPrev').disabled = d <= 1;
        zona.querySelector('#diaNext').disabled = d >= total;

        const cuerpo = zona.querySelector('#diaCuerpo');
        cuerpo.innerHTML = lista.length
            ? lista.map(itemHtml).join('')
            : '<div class="dia-vacio"><span class="material-symbols-outlined">event_available</span><p>No hay mantenimientos, tareas ni entregas programadas para este día.</p></div>';
        cuerpo.classList.remove('cambia'); void cuerpo.offsetWidth; cuerpo.classList.add('cambia');
        cuerpo.scrollTop = 0;
    }

    function vacio() {
        zona.querySelector('#diaCuerpo').innerHTML = '<div class="dia-vacio"><span class="material-symbols-outlined">touch_app</span><p>Selecciona un día del calendario para ver qué tiene asignado.</p></div>';
        zona.querySelector('#diaPrev').disabled = zona.querySelector('#diaNext').disabled = true;
    }

    function iniciar() {
        zona = document.getElementById('ajaxFiltroZona');
        const json = document.getElementById('calDatos');
        if (!zona || !json || zona.dataset.calListo === '1') return;
        zona.dataset.calListo = '1';
        try { datos = JSON.parse(json.textContent) || {}; } catch (e) { datos = {}; }

        zona.querySelector('#calGrid').addEventListener('click', (e) => {
            const c = e.target.closest('.cal-cell[data-dia]');
            if (!c) return;
            mostrar(+c.dataset.dia, false);
            // En pantallas angostas el panel queda debajo del calendario: lo acercamos
            if (window.matchMedia('(max-width: 900px)').matches) zona.querySelector('#diaCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        zona.querySelector('#calGrid').addEventListener('keydown', (e) => {
            const c = e.target.closest('.cal-cell[data-dia]');
            if (!c) return;
            const d = +c.dataset.dia;
            const paso = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); mostrar(d, false); }
            else if (paso) { e.preventDefault(); mostrar(d + paso, true); }
        });
        zona.querySelector('#diaPrev').addEventListener('click', () => mostrar(seleccionado - 1, false));
        zona.querySelector('#diaNext').addEventListener('click', () => mostrar(seleccionado + 1, false));

        // Al abrir el mes actual se muestra el día de hoy; en otros meses, una invitación a elegir
        +zona.dataset.hoy ? mostrar(+zona.dataset.hoy, false) : vacio();
    }

    iniciar();
    // El calendario se recarga por AJAX al cambiar de mes (js/ui.js reemplaza #ajaxFiltroZona): volvemos a enlazarlo
    new MutationObserver(() => requestAnimationFrame(iniciar)).observe(document.querySelector('.main-content'), { childList: true });
})();
</script>
</body>
</html>
