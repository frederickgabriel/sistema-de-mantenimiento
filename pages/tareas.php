<?php
require_once '../includes/config.php';
requireLogin();
$db = getDB(); $msg = '';
$miId  = (int)$_SESSION['usuario']['id'];
$esAdm = esAdmin();

// Calcula fecha_completado: NOW() al entrar por primera vez a un estado terminal,
// conserva la fecha si ya estaba en un estado terminal, o NULL si no es terminal.
function calcularFechaCompletado(?array $actual, string $nuevoEstado): ?string {
    if (!in_array($nuevoEstado, ['Realizado', 'No Realizado'])) return null;
    if ($actual && in_array($actual['estado'], ['Realizado', 'No Realizado']) && $actual['fecha_completado']) {
        return $actual['fecha_completado'];
    }
    return date('Y-m-d H:i:s');
}

// Un usuario normal solo puede gestionar tareas que tiene asignadas; el admin puede con todas.
function puedeGestionar(bool $esAdm, ?array $tarea, int $miId): bool {
    return $esAdm || ($tarea && (int)$tarea['id_usuario_asignado'] === $miId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'nueva_tarea') {
        $nombre=trim($_POST['nombre_tarea']??''); $desc=trim($_POST['descripcion']??''); $inv=trim($_POST['numero_inventario']??'')?:null; $fecha=$_POST['fecha_programada']?:null; $prior=$_POST['prioridad']??'Media';
        // Un usuario normal solo puede crear tareas asignadas a sí mismo; solo el admin elige a quién asignar.
        $asig = $esAdm ? ($_POST['id_usuario_asignado']?:null) : $miId;
        if ($nombre) { $db->prepare("INSERT INTO Tareas (nombre_tarea,descripcion,numero_inventario,fecha_programada,prioridad,id_usuario_asignado) VALUES (?,?,?,?,?,?)")->execute([$nombre,$desc,$inv,$fecha,$prior,$asig]); $msg="✅ Tarea «{$nombre}» registrada."; }
        else $msg="❌ El nombre es obligatorio.";
    } elseif ($action === 'cambiar_estado') {
        $id=(int)$_POST['id_tarea'];
        $actual=$db->prepare("SELECT estado,fecha_completado,id_usuario_asignado FROM Tareas WHERE id_tarea=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionar($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre esa tarea."; }
        else {
            $fechaCompletado=calcularFechaCompletado($actual,$_POST['estado']);
            $db->prepare("UPDATE Tareas SET estado=?,fecha_completado=? WHERE id_tarea=?")->execute([$_POST['estado'],$fechaCompletado,$id]);
            $msg="✅ Estado actualizado.";
        }
    } elseif ($action === 'completar_tarea') {
        $id=(int)$_POST['id_tarea'];
        $actual=$db->prepare("SELECT estado,fecha_completado,numero_inventario,id_usuario_asignado FROM Tareas WHERE id_tarea=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionar($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre esa tarea."; }
        else {
            $estado=in_array($_POST['estado']??'',['Realizado','No Realizado']) ? $_POST['estado'] : 'Realizado';
            $fechaCompletado=calcularFechaCompletado($actual,$estado);
            $db->prepare("UPDATE Tareas SET estado=?,fecha_completado=? WHERE id_tarea=?")->execute([$estado,$fechaCompletado,$id]);
            $erroresFotos=guardarEvidencias($db,$_FILES['fotos_equipo']??[],'Tarea',$id,$actual['numero_inventario']??null,$miId);
            $msg="✅ Tarea marcada como {$estado}.";
            if ($erroresFotos) $msg.=" ⚠ ".implode(' ',$erroresFotos);
        }
    } elseif ($action === 'editar_tarea') {
        $id=(int)$_POST['id_tarea'];
        $actual=$db->prepare("SELECT estado,fecha_completado,id_usuario_asignado FROM Tareas WHERE id_tarea=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionar($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre esa tarea."; }
        else {
            $nuevoEstado=$_POST['estado']??'Pendiente';
            $fechaCompletado=calcularFechaCompletado($actual,$nuevoEstado);
            // Un usuario normal no puede reasignar la tarea a otra persona; solo el admin puede.
            $nuevoAsignado = $esAdm ? ($_POST['id_usuario_asignado']?:null) : $actual['id_usuario_asignado'];
            $db->prepare("UPDATE Tareas SET nombre_tarea=?,descripcion=?,numero_inventario=?,fecha_programada=?,prioridad=?,estado=?,fecha_completado=?,id_usuario_asignado=? WHERE id_tarea=?")
               ->execute([trim($_POST['nombre_tarea']??''),trim($_POST['descripcion']??''),trim($_POST['numero_inventario']??'')?:null,$_POST['fecha_programada']?:null,$_POST['prioridad']??'Media',$nuevoEstado,$fechaCompletado,$nuevoAsignado,$id]);
            $msg="✅ Tarea actualizada.";
        }
    } elseif ($action === 'eliminar_tarea') {
        $id=(int)$_POST['id_tarea'];
        $actual=$db->prepare("SELECT id_usuario_asignado FROM Tareas WHERE id_tarea=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionar($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre esa tarea."; }
        else { $db->prepare("DELETE FROM Tareas WHERE id_tarea=?")->execute([$id]); $msg="🗑 Tarea eliminada."; }
    }
    header("Location: /pages/tareas.php?msg=".urlencode($msg)."&estado=".urlencode($_GET['estado']??'')."&empleado=".urlencode($_GET['empleado']??'')); exit;
}
if (isset($_GET['msg'])) $msg=$_GET['msg'];
$filtroEstado=$_GET['estado']??'';
$filtroEmpleado = $esAdm ? (int)($_GET['empleado']??0) : 0;

