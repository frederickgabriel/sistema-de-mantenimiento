<?php
// =============================================
// DETALLE DE EMPLEADO — Solo Admin
// Archivo: pages/empleado_detalle.php
// =============================================
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM Usuarios WHERE id_usuario = ? AND rol = 'usuario'");
$stmt->execute([$id]);
$empleado = $stmt->fetch();

if (!$empleado) {
    header("Location: /pages/empleados.php?msg=" . urlencode("❌ Empleado no encontrado."));
    exit;
}

$tareas = $db->prepare("
    SELECT t.*, e.modelo
    FROM Tareas t
    LEFT JOIN Equipos e ON e.numero_inventario = t.numero_inventario
    WHERE t.id_usuario_asignado = ?
    ORDER BY COALESCE(t.fecha_completado, t.fecha_creacion) DESC
");
$tareas->execute([$id]);
$tareas = $tareas->fetchAll();

$mantenimientos = $db->prepare("
    SELECT m.*, e.modelo, e.marca,
           (SELECT COUNT(*) FROM EvidenciasEquipo WHERE origen = 'Mantenimiento' AND id_origen = m.id_mantenimiento) AS num_evidencias
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    WHERE m.id_tecnico = ?
    ORDER BY m.fecha_realizacion DESC
");
$mantenimientos->execute([$id]);
$mantenimientos = $mantenimientos->fetchAll();

$evidencias = $db->prepare("SELECT * FROM EvidenciasEquipo WHERE id_usuario = ? ORDER BY fecha_subida DESC");
$evidencias->execute([$id]);
$evidencias = $evidencias->fetchAll();

$evidenciasPorMtto = [];
foreach ($evidencias as $ev) {
    if ($ev['origen'] === 'Mantenimiento') $evidenciasPorMtto[$ev['id_origen']][] = $ev;
}

$tareasRealizadas = count(array_filter($tareas, fn($t) => $t['estado'] === 'Realizado'));
$tareasPendientes = count(array_filter($tareas, fn($t) => in_array($t['estado'], ['Pendiente', 'En Proceso'])));
$volver = '/pages/empleado_detalle.php?id=' . $id;
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?= e($empleado['nombre']) ?> — <?= SITE_NAME ?></title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block"><link rel="stylesheet" href="/css/estilos.css?v=8"></head>
<body><div class="app-layout"><?php include '../includes/sidebar.php'; ?>
<main class="main-content">
<div class="page-header">
    <div><div class="page-title"><span class="material-symbols-outlined mi-md">person</span> <?= e($empleado['nombre']) ?></div><div class="page-subtitle">Actividad del empleado</div></div>
    <div class="page-actions"><a href="/pages/empleados.php" class="btn btn-ghost"><span class="material-symbols-outlined mi-sm">arrow_back</span> Volver a Empleados</a></div>
</div>

<div class="card">
    <div class="card-header"><div class="employee-detail-head">
        <?= avatarChip($empleado['foto_perfil'], $empleado['nombre'], 72) ?>
        <div class="employee-card-info">
            <span class="employee-card-name" style="font-size:18px"><?= e($empleado['nombre']) ?></span>
            <span class="employee-card-cargo"><?= e($empleado['cargo']) ?></span>
            <span class="employee-card-correo"><span class="material-symbols-outlined mi-xs" style="vertical-align:-3px">mail</span> <?= e($empleado['correo']) ?></span>
            <span class="employee-card-correo"><span class="material-symbols-outlined mi-xs" style="vertical-align:-3px">event</span> Registrado el <?= fechaES($empleado['fecha_registro']) ?></span>
        </div>
    </div></div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Tareas Realizadas</div><div class="stat-value success"><?= $tareasRealizadas ?></div><div class="stat-meta"><?= count($tareas) ?> asignadas en total</div></div>
    <div class="stat-card"><div class="stat-label">Tareas Pendientes</div><div class="stat-value warning"><?= $tareasPendientes ?></div><div class="stat-meta">Pendientes o en proceso</div></div>
    <div class="stat-card"><div class="stat-label">Mantenimientos</div><div class="stat-value accent"><?= count($mantenimientos) ?></div><div class="stat-meta">Realizados por este empleado</div></div>
    <div class="stat-card"><div class="stat-label">Fotos de Evidencia</div><div class="stat-value"><?= count($evidencias) ?></div><div class="stat-meta">Subidas como evidencia</div></div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">checklist</span> Historial de Tareas</div><span class="text-muted" style="font-size:13px"><?= count($tareas) ?> registros</span></div>
    <div class="table-wrapper">
    <?php if (empty($tareas)): ?><div class="empty-state"><span class="empty-icon material-symbols-outlined">inbox</span><p>Sin tareas asignadas.</p></div>
    <?php else: ?><table><thead><tr><th>Tarea</th><th>Equipo</th><th>Prioridad</th><th>Estado</th><th>Completada</th></tr></thead><tbody>
    <?php foreach ($tareas as $t): ?>
    <tr>
        <td><strong><?= e($t['nombre_tarea']) ?></strong></td>
        <td class="text-mono"><?= $t['numero_inventario'] ? e($t['numero_inventario']) : '<span class="text-muted">—</span>' ?></td>
        <td><span class="badge-estado badge-<?= strtolower($t['prioridad']) ?>"><?= e($t['prioridad']) ?></span></td>
        <td><?= badgeTarea($t['estado']) ?></td>
        <td class="text-secondary"><?= $t['fecha_completado'] ? fechaES($t['fecha_completado']) : '<span class="text-muted">—</span>' ?></td>
    </tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">build</span> Historial de Mantenimientos</div><span class="text-muted" style="font-size:13px"><?= count($mantenimientos) ?> registros</span></div>
    <div class="table-wrapper">
    <?php if (empty($mantenimientos)): ?><div class="empty-state"><span class="empty-icon material-symbols-outlined">build</span><p>Sin mantenimientos registrados.</p></div>
    <?php else: ?><table><thead><tr><th>Equipo</th><th>Tipo</th><th>Estado</th><th>Fecha</th><th>Entrega</th><th>Fotos</th></tr></thead><tbody>
    <?php foreach ($mantenimientos as $m): $evs = $evidenciasPorMtto[$m['id_mantenimiento']] ?? []; $completado = $m['estado'] === 'Completado'; ?>
    <tr>
        <td><span class="text-mono"><?= e($m['numero_inventario']) ?></span><br><small class="text-muted"><?= e($m['modelo']) ?> <?= e($m['marca'] ?? '') ?></small></td>
        <td><?= $m['tipo_mantenimiento'] === 'Preventivo' ? '<span class="badge-estado badge-proceso"><span class="material-symbols-outlined mi-sm">shield</span> Preventivo</span>' : '<span class="badge-estado badge-reparacion"><span class="material-symbols-outlined mi-sm">handyman</span> Correctivo</span>' ?></td>
        <td><span class="badge-estado <?= $completado?'badge-realizado':'badge-pendiente' ?>"><span class="material-symbols-outlined mi-sm"><?= $completado?'check_circle':'hourglass_empty' ?></span> <?= $completado?'Completado':'En Proceso' ?></span></td>
        <td class="text-secondary"><?= fechaES($m['fecha_realizacion']) ?></td>
        <td class="text-secondary"><?= fechaES($m['fecha_entrega']) ?></td>
        <td><button class="btn btn-ghost btn-sm" style="font-size:12px" onclick='abrirFotos(<?= $m["id_mantenimiento"] ?>, <?= json_encode($m["numero_inventario"]) ?>, <?= json_encode(array_map(fn($e)=>["id"=>$e["id_evidencia"],"ruta"=>$e["ruta_imagen"]], $evs)) ?>)'><span class="material-symbols-outlined mi-sm">photo_camera</span> <?= count($evs) ?></button></td>
    </tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">photo_library</span> Evidencias de Equipos</div><span class="text-muted" style="font-size:13px"><?= count($evidencias) ?> fotos</span></div>
    <?php if (empty($evidencias)): ?><div class="empty-state"><span class="empty-icon material-symbols-outlined">image</span><p>Este empleado no ha subido fotos de evidencia.</p></div>
    <?php else: ?>
    <div class="evidencia-grid">
        <?php foreach ($evidencias as $ev): $src = '/uploads/evidencias/' . $ev['ruta_imagen']; ?>
        <div class="evidencia-item">
            <img src="<?= e($src) ?>" alt="Evidencia" onclick="abrirLightbox('<?= e($src) ?>')">
            <div class="evidencia-caption">
                <span><?= $ev['numero_inventario'] ? e($ev['numero_inventario']) : 'Sin equipo' ?></span>
                <span class="text-muted"><?= e($ev['origen']) ?> · <?= fechaES($ev['fecha_subida']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</main></div>

<!-- Modal Gestionar Fotos (reutiliza las acciones de pages/mantenimientos.php) -->
<div class="modal-overlay" id="modalFotos"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">photo_camera</span> Fotos: <span id="fotosLabel"></span></div><button class="modal-close" onclick="closeModal('modalFotos')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body">
    <div class="evidencia-grid" id="fotosGrid" style="padding:0 0 16px"></div>
    <form method="POST" action="/pages/mantenimientos.php" enctype="multipart/form-data"><input type="hidden" name="action" value="agregar_evidencias"><input type="hidden" name="id_mantenimiento" id="fotosIdMtto"><input type="hidden" name="volver" value="<?= e($volver) ?>"><div class="form-group"><label>Agregar fotos</label><input type="file" name="fotos_nuevas[]" id="fotosInput" accept="image/*" multiple><span style="font-size:12px;color:var(--text-muted)">Hasta completar un máximo de 5 fotos en total.</span></div><button type="submit" class="btn btn-primary btn-full">Subir Fotos</button></form>
</div></div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')}));
function abrirFotos(idMtto, inventario, fotos){
    document.getElementById('fotosLabel').textContent = inventario;
    document.getElementById('fotosIdMtto').value = idMtto;
    document.getElementById('fotosInput').value = '';
    const grid = document.getElementById('fotosGrid');
    grid.innerHTML = fotos.length ? '' : '<p class="text-muted" style="font-size:13px">Sin fotos todavía.</p>';
    fotos.forEach(f => {
        const src = '/uploads/evidencias/' + f.ruta;
        const item = document.createElement('div');
        item.className = 'evidencia-item evidencia-item-manage';
        item.innerHTML = `
            <img src="${src}" onclick="abrirLightbox('${src}')">
            <form method="POST" action="/pages/mantenimientos.php" onsubmit="return confirm('¿Eliminar esta foto?')">
                <input type="hidden" name="action" value="eliminar_evidencia">
                <input type="hidden" name="id_evidencia" value="${f.id}">
                <input type="hidden" name="volver" value="<?= e($volver) ?>">
                <button type="submit" class="evidencia-delete-btn" title="Eliminar foto"><span class="material-symbols-outlined mi-sm">close</span></button>
            </form>`;
        grid.appendChild(item);
    });
    openModal('modalFotos');
}
</script>
<?php include '../includes/lightbox.php'; ?>
</body></html>
