<?php
// =============================================
// BAJAS DE EQUIPOS
// Archivo: pages/bajas.php
// =============================================
require_once '../includes/config.php';
require_once '../includes/formato_bajas.php';
requireAdmin();

$db  = getDB();
$msg = '';

// ==========================================
// Procesar POST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Registrar Baja ---
    if ($action === 'registrar_baja') {
        $inv            = trim($_POST['numero_inventario'] ?? '');
        $motivo         = mb_substr(trim($_POST['motivo_baja'] ?? ''), 0, 255);
        $desc_falla     = trim($_POST['descripcion_falla'] ?? '');
        $diagnostico    = trim($_POST['diagnostico_tecnico'] ?? '');
        $costo_rep      = $_POST['costo_reparacion_estimado'] ?: null;
        $valor_actual   = $_POST['valor_actual_estimado'] ?: null;
        $fecha_baja     = $_POST['fecha_baja'] ?? date('Y-m-d');

        $chk = $db->prepare("SELECT estado FROM Equipos WHERE numero_inventario=?");
        $chk->execute([$inv]);
        $estadoEq = $chk->fetchColumn();

        if ($inv && $motivo && $desc_falla && $diagnostico && !in_array($estadoEq, ['Activo','En Reparacion'], true)) {
            $msg = "❌ Ese equipo no se puede dar de baja (ya está de baja, inactivo o no existe).";
        } elseif ($inv && $motivo && $desc_falla && $diagnostico) {
            try {
                // Insertar baja
                $db->prepare("
                    INSERT INTO Bajas (numero_inventario, motivo_baja, descripcion_falla, diagnostico_tecnico,
                        costo_reparacion_estimado, valor_actual_estimado,
                        id_tecnico_responsable, fecha_baja)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([
                    $inv, $motivo, $desc_falla, $diagnostico,
                    $costo_rep, $valor_actual,
                    $_SESSION['usuario']['id'], $fecha_baja
                ]);

                // Cambiar estado del equipo a 'Baja'
                $db->prepare("UPDATE Equipos SET estado='Baja' WHERE numero_inventario=?")
                   ->execute([$inv]);

                notificarAdmins('Nueva baja registrada', "Equipo {$inv}: {$motivo}", '/pages/bajas.php', 'inventory_2');
                respond(true, "✅ Baja registrada. Selecciónala para guardarla en un marbete.", "/pages/bajas.php");
            } catch (PDOException $e) {
                $msg = "❌ Error al registrar la baja: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Completa todos los campos obligatorios.";
        }
    }

    // --- Validar / Rechazar baja ---
    elseif ($action === 'validar_baja') {
        $id      = (int)$_POST['id_baja'];
        $estado  = $_POST['estado_validacion'] ?? 'Validado';
        $obs     = trim($_POST['observaciones_validacion'] ?? '');
        $db->prepare("UPDATE Bajas SET estado_validacion=?, observaciones_validacion=?, fecha_validacion=NOW() WHERE id_baja=?")
           ->execute([$estado, $obs, $id]);
        $bajaInfo = $db->prepare("SELECT id_tecnico_responsable, numero_inventario FROM Bajas WHERE id_baja=?");
        $bajaInfo->execute([$id]);
        if ($bajaInfo = $bajaInfo->fetch()) notificar((int)$bajaInfo['id_tecnico_responsable'], "Baja {$estado}", "Equipo {$bajaInfo['numero_inventario']}", '/pages/bajas.php', $estado === 'Validado' ? 'check_circle' : 'cancel');
        respond(true, "✅ Baja {$estado} correctamente.", '/pages/bajas.php');
    }

    // --- Eliminar baja y reactivar equipo ---
    elseif ($action === 'eliminar_baja') {
        $id  = (int)$_POST['id_baja'];
        $inv = trim($_POST['numero_inventario'] ?? '');
        $enMarbete = $db->prepare("SELECT id_marbete FROM Bajas WHERE id_baja=?");
        $enMarbete->execute([$id]);
        if ($enMarbete->fetchColumn()) {
            respond(false, "❌ Esa baja ya está guardada en un marbete. Quítala del marbete primero.", '/pages/bajas.php');
        }
        $db->prepare("DELETE FROM Bajas WHERE id_baja=?")->execute([$id]);
        $db->prepare("UPDATE Equipos SET estado='Inactivo' WHERE numero_inventario=?")->execute([$inv]);
        respond(true, "🗑 Baja eliminada. Equipo reactivado como Inactivo.", '/pages/bajas.php');
    }

    // --- Guardar las bajas seleccionadas en un marbete nuevo ---
    elseif ($action === 'guardar_marbete') {
        $p   = fbParametros($_POST);
        $ids = $p['ids'];
        if ($ids) {
            // Solo las que siguen libres: una baja ya archivada no puede entrar a otro marbete
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $libres = $db->prepare("SELECT id_baja FROM Bajas WHERE id_marbete IS NULL AND id_baja IN ($ph)");
            $libres->execute($ids);
            $ids = array_map('intval', $libres->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!$ids) {
            respond(false, "❌ Esas bajas ya están guardadas en un marbete. La lista se actualizó.", '/pages/bajas.php');
        }
        try {
            $db->beginTransaction();
            $db->prepare("
                INSERT INTO Marbetes_Bajas (tipo, fecha, fecha_verificacion, depto, ubicacion, observaciones,
                    firma_solicita, firma_costos, firma_finanzas, firma_gerente, id_usuario)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $p['tipo'], $p['fecha'], $p['fecha_verificacion'] ?: null, $p['depto'], $p['ubicacion'],
                mb_substr($p['observaciones'], 0, 150) ?: null,
                $p['firmas']['solicita']['nombre'], $p['firmas']['costos']['nombre'],
                $p['firmas']['finanzas']['nombre'], $p['firmas']['gerente']['nombre'],
                $_SESSION['usuario']['id'],
            ]);
            $idMarbete = (int)$db->lastInsertId();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE Bajas SET id_marbete=? WHERE id_marbete IS NULL AND id_baja IN ($ph)")
               ->execute(array_merge([$idMarbete], $ids));
            $db->commit();
            respond(true, "✅ Marbete " . fbFolio($idMarbete) . " guardado con " . count($ids) . " equipo(s).", '/pages/bajas.php');
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            respond(false, "❌ No se pudo guardar el marbete: " . $e->getMessage(), '/pages/bajas.php');
        }
    }

    // --- Corregir los datos de un marbete ---
    elseif ($action === 'editar_marbete') {
        $idMarbete = (int)($_POST['id_marbete'] ?? 0);
        $p = fbParametros($_POST);
        $db->prepare("
            UPDATE Marbetes_Bajas SET tipo=?, fecha=?, fecha_verificacion=?, depto=?, ubicacion=?, observaciones=?,
                firma_solicita=?, firma_costos=?, firma_finanzas=?, firma_gerente=?
            WHERE id_marbete=?
        ")->execute([
            $p['tipo'], $p['fecha'], $p['fecha_verificacion'] ?: null, $p['depto'], $p['ubicacion'],
            mb_substr($p['observaciones'], 0, 150) ?: null,
            $p['firmas']['solicita']['nombre'], $p['firmas']['costos']['nombre'],
            $p['firmas']['finanzas']['nombre'], $p['firmas']['gerente']['nombre'],
            $idMarbete,
        ]);
        respond(true, "✅ Marbete " . fbFolio($idMarbete) . " actualizado.", '/pages/bajas.php?vista=marbetes');
    }

    // --- Sacar un equipo de su marbete (vuelve a la lista de Bajas) ---
    elseif ($action === 'quitar_de_marbete') {
        $id = (int)($_POST['id_baja'] ?? 0);
        $q = $db->prepare("SELECT id_marbete FROM Bajas WHERE id_baja=?");
        $q->execute([$id]);
        $idMarbete = (int)$q->fetchColumn();
        if (!$idMarbete) respond(false, "❌ Esa baja no está en ningún marbete.", '/pages/bajas.php?vista=marbetes');
        $db->prepare("UPDATE Bajas SET id_marbete=NULL WHERE id_baja=?")->execute([$id]);
        $resto = $db->prepare("SELECT COUNT(*) FROM Bajas WHERE id_marbete=?");
        $resto->execute([$idMarbete]);
        if (!(int)$resto->fetchColumn()) {
            $db->prepare("DELETE FROM Marbetes_Bajas WHERE id_marbete=?")->execute([$idMarbete]);
            respond(true, "🗑 Equipo devuelto a Bajas. El marbete " . fbFolio($idMarbete) . " quedó vacío y se eliminó.", '/pages/bajas.php?vista=marbetes');
        }
        respond(true, "✅ Equipo devuelto a Bajas; ya puedes incluirlo en otro marbete.", '/pages/bajas.php?vista=marbetes');
    }

    // --- Deshacer un marbete completo: todos sus equipos vuelven a Bajas ---
    elseif ($action === 'eliminar_marbete') {
        $idMarbete = (int)($_POST['id_marbete'] ?? 0);
        // Se libera explícitamente (sin depender de la FK ON DELETE SET NULL: en hostings con tablas MyISAM no se aplica)
        $db->prepare("UPDATE Bajas SET id_marbete=NULL WHERE id_marbete=?")->execute([$idMarbete]);
        $db->prepare("DELETE FROM Marbetes_Bajas WHERE id_marbete=?")->execute([$idMarbete]);
        respond(true, "🗑 Marbete " . fbFolio($idMarbete) . " eliminado. Sus equipos volvieron a Bajas.", '/pages/bajas.php?vista=marbetes');
    }

    respond(!str_starts_with($msg, '❌'), $msg, '/pages/bajas.php');
}