$conds=[]; $params=[];
if ($filtroEstado && in_array($filtroEstado,['Pendiente','En Proceso','Realizado','No Realizado'])) { $conds[]="t.estado=?"; $params[]=$filtroEstado; }
if (!$esAdm) { $conds[]="t.id_usuario_asignado=?"; $params[]=$miId; }
elseif ($filtroEmpleado) { $conds[]="t.id_usuario_asignado=?"; $params[]=$filtroEmpleado; }
$where = $conds ? "WHERE ".implode(' AND ',$conds) : '';

$stmt=$db->prepare("SELECT t.*,e.modelo,a.nombre_area,u.nombre as asignado_nombre,u.foto_perfil as asignado_foto FROM Tareas t LEFT JOIN Equipos e ON e.numero_inventario=t.numero_inventario LEFT JOIN Areas a ON e.id_area=a.id_area LEFT JOIN Usuarios u ON u.id_usuario=t.id_usuario_asignado {$where} ORDER BY CASE t.estado WHEN 'Pendiente' THEN 1 WHEN 'En Proceso' THEN 2 WHEN 'No Realizado' THEN 3 WHEN 'Realizado' THEN 4 END, CASE t.prioridad WHEN 'Alta' THEN 1 WHEN 'Media' THEN 2 WHEN 'Baja' THEN 3 END, t.fecha_programada ASC");
$stmt->execute($params); $tareas=$stmt->fetchAll();