// Leer mensajes
$msg = getFlash('msg') ?? '';
$vista  = ($_GET['vista'] ?? '') === 'marbetes' ? 'marbetes' : 'bajas';

// Bajas por archivar: las que ya están en un marbete solo se ven dentro de él
$bajas = $db->query("
    SELECT b.*, e.modelo, e.marca, e.procesador, e.ram, e.disco, a.nombre_area,
           u.nombre as tecnico_nombre, u.cargo as tecnico_cargo
    FROM Bajas b
    JOIN Equipos e ON e.numero_inventario = b.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = b.id_tecnico_responsable
    WHERE b.id_marbete IS NULL
    ORDER BY b.fecha_baja DESC, b.id_baja DESC
")->fetchAll();

// Marbetes guardados, cada uno con sus equipos
$marbetes = $db->query("
    SELECT m.*, u.nombre AS usuario_nombre
    FROM Marbetes_Bajas m
    LEFT JOIN Usuarios u ON u.id_usuario = m.id_usuario
    ORDER BY m.fecha_registro DESC, m.id_marbete DESC
")->fetchAll();
$itemsMarbete = [];
foreach ($db->query("
    SELECT b.id_baja, b.id_marbete, b.numero_inventario, b.motivo_baja, b.valor_actual_estimado, e.marca, e.modelo
    FROM Bajas b JOIN Equipos e ON e.numero_inventario = b.numero_inventario
    WHERE b.id_marbete IS NOT NULL
    ORDER BY b.fecha_baja, b.id_baja
") as $it) {
    $itemsMarbete[$it['id_marbete']][] = $it;
}

// Equipos activos/inactivos/en reparación que se pueden dar de baja
$equiposActivos = $db->query("
    SELECT numero_inventario, modelo, marca, estado, COALESCE(id_area, 0) AS id_area FROM Equipos
    WHERE estado IN ('Activo','En Reparacion')
      AND numero_inventario NOT IN (SELECT numero_inventario FROM Bajas)
    ORDER BY numero_inventario
")->fetchAll();
$areasBaja = $db->query("SELECT id_area, nombre_area FROM Areas ORDER BY nombre_area")->fetchAll();
$equiposPorArea = [];
foreach ($equiposActivos as $eq) {
    $equiposPorArea[$eq['id_area']][] = [
        'inv' => $eq['numero_inventario'],
        'txt' => $eq['numero_inventario'] . ' — ' . $eq['modelo'] . ' (' . $eq['estado'] . ')',
    ];
}

// Departamentos registrados (campo DEPTO del formato de baja). Un "Departamento" es un registro de la tabla Areas
// (la tabla Departamentos guarda lo que ahora se llama "Área"); DISTINCT por si varias áreas tienen un departamento con el mismo nombre.
$departamentos = $db->query("SELECT DISTINCT nombre_area FROM Areas ORDER BY nombre_area")->fetchAll(PDO::FETCH_COLUMN);

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
    <title>Bajas de Equipos — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <?php include __DIR__ . '/../includes/tema_inicial.php'; ?><link rel="stylesheet" href="/css/estilos.css?v=32">
    <style>
        .baja-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 14px;
            transition: border-color .2s;
        }
        .baja-card:hover { border-color: var(--accent); }
        .baja-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .baja-inv { font-family: var(--font-mono); color: var(--danger); font-size: 15px; font-weight: 700; }
        .baja-modelo { color: var(--text-secondary); font-size: 13px; margin-top: 2px; }
        .baja-meta { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; }
        .baja-meta-item { font-size: 12px; color: var(--text-muted); }
        .baja-meta-item strong { color: var(--text-secondary); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
        .baja-diag { 
            background: var(--bg-main); 
            border-left: 3px solid var(--danger);
            padding: 10px 14px; 
            border-radius: 0 6px 6px 0;
            font-size: 13px; 
            color: var(--text-secondary); 
            margin-top: 10px;
            line-height: 1.6;
        }
        .validacion-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-light);
            flex-wrap: wrap;
        }
        .badge-validado   { background: rgba(63,185,80,.15); color: var(--success); border: 1px solid rgba(63,185,80,.3); }
        .badge-rechazado  { background: rgba(248,81,73,.15);  color: var(--danger);  border: 1px solid rgba(248,81,73,.3); }
        .badge-pendiente-val { background: rgba(210,153,34,.15); color: var(--warning); border: 1px solid rgba(210,153,34,.3); }

        /* ---- Selección de bajas para el formato ---- */
        .sel-baja, #selTodasBajas { accent-color: var(--accent); }
        .baja-card { transition: border-color .2s, box-shadow .2s var(--ease-out); }
        .baja-card.seleccionada { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

        .sel-bar {
            position: fixed; left: 50%; bottom: 24px; z-index: 900;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap; justify-content: center;
            background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);
            box-shadow: 0 12px 32px rgba(20,20,40,.18); padding: 10px 12px 10px 18px;
            max-width: calc(100vw - 32px);
            transform: translate(-50%, calc(100% + 40px)); opacity: 0; pointer-events: none;
            transition: transform .3s var(--ease-out), opacity .2s ease;
        }
        .sel-bar.visible { transform: translate(-50%, 0); opacity: 1; pointer-events: auto; }
        .sel-bar-texto { font-size: 14px; color: var(--text-secondary); display: flex; align-items: center; gap: 8px; }
        .sel-bar-texto strong { color: var(--text-primary); font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title"><span class="material-symbols-outlined mi-md">delete</span> Bajas de Equipos</div>
                <div class="page-subtitle">Diagnóstico y validación de equipos dados de baja · Los formatos guardados quedan en Marbetes</div>
            </div>
            <div class="page-actions">
                <button class="btn btn-primary" onclick="abrirFormato()"><span class="material-symbols-outlined mi-sm">bookmark_add</span> Guardar en marbete</button>
                <button class="btn btn-danger" onclick="openModal('modalNuevaBaja')">+ Dar de Baja Equipo</button>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= str_starts_with($msg,'✅') ? 'alert-success' : (str_starts_with($msg,'🗑') ? 'alert-info' : 'alert-error') ?>"><?= renderMsg($msg) ?></div>
        <?php endif; ?>

        <div id="ajaxFiltroZona">
        <?php
        $totalBajas = (int)$db->query("SELECT COUNT(*) FROM Bajas")->fetchColumn();
        $pendVal    = (int)$db->query("SELECT COUNT(*) FROM Bajas WHERE estado_validacion='Pendiente'")->fetchColumn();
        $porArchivar = count($bajas);
        $totalMarbetes = count($marbetes);
        ?>
        <!-- Stats rápidas -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:24px">
            <div class="stat-card">
                <div class="stat-label">Total Bajas</div>
                <div class="stat-value danger"><?= $totalBajas ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Por archivar</div>
                <div class="stat-value warning"><?= $porArchivar ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pendientes Validación</div>
                <div class="stat-value"><?= $pendVal ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Marbetes guardados</div>
                <div class="stat-value success"><?= $totalMarbetes ?></div>
            </div>
        </div>

        <nav class="seg-tabs" role="tablist" aria-label="Vista de bajas">
            <a class="seg-tab <?= $vista === 'bajas' ? 'activa' : '' ?>" role="tab" aria-selected="<?= $vista === 'bajas' ? 'true' : 'false' ?>" href="/pages/bajas.php" onclick="return ajaxFiltro(this.href)">
                <span class="material-symbols-outlined mi-sm">inventory_2</span> Por archivar <span class="seg-n"><?= $porArchivar ?></span>
            </a>
            <a class="seg-tab <?= $vista === 'marbetes' ? 'activa' : '' ?>" role="tab" aria-selected="<?= $vista === 'marbetes' ? 'true' : 'false' ?>" href="/pages/bajas.php?vista=marbetes" onclick="return ajaxFiltro(this.href)">
                <span class="material-symbols-outlined mi-sm">bookmarks</span> Marbetes <span class="seg-n"><?= $totalMarbetes ?></span>
            </a>
        </nav>

        <?php if ($vista === 'marbetes'): ?>
        <!-- ========== MARBETES GUARDADOS ========== -->
        <?php if (empty($marbetes)): ?>
            <div class="card">
                <div class="empty-state" style="padding:60px 20px">
                    <span class="empty-icon material-symbols-outlined">bookmarks</span>
                    <p>Aún no hay marbetes. Selecciona bajas en la pestaña <strong>Por archivar</strong> y pulsa <strong>Guardar en marbete</strong>.</p>
                </div>
            </div>
        <?php else: ?>
            <p class="marbete-aviso"><span class="material-symbols-outlined mi-sm">info</span> ¿Te equivocaste? Edita los datos del marbete, o saca un equipo con <strong>Quitar</strong> para que vuelva a Por archivar.</p>
            <?php foreach ($marbetes as $m):
                $its = $itemsMarbete[$m['id_marbete']] ?? [];
                $totalM = array_sum(array_map(fn($i) => (float)$i['valor_actual_estimado'], $its));
                $folio = fbFolio((int)$m['id_marbete']);
                $datosEdit = [
                    'id' => (int)$m['id_marbete'], 'folio' => $folio, 'n' => count($its), 'tipo' => $m['tipo'],
                    'fecha' => $m['fecha'], 'fecha_verificacion' => $m['fecha_verificacion'] ?? '',
                    'depto' => $m['depto'], 'ubicacion' => $m['ubicacion'], 'observaciones' => $m['observaciones'] ?? '',
                    'firma_solicita' => $m['firma_solicita'] ?? '', 'firma_costos' => $m['firma_costos'] ?? '',
                    'firma_finanzas' => $m['firma_finanzas'] ?? '', 'firma_gerente' => $m['firma_gerente'] ?? '',
                ];
            ?>
            <article class="marbete-card" data-id="<?= (int)$m['id_marbete'] ?>">
                <div class="marbete-head">
                    <div>
                        <div>
                            <span class="marbete-folio"><span class="material-symbols-outlined mi-sm">bookmark</span> <?= e($folio) ?></span>
                            <span class="marbete-tipo"><?= $m['tipo'] === 'operacion' ? 'Equipo operación' : 'Baja de activos' ?></span>
                        </div>
                        <div class="marbete-sub">Guardado el <?= fechaES(substr($m['fecha_registro'], 0, 10)) ?> · <?= e($m['usuario_nombre'] ?? '—') ?></div>
                    </div>
                    <div class="marbete-acciones">
                        <a class="btn btn-success btn-sm" href="/pages/formato_baja_excel.php?marbete=<?= (int)$m['id_marbete'] ?>" data-descarga><span class="material-symbols-outlined mi-sm">download</span> Descargar Excel</a>
                        <button type="button" class="btn btn-ghost btn-sm" data-m="<?= e(json_encode($datosEdit, JSON_UNESCAPED_UNICODE)) ?>" onclick="abrirEditarMarbete(this)"><span class="material-symbols-outlined mi-sm">edit</span> Editar datos</button>
                        <form method="POST" style="display:inline" onsubmit="return zConfirm(this,'¿Deshacer el marbete <?= e($folio) ?>? Sus <?= count($its) ?> equipo(s) volverán a Por archivar.','danger')">
                            <input type="hidden" name="action" value="eliminar_marbete">
                            <input type="hidden" name="id_marbete" value="<?= (int)$m['id_marbete'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" aria-label="Deshacer marbete <?= e($folio) ?>"><span class="material-symbols-outlined mi-sm">undo</span> Deshacer</button>
                        </form>
                    </div>
                </div>

                <div class="baja-meta">
                    <div class="baja-meta-item"><strong>Fecha del formato</strong><?= fechaES($m['fecha']) ?></div>
                    <div class="baja-meta-item"><strong>Depto</strong><?= e($m['depto']) ?></div>
                    <div class="baja-meta-item"><strong>Ubicación</strong><?= e($m['ubicacion']) ?></div>
                    <div class="baja-meta-item"><strong>Equipos</strong><?= count($its) ?></div>
                    <div class="baja-meta-item"><strong>Total</strong>$<?= number_format($totalM, 2) ?></div>
                </div>

                <button type="button" class="marbete-toggle" aria-expanded="false" aria-controls="mi-<?= (int)$m['id_marbete'] ?>">
                    <span class="material-symbols-outlined mi-sm marbete-chevron">expand_more</span>
                    <span class="marbete-toggle-txt" data-cerrado="Ver <?= count($its) ?> equipo(s)" data-abierto="Ocultar equipos">Ver <?= count($its) ?> equipo(s)</span>
                </button>
                <div class="marbete-detalle" id="mi-<?= (int)$m['id_marbete'] ?>"><div class="marbete-detalle-in">
                <ul class="marbete-items">
                    <?php foreach ($its as $it): ?>
                    <li class="marbete-item">
                        <div style="min-width:0">
                            <div class="marbete-item-inv"><?= e($it['numero_inventario']) ?></div>
                            <div class="marbete-item-desc"><span class="text-clip" style="max-width:420px" title="<?= e(trim(($it['marca'] ?? '') . ' ' . $it['modelo']) . ' — ' . $it['motivo_baja']) ?>"><?= e(trim(($it['marca'] ?? '') . ' ' . $it['modelo'])) ?> — <?= e($it['motivo_baja']) ?></span></div>
                        </div>
                        <div class="marbete-item-der">
                            <?php if ($it['valor_actual_estimado'] !== null): ?><span>$<?= number_format((float)$it['valor_actual_estimado'], 2) ?></span><?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return zConfirm(this,'¿Quitar <?= e($it['numero_inventario']) ?> del marbete <?= e($folio) ?>? Volverá a Por archivar.','danger')">
                                <input type="hidden" name="action" value="quitar_de_marbete">
                                <input type="hidden" name="id_baja" value="<?= (int)$it['id_baja'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" aria-label="Quitar <?= e($it['numero_inventario']) ?> del marbete"><span class="material-symbols-outlined mi-sm">remove_circle</span> Quitar</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                </div></div>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php else: ?>
        <!-- ========== BAJAS POR ARCHIVAR ========== -->
        <?php if (empty($bajas)): ?>
            <div class="card">
                <div class="empty-state" style="padding:60px 20px">
                    <span class="empty-icon material-symbols-outlined"><?= $totalMarbetes ? 'task_alt' : 'computer' ?></span>
                    <p><?= $totalMarbetes
                        ? 'Todas las bajas ya están guardadas en un marbete. Revísalas en la pestaña <strong>Marbetes</strong>.'
                        : 'No hay equipos dados de baja. Usa el botón superior para registrar una baja.' ?></p>
                </div>
            </div>
        <?php else: ?>
            <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);margin-bottom:12px;cursor:pointer">
                <input type="checkbox" id="selTodasBajas"> Seleccionar todas para guardarlas en un marbete
            </label>
            <?php foreach ($bajas as $b): ?>
            <div class="baja-card">
                <div class="baja-card-header">
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <input type="checkbox" class="sel-baja" value="<?= $b['id_baja'] ?>" title="Incluir en el marbete" aria-label="Incluir <?= e($b['numero_inventario']) ?> en el marbete" style="margin-top:4px;width:16px;height:16px;cursor:pointer">
                        <div>
                        <div class="baja-inv"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">delete_forever</span> <span class="text-clip" title="<?= e($b['numero_inventario']) ?>" style="max-width:200px"><?= e($b['numero_inventario']) ?></span></div>
                        <div class="baja-modelo"><span class="text-clip" title="<?= e($b['modelo'].' '.($b['marca'] ?? '').' — '.($b['nombre_area'] ?? 'Sin departamento')) ?>" style="max-width:360px"><?= e($b['modelo']) ?> <?= e($b['marca'] ?? '') ?> — <?= e($b['nombre_area'] ?? 'Sin departamento') ?></span></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <?php
                        $vClass = match($b['estado_validacion']) {
                            'Validado'  => 'badge-validado',
                            'Rechazado' => 'badge-rechazado',
                            default     => 'badge-pendiente-val'
                        };
                        $vIcon = match($b['estado_validacion']) {
                            'Validado'  => 'check_circle',
                            'Rechazado' => 'cancel',
                            default     => 'hourglass_empty'
                        };
                        ?>
                        <span class="badge-estado <?= $vClass ?>"><span class="material-symbols-outlined mi-sm"><?= $vIcon ?></span> <?= e($b['estado_validacion']) ?></span>
                        <?php if ($b['estado_validacion'] === 'Pendiente'): ?>
                        <button class="btn btn-success btn-sm" onclick="abrirValidar(<?= $b['id_baja'] ?>, 'Validado')"><span class="material-symbols-outlined mi-sm">check_circle</span> Validar</button>
                        <button class="btn btn-danger btn-sm" onclick="abrirValidar(<?= $b['id_baja'] ?>, 'Rechazado')"><span class="material-symbols-outlined mi-sm">cancel</span> Rechazar</button>
                        <?php endif; ?>
                        <form method="POST" style="display:inline" onsubmit="return zConfirm(this,'¿Eliminar esta baja? El equipo volverá a estado Inactivo.','danger')">
                            <input type="hidden" name="action" value="eliminar_baja">
                            <input type="hidden" name="id_baja" value="<?= $b['id_baja'] ?>">
                            <input type="hidden" name="numero_inventario" value="<?= e($b['numero_inventario']) ?>">
                            <button type="submit" class="btn btn-ghost btn-sm btn-icon" aria-label="Eliminar baja <?= e($b['numero_inventario']) ?>"><span class="material-symbols-outlined mi-sm">delete</span></button>
                        </form>
                    </div>
                </div>

                <div class="baja-meta">
                    <div class="baja-meta-item"><strong>Motivo</strong><span class="text-clip" title="<?= e($b['motivo_baja']) ?>"><?= e($b['motivo_baja']) ?></span></div>
                    <div class="baja-meta-item"><strong>Fecha de Baja</strong><?= fechaES($b['fecha_baja']) ?></div>
                    <div class="baja-meta-item"><strong>Técnico</strong><span class="text-clip" title="<?= e($b['tecnico_nombre'] ?? '') ?>"><?= e($b['tecnico_nombre'] ?? '—') ?></span></div>
                    <?php if ($b['costo_reparacion_estimado']): ?>
                    <div class="baja-meta-item"><strong>Costo Reparación Est.</strong>$<?= number_format($b['costo_reparacion_estimado'],2) ?></div>
                    <?php endif; ?>
                </div>

                <div class="baja-diag">
                    <strong style="color:var(--text-primary);display:block;margin-bottom:4px"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">search</span> Diagnóstico:</strong>
                    <?= nl2br(e($b['diagnostico_tecnico'])) ?>
                </div>

                <?php if ($b['estado_validacion'] !== 'Pendiente' && $b['observaciones_validacion']): ?>
                <div class="baja-diag" style="border-color:<?= $b['estado_validacion'] === 'Validado' ? 'var(--success)' : 'var(--danger)' ?>;margin-top:8px">
                    <strong style="color:var(--text-primary);display:block;margin-bottom:4px"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">description</span> Observaciones de validación:</strong>
                    <?= nl2br(e($b['observaciones_validacion'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
        </div>

    </main>
</div>

<!-- Modal: Nueva Baja -->
<div class="modal-overlay" id="modalNuevaBaja">
    <div class="modal-box" style="max-width:620px">
        <div class="modal-header">
            <div class="modal-title"><span class="material-symbols-outlined mi-md">delete_forever</span> Dar de Baja un Equipo</div>
            <button class="modal-close" onclick="closeModal('modalNuevaBaja')"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">close</span></button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">warning</span> Esta acción cambiará el estado del equipo a <strong>Baja</strong>. Después podrás seleccionarla y guardarla en un marbete.</div>
            <form method="POST" action="/pages/bajas.php">
                <input type="hidden" name="action" value="registrar_baja">

                <div class="form-row">
                    <div class="form-group">
                        <label>Departamento *</label>
                        <select id="bajaDepto" required>
                            <option value="">Selecciona departamento...</option>
                            <?php foreach ($areasBaja as $ar): if (empty($equiposPorArea[$ar['id_area']])) continue; ?>
                                <option value="<?= (int)$ar['id_area'] ?>"><?= e($ar['nombre_area']) ?> (<?= count($equiposPorArea[$ar['id_area']]) ?>)</option>
                            <?php endforeach; ?>
                            <?php if (!empty($equiposPorArea[0])): ?>
                                <option value="0">Sin departamento (<?= count($equiposPorArea[0]) ?>)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Equipo a dar de baja *</label>
                        <select name="numero_inventario" id="bajaEquipo" required disabled>
                            <option value="">Primero elige un departamento</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fecha de Baja *</label>
                        <input type="date" name="fecha_baja" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Motivo de Baja *</label>
                    <input type="text" name="motivo_baja" maxlength="255" required placeholder="Escribe el motivo de la baja (ej. daño irreparable, obsolescencia, robo...)">
                </div>

                <div class="form-group">
                    <label>Descripción de la falla / problema *</label>
                    <textarea name="descripcion_falla" rows="2" required placeholder="Describe el problema o falla que presenta el equipo..."></textarea>
                </div>

                <div class="form-group">
                    <label>Diagnóstico técnico *</label>
                    <textarea name="diagnostico_tecnico" rows="3" required placeholder="Diagnóstico técnico detallado. Explica por qué no es viable reparar o continuar usando el equipo..."></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Costo de reparación ($)</label>
                        <input type="number" name="costo_reparacion_estimado" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Costo unitario ($)</label>
                        <input type="number" name="valor_actual_estimado" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>

                <button type="submit" class="btn btn-danger btn-full" style="margin-top:8px">
                    <span class="material-symbols-outlined mi-sm">delete_forever</span> Registrar Baja
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Generar Formato de Baja -->
<div class="modal-overlay" id="modalFormato">
    <div class="modal-box" style="max-width:520px">
        <div class="modal-header">
            <div class="modal-title"><span class="material-symbols-outlined mi-md">bookmark_add</span> <span id="formatoTitulo">Guardar en marbete</span></div>
            <button class="modal-close" onclick="closeModal('modalFormato')"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">close</span></button>
        </div>
        <?php $firmasGuardadas = fbFirmasGuardadas(); ?>
        <div class="modal-body">
            <form method="POST" action="/pages/bajas.php" id="formFormato">
                <input type="hidden" name="action" id="formatoAccion" value="guardar_marbete">
                <input type="hidden" name="id_marbete" id="formatoIdMarbete" value="">
                <div id="formatoIds"></div>
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px" id="formatoResumen"><strong id="formatoConteo">0</strong> equipo(s) seleccionado(s). Al guardar, dejarán de aparecer en Por archivar.</p>

                <div class="form-group">
                    <label>Tipo de baja</label>
                    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:14px">
                        <label style="display:flex;gap:8px;align-items:center;font-weight:400;text-transform:none;letter-spacing:0;margin:0;color:var(--text-primary);cursor:pointer"><input type="radio" name="tipo" value="activos" checked style="width:auto;padding:0;margin:0"> Baja de activos</label>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:400;text-transform:none;letter-spacing:0;margin:0;color:var(--text-primary);cursor:pointer"><input type="radio" name="tipo" value="operacion" style="width:auto;padding:0;margin:0"> Baja de equipo operación</label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha verificación de bajas</label>
                        <input type="date" name="fecha_verificacion">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Depto</label>
                        <?php if ($departamentos): ?>
                        <select name="depto" required>
                            <?php foreach ($departamentos as $dep): ?>
                                <option value="<?= e($dep) ?>" <?= mb_strtoupper($dep) === 'SISTEMAS' ? 'selected' : '' ?>><?= e($dep) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="depto" value="SISTEMAS">
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Ubicación</label>
                        <input type="text" name="ubicacion" value="STAFF">
                    </div>
                </div>

                <div class="form-group">
                    <label>Observaciones adicionales</label>
                    <input type="text" name="observaciones" maxlength="150" placeholder="Opcional">
                </div>

                <p style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary);margin:4px 0 10px">Firmas</p>
                <div class="form-row">
                    <div class="form-group">
                        <label>Solicitado por (<?= e(FB_FIRMAS['solicita']['cargo']) ?>)</label>
                        <input type="text" name="firma_solicita" maxlength="60" value="<?= e($firmasGuardadas['solicita']) ?>" placeholder="Nombre">
                    </div>
                    <div class="form-group">
                        <label>Verificado costos (<?= e(FB_FIRMAS['costos']['cargo']) ?>)</label>
                        <input type="text" name="firma_costos" maxlength="60" value="<?= e($firmasGuardadas['costos']) ?>" placeholder="Nombre">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Autoriza (<?= e(FB_FIRMAS['finanzas']['cargo']) ?>)</label>
                        <input type="text" name="firma_finanzas" maxlength="60" value="<?= e($firmasGuardadas['finanzas']) ?>" placeholder="Nombre">
                    </div>
                    <div class="form-group">
                        <label>Autoriza (<?= e(FB_FIRMAS['gerente']['cargo']) ?>)</label>
                        <input type="text" name="firma_gerente" maxlength="60" value="<?= e($firmasGuardadas['gerente']) ?>" placeholder="Nombre">
                    </div>
                </div>
                <label style="display:flex;gap:8px;align-items:center;font-weight:400;text-transform:none;letter-spacing:0;margin:0 0 14px;font-size:13px;cursor:pointer"><input type="checkbox" name="guardar_firmas" value="1" style="width:auto;padding:0;margin:0"> Dejar estos nombres por defecto para los próximos formatos</label>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
                    <button type="submit" class="btn btn-primary" id="formatoGuardar" style="flex:1"><span class="material-symbols-outlined mi-sm">bookmark_add</span> <span id="formatoGuardarTxt">Guardar</span></button>
                    <button type="submit" class="btn btn-success" id="formatoExcel" style="flex:1" formaction="/pages/formato_baja_excel.php" formtarget="_self"><span class="material-symbols-outlined mi-sm">download</span> Descargar Excel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Barra flotante: bajas seleccionadas para el formato -->
<div class="sel-bar" id="selBar" aria-live="polite">
    <span class="sel-bar-texto" id="selBarTexto"><span class="material-symbols-outlined mi-sm">checklist</span> <span><strong id="selBarConteo">0</strong> seleccionada(s)</span></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="limpiarSeleccion()">Quitar selección</button>
    <button type="button" class="btn btn-primary btn-sm" onclick="abrirFormato()"><span class="material-symbols-outlined mi-sm">bookmark_add</span> Guardar en marbete</button>
</div>

<!-- Modal: Validar/Rechazar -->
<div class="modal-overlay" id="modalValidar">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title" id="validarTitulo"><span class="material-symbols-outlined mi-md">check_circle</span> Validar Baja</div>
            <button class="modal-close" onclick="closeModal('modalValidar')"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">close</span></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/bajas.php">
                <input type="hidden" name="action" value="validar_baja">
                <input type="hidden" name="id_baja" id="validarId">
                <input type="hidden" name="estado_validacion" id="validarEstado">
                <div class="form-group">
                    <label>Observaciones / Justificación</label>
                    <textarea name="observaciones_validacion" rows="4" placeholder="Escribe las observaciones o justificación de la decisión (opcional)..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-full" id="validarBtn">Confirmar</button>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

// Baja: el equipo se elige según el departamento seleccionado
const equiposPorDepto = <?= json_encode($equiposPorArea, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
document.addEventListener('change', function (e) {
    if (e.target.id !== 'bajaDepto') return;
    const sel = document.getElementById('bajaEquipo');
    const lista = equiposPorDepto[e.target.value] || [];
    sel.innerHTML = '';
    sel.add(new Option(lista.length ? 'Selecciona equipo...' : 'Primero elige un departamento', ''));
    lista.forEach(q => sel.add(new Option(q.txt, q.inv)));
    sel.disabled = !lista.length;
});

// Equipos de cada marbete: plegados por defecto; un clic los despliega y otro los vuelve a plegar.
// Los marbetes abiertos se recuerdan para que el refresco en tiempo real no los cierre.
const marbetesAbiertos = (function () {
    try { return new Set(JSON.parse(sessionStorage.getItem('marbetesAbiertos') || '[]')); } catch (e) { return new Set(); }
})();
function guardarAbiertos() {
    try { sessionStorage.setItem('marbetesAbiertos', JSON.stringify([...marbetesAbiertos])); } catch (e) {}
}
function pintarMarbete(card, abierto) {
    card.classList.toggle('abierto', abierto);
    const b = card.querySelector('.marbete-toggle');
    if (!b) return;
    b.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    const t = b.querySelector('.marbete-toggle-txt');
    t.textContent = abierto ? t.dataset.abierto : t.dataset.cerrado;
}
function restaurarMarbetes() {
    document.querySelectorAll('.marbete-card[data-id]').forEach(card => {
        if (!marbetesAbiertos.has(card.dataset.id) || card.classList.contains('abierto')) return;
        card.classList.add('sin-anim');   // al recargar la lista no se re-anima: queda como estaba
        pintarMarbete(card, true);
        void card.offsetHeight;
        card.classList.remove('sin-anim');
    });
}
document.addEventListener('click', function (e) {
    const b = e.target.closest('.marbete-toggle');
    if (!b) return;
    const card = b.closest('.marbete-card');
    const abrir = !card.classList.contains('abierto');
    abrir ? marbetesAbiertos.add(card.dataset.id) : marbetesAbiertos.delete(card.dataset.id);
    guardarAbiertos();
    pintarMarbete(card, abrir);
});
restaurarMarbetes();

function abrirValidar(id, estado) {
    document.getElementById('validarId').value    = id;
    document.getElementById('validarEstado').value = estado;
    document.getElementById('validarTitulo').innerHTML = estado === 'Validado' ? '<span class="material-symbols-outlined mi-md">check_circle</span> Validar Baja' : '<span class="material-symbols-outlined mi-md">cancel</span> Rechazar Baja';
    document.getElementById('validarBtn').innerHTML    = estado === 'Validado' ? '<span class="material-symbols-outlined mi-sm">check_circle</span> Confirmar Validación' : '<span class="material-symbols-outlined mi-sm">cancel</span> Confirmar Rechazo';
    document.getElementById('validarBtn').className = estado === 'Validado'
        ? 'btn btn-success btn-full'
        : 'btn btn-danger btn-full';
    openModal('modalValidar');
}

// ---- Formato de baja: selección de equipos (delegado: la lista se recarga por AJAX) ----
function bajasSeleccionadas() {
    return [...document.querySelectorAll('.sel-baja:checked')].map(c => c.value);
}

function actualizarSeleccion() {
    const casillas = document.querySelectorAll('.sel-baja');
    const n = bajasSeleccionadas().length;
    casillas.forEach(c => c.closest('.baja-card')?.classList.toggle('seleccionada', c.checked));

    const todas = document.getElementById('selTodasBajas');
    if (todas) {
        todas.checked = n > 0 && n === casillas.length;
        todas.indeterminate = n > 0 && n < casillas.length;
    }
    document.getElementById('selBar').classList.toggle('visible', n > 0);
    document.getElementById('selBarConteo').textContent = n;
}

function limpiarSeleccion() {
    document.querySelectorAll('.sel-baja').forEach(c => c.checked = false);
    actualizarSeleccion();
}

document.addEventListener('change', function (e) {
    if (e.target.id === 'selTodasBajas') {
        document.querySelectorAll('.sel-baja').forEach(c => c.checked = e.target.checked);
    }
    if (e.target.id === 'selTodasBajas' || e.target.classList.contains('sel-baja')) actualizarSeleccion();
});

// Cuando la lista se recarga por AJAX (validar/eliminar) se pierden las casillas: resincroniza la barra
new MutationObserver(() => requestAnimationFrame(() => { actualizarSeleccion(); restaurarMarbetes(); }))
    .observe(document.querySelector('.main-content'), { childList: true });

// Modal del formato: modo "crear" (guarda las bajas marcadas en un marbete nuevo) o "editar" (corrige un marbete)
function modoFormato(editar) {
    document.getElementById('formatoTitulo').textContent = editar ? 'Editar marbete' : 'Guardar en marbete';
    document.getElementById('formatoGuardarTxt').textContent = editar ? 'Guardar cambios' : 'Guardar en marbete';
    document.getElementById('formatoAccion').value = editar ? 'editar_marbete' : 'guardar_marbete';
    document.getElementById('formatoExcel').style.display = editar ? 'none' : '';
}

function abrirFormato() {
    const ids = bajasSeleccionadas();
    if (!ids.length) {
        zToast(document.querySelector('.sel-baja')
            ? 'Marca la casilla de al menos una baja para guardarla en un marbete.'
            : 'No hay bajas por archivar. Registra una baja o revisa la pestaña Marbetes.', 'warning');
        return;
    }
    const f = document.getElementById('formFormato');
    f.reset();
    modoFormato(false);
    document.getElementById('formatoIdMarbete').value = '';
    document.getElementById('formatoIds').innerHTML = '<input type="hidden" name="ids" value="' + ids.join(',') + '">';
    document.getElementById('formatoResumen').innerHTML = '<strong>' + ids.length + '</strong> equipo(s) seleccionado(s). Al guardar, dejarán de aparecer en Por archivar.';
    openModal('modalFormato');
}

function abrirEditarMarbete(btn) {
    const m = JSON.parse(btn.dataset.m);
    const f = document.getElementById('formFormato');
    f.reset();
    modoFormato(true);
    document.getElementById('formatoIdMarbete').value = m.id;
    document.getElementById('formatoIds').innerHTML = '';
    document.getElementById('formatoResumen').innerHTML = 'Marbete <strong>' + m.folio + '</strong> · ' + m.n + ' equipo(s). Corrige los datos y guarda.';
    f.elements.tipo.value = m.tipo;
    f.elements.fecha.value = m.fecha;
    f.elements.fecha_verificacion.value = m.fecha_verificacion;
    f.elements.ubicacion.value = m.ubicacion;
    f.elements.observaciones.value = m.observaciones;
    ['solicita', 'costos', 'finanzas', 'gerente'].forEach(c => { f.elements['firma_' + c].value = m['firma_' + c]; });
    const depto = f.elements.depto;
    if (depto.tagName === 'SELECT' && ![...depto.options].some(o => o.value === m.depto)) depto.add(new Option(m.depto, m.depto));
    depto.value = m.depto;
    openModal('modalFormato');
}

// Guardar → crea/edita el marbete por AJAX (la lista se refresca sola); Descargar Excel → baja el archivo sin salir
document.getElementById('formFormato').addEventListener('submit', function (e) {
    e.preventDefault();
    if (e.submitter && e.submitter.hasAttribute('formaction')) {
        const qs = new URLSearchParams(new FormData(this)).toString();
        closeModal('modalFormato');
        descargarExcel('/pages/formato_baja_excel.php?' + qs);
        return;
    }
    const btn = e.submitter || document.getElementById('formatoGuardar');
    btn.disabled = true;
    fetch(this.getAttribute('action'), { method: 'POST', body: new FormData(this), headers: { 'X-Ajax-Request': '1' } })
        .then(r => { if (!r.ok) throw new Error('http'); return r.json(); })
        .then(d => {
            closeModal('modalFormato');
            if (d.ok) limpiarSeleccion();
            zToast(d.msg, d.ok ? 'success' : 'error');
            return cargarZona(location.href, false, true);
        })
        .catch(() => zToast('No se pudo guardar el marbete. Revisa tu conexión e inténtalo de nuevo.', 'error'))
        .finally(() => { btn.disabled = false; });
});

function descargarExcel(url) {
    zToast('Descargando el formato en Excel…', 'success');
    window.location.href = url; // es un adjunto: el navegador lo descarga sin salir de la página
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('modalFormato').classList.contains('open')) closeModal('modalFormato');
});

</script>
</body>
</html>