$condsConteo=[]; $paramsConteo=[];
if (!$esAdm) { $condsConteo[]="id_usuario_asignado=?"; $paramsConteo[]=$miId; }
elseif ($filtroEmpleado) { $condsConteo[]="id_usuario_asignado=?"; $paramsConteo[]=$filtroEmpleado; }
$whereConteo = $condsConteo ? "WHERE ".implode(' AND ',$condsConteo) : '';
$conteosStmt=$db->prepare("SELECT estado,COUNT(*) as total FROM Tareas {$whereConteo} GROUP BY estado"); $conteosStmt->execute($paramsConteo); $conteos=$conteosStmt->fetchAll();
$contMap=array_column($conteos,'total','estado');
$equipos=$db->query("SELECT numero_inventario,modelo FROM Equipos WHERE estado != 'Baja' ORDER BY numero_inventario")->fetchAll();
$usuarios=$db->query("SELECT id_usuario,nombre,cargo FROM Usuarios ORDER BY nombre")->fetchAll();
$estados=['Pendiente','En Proceso','Realizado','No Realizado'];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Tareas — <?= SITE_NAME ?></title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block"><link rel="stylesheet" href="/css/estilos.css?v=8"></head>
<body><div class="app-layout"><?php include '../includes/sidebar.php'; ?>
<main class="main-content">
<div class="page-header"><div><div class="page-title"><span class="material-symbols-outlined mi-md">checklist</span> Tareas</div><div class="page-subtitle"><?= $esAdm ? 'Actividades pendientes y seguimiento' : 'Tus tareas asignadas' ?></div></div><div class="page-actions"><button class="btn btn-primary" onclick="openModal('modalNuevaTarea')">+ Nueva Tarea</button></div></div>
<?php if($msg): ?><div class="alert <?= str_starts_with($msg,'✅')?'alert-success':(str_starts_with($msg,'🗑')?'alert-info':'alert-error') ?>"><?= renderMsg($msg) ?></div><?php endif; ?>

<?php if($esAdm): ?>
<div class="form-group" style="max-width:280px;margin-bottom:16px">
    <label>Filtrar por empleado</label>
    <select onchange="location.href='/pages/tareas.php?estado=<?= urlencode($filtroEstado) ?>&empleado='+this.value">
        <option value="">Todos los empleados</option>
        <?php foreach($usuarios as $u): ?>
        <option value="<?= $u['id_usuario'] ?>" <?= $filtroEmpleado===(int)$u['id_usuario']?'selected':'' ?>><?= e($u['nombre']) ?> (<?= e($u['cargo']) ?>)</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div class="tareas-filters">
    <a href="/pages/tareas.php?empleado=<?= $filtroEmpleado ?>" class="filter-btn <?= !$filtroEstado?'active':'' ?>">Todas (<?= array_sum($contMap) ?>)</a>
    <?php foreach(['Pendiente'=>'<span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">hourglass_empty</span> Pendientes','En Proceso'=>'<span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">autorenew</span> En Proceso','No Realizado'=>'<span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">cancel</span> No Realizadas','Realizado'=>'<span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">check_circle</span> Realizadas'] as $k=>$label): ?>
    <a href="/pages/tareas.php?estado=<?= urlencode($k) ?>&empleado=<?= $filtroEmpleado ?>" class="filter-btn <?= $filtroEstado===$k?'active':'' ?>"><?= $label ?> (<?= $contMap[$k]??0 ?>)</a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">push_pin</span> Lista de Tareas</div><span class="text-muted" style="font-size:13px"><?= count($tareas) ?> tareas</span></div>
    <div class="table-wrapper">
    <?php if(empty($tareas)): ?><div class="empty-state"><span class="empty-icon material-symbols-outlined">inbox</span><p>No hay tareas.</p></div>
    <?php else: ?><table><thead><tr><th>Tarea</th><th>Equipo</th><th>Prioridad</th><th>Fecha</th><th>Estado</th><th>Asignado</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($tareas as $t): ?>
    <tr>
        <td><strong><?= e($t['nombre_tarea']) ?></strong><?php if($t['descripcion']): ?><br><small class="text-muted"><?= e(mb_substr($t['descripcion'],0,60)) ?><?= strlen($t['descripcion'])>60?'…':'' ?></small><?php endif; ?></td>
        <td class="text-mono"><?= $t['numero_inventario']?e($t['numero_inventario']):'<span class="text-muted">—</span>' ?></td>
        <td><span class="badge-estado badge-<?= strtolower($t['prioridad']) ?>"><?= e($t['prioridad']) ?></span></td>
        <td><?php if($t['fecha_programada']){$d=(int)((strtotime($t['fecha_programada'])-time())/86400);$c=$d<0?'danger':($d<=3?'warning':'secondary');echo "<span class=\"text-{$c}\">".fechaES($t['fecha_programada'])."</span>";}else echo '<span class="text-muted">—</span>'; ?></td>
        <td><form method="POST" action="/pages/tareas.php?estado=<?= urlencode($filtroEstado) ?>&empleado=<?= $filtroEmpleado ?>"><input type="hidden" name="action" value="cambiar_estado"><input type="hidden" name="id_tarea" value="<?= $t['id_tarea'] ?>"><select name="estado" data-original="<?= e($t['estado']) ?>" onchange="handleEstadoChange(this, <?= htmlspecialchars(json_encode(['id'=>$t['id_tarea'],'nombre'=>$t['nombre_tarea']]),ENT_QUOTES) ?>)" style="background:var(--bg-main);border:1px solid var(--border);color:var(--text-primary);border-radius:6px;padding:4px 8px;font-size:13px;cursor:pointer"><?php foreach($estados as $s): ?><option value="<?= $s ?>" <?= $t['estado']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></form>
        <?php if($t['fecha_completado']): ?><small class="text-muted" style="display:block;margin-top:4px">Completada: <?= fechaES($t['fecha_completado']) ?></small><?php endif; ?>
        </td>
        <td class="text-secondary" style="font-size:13px"><?php if($t['asignado_nombre']): ?><div style="display:flex;align-items:center;gap:6px"><?= avatarChip($t['asignado_foto'],$t['asignado_nombre'],22) ?> <?= e($t['asignado_nombre']) ?></div><?php else: ?>—<?php endif; ?></td>
        <td><div style="display:flex;gap:6px"><button class="btn btn-warning btn-sm btn-icon" onclick="abrirEditar(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)"><span class="material-symbols-outlined mi-sm">edit</span></button><form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar?')"><input type="hidden" name="action" value="eliminar_tarea"><input type="hidden" name="id_tarea" value="<?= $t['id_tarea'] ?>"><button type="submit" class="btn btn-danger btn-sm btn-icon"><span class="material-symbols-outlined mi-sm">delete</span></button></form></div></td>
    </tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
</div>
</main></div>

<!-- Modal Nueva Tarea -->
<div class="modal-overlay" id="modalNuevaTarea"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">checklist</span> Nueva Tarea</div><button class="modal-close" onclick="closeModal('modalNuevaTarea')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body"><form method="POST"><input type="hidden" name="action" value="nueva_tarea"><div class="form-group"><label>Nombre *</label><input type="text" name="nombre_tarea" required></div><div class="form-group"><label>Descripción</label><textarea name="descripcion" rows="3" placeholder="Detalla lo que se necesita hacer..."></textarea></div><div class="form-row"><div class="form-group"><label>Equipo</label><select name="numero_inventario"><option value="">Sin equipo</option><?php foreach($equipos as $eq): ?><option value="<?= e($eq['numero_inventario']) ?>"><?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Prioridad</label><select name="prioridad"><option value="Baja">Baja</option><option value="Media" selected>Media</option><option value="Alta">Alta</option></select></div></div><div class="form-row"><div class="form-group"><label>Fecha Programada</label><input type="date" name="fecha_programada"></div><?php if($esAdm): ?><div class="form-group"><label>Asignar a</label><select name="id_usuario_asignado"><option value="">Sin asignar</option><?php foreach($usuarios as $u): ?><option value="<?= $u['id_usuario'] ?>"><?= e($u['nombre']) ?> (<?= e($u['cargo']) ?>)</option><?php endforeach; ?></select></div><?php endif; ?></div><button type="submit" class="btn btn-primary btn-full">Guardar Tarea</button></form></div></div></div>

<!-- Modal Completar Tarea (con fotos de evidencia) -->
<div class="modal-overlay" id="modalCompletarTarea"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">task_alt</span> Completar: <span id="completarNombreTarea"></span></div><button class="modal-close" onclick="closeModal('modalCompletarTarea')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="completar_tarea"><input type="hidden" name="id_tarea" id="completarIdTarea"><input type="hidden" name="estado" id="completarEstadoInput"><p style="font-size:14px;color:var(--text-secondary);margin-bottom:14px">Se marcará esta tarea como <strong id="completarTituloEstado"></strong>.</p><div class="form-group"><label>Fotos del equipo (antes de abrirlo)</label><input type="file" name="fotos_equipo[]" id="completarFotos" accept="image/*" multiple><span style="font-size:12px;color:var(--text-muted)">Opcional — evidencia de cómo llegó el equipo, máx. 5 fotos.</span></div><button type="submit" class="btn btn-primary btn-full">Guardar</button></form></div></div></div>

<!-- Modal Editar Tarea -->
<div class="modal-overlay" id="modalEditarTarea"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">edit</span> Editar Tarea</div><button class="modal-close" onclick="closeModal('modalEditarTarea')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body"><form method="POST"><input type="hidden" name="action" value="editar_tarea"><input type="hidden" name="id_tarea" id="editTareaId"><div class="form-group"><label>Nombre *</label><input type="text" name="nombre_tarea" id="editNombre" required></div><div class="form-group"><label>Descripción</label><textarea name="descripcion" id="editDesc" rows="3"></textarea></div><div class="form-row"><div class="form-group"><label>Equipo</label><select name="numero_inventario" id="editEquipo"><option value="">Sin equipo</option><?php foreach($equipos as $eq): ?><option value="<?= e($eq['numero_inventario']) ?>"><?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Prioridad</label><select name="prioridad" id="editPrioridad"><option value="Baja">Baja</option><option value="Media">Media</option><option value="Alta">Alta</option></select></div></div><div class="form-row"><div class="form-group"><label>Fecha</label><input type="date" name="fecha_programada" id="editFecha"></div><div class="form-group"><label>Estado</label><select name="estado" id="editEstadoT"><option value="Pendiente">Pendiente</option><option value="En Proceso">En Proceso</option><option value="Realizado">Realizado</option><option value="No Realizado">No Realizado</option></select></div></div><?php if($esAdm): ?><div class="form-group"><label>Asignar a</label><select name="id_usuario_asignado" id="editAsignado"><option value="">Sin asignar</option><?php foreach($usuarios as $u): ?><option value="<?= $u['id_usuario'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?></select></div><?php endif; ?><button type="submit" class="btn btn-warning btn-full">Guardar Cambios</button></form></div></div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')}));
function handleEstadoChange(sel, tarea) {
    const val = sel.value;
    if (val === 'Realizado' || val === 'No Realizado') {
        sel.value = sel.dataset.original;
        abrirCompletar(tarea.id, val, tarea.nombre);
    } else {
        sel.dataset.original = val;
        sel.form.submit();
    }
}
function abrirCompletar(idTarea, estado, nombreTarea) {
    document.getElementById('completarIdTarea').value = idTarea;
    document.getElementById('completarEstadoInput').value = estado;
    document.getElementById('completarTituloEstado').textContent = estado;
    document.getElementById('completarNombreTarea').textContent = nombreTarea;
    document.getElementById('completarFotos').value = '';
    openModal('modalCompletarTarea');
}
function abrirEditar(t){
    document.getElementById('editTareaId').value=t.id_tarea;
    document.getElementById('editNombre').value=t.nombre_tarea||'';
    document.getElementById('editDesc').value=t.descripcion||'';
    document.getElementById('editEquipo').value=t.numero_inventario||'';
    document.getElementById('editPrioridad').value=t.prioridad||'Media';
    document.getElementById('editFecha').value=t.fecha_programada||'';
    document.getElementById('editEstadoT').value=t.estado||'Pendiente';
    if (document.getElementById('editAsignado')) document.getElementById('editAsignado').value=t.id_usuario_asignado||'';
    openModal('modalEditarTarea');
}
</script>
<?php include '../includes/lightbox.php'; ?>
</body></html>