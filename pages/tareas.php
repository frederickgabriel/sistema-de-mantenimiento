<?php
require_once '../includes/config.php';
requireLogin();
$db = getDB(); $msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'nueva_tarea') {
        $nombre=trim($_POST['nombre_tarea']??''); $desc=trim($_POST['descripcion']??''); $inv=trim($_POST['numero_inventario']??'')?:null; $fecha=$_POST['fecha_programada']?:null; $prior=$_POST['prioridad']??'Media'; $asig=$_POST['id_usuario_asignado']?:null;
        if ($nombre) { $db->prepare("INSERT INTO Tareas (nombre_tarea,descripcion,numero_inventario,fecha_programada,prioridad,id_usuario_asignado) VALUES (?,?,?,?,?,?)")->execute([$nombre,$desc,$inv,$fecha,$prior,$asig]); $msg="✅ Tarea «{$nombre}» registrada."; }
        else $msg="❌ El nombre es obligatorio.";
    } elseif ($action === 'cambiar_estado') {
        $db->prepare("UPDATE Tareas SET estado=? WHERE id_tarea=?")->execute([$_POST['estado'],(int)$_POST['id_tarea']]); $msg="✅ Estado actualizado.";
    } elseif ($action === 'editar_tarea') {
        $id=(int)$_POST['id_tarea'];
        $db->prepare("UPDATE Tareas SET nombre_tarea=?,descripcion=?,numero_inventario=?,fecha_programada=?,prioridad=?,estado=?,id_usuario_asignado=? WHERE id_tarea=?")
           ->execute([trim($_POST['nombre_tarea']??''),trim($_POST['descripcion']??''),trim($_POST['numero_inventario']??'')?:null,$_POST['fecha_programada']?:null,$_POST['prioridad']??'Media',$_POST['estado']??'Pendiente',$_POST['id_usuario_asignado']?:null,$id]);
        $msg="✅ Tarea actualizada.";
    } elseif ($action === 'eliminar_tarea') {
        $db->prepare("DELETE FROM Tareas WHERE id_tarea=?")->execute([(int)$_POST['id_tarea']]); $msg="🗑 Tarea eliminada.";
    }
    header("Location: /pages/tareas.php?msg=".urlencode($msg)."&estado=".urlencode($_GET['estado']??'')); exit;
}
if (isset($_GET['msg'])) $msg=$_GET['msg'];
$filtroEstado=$_GET['estado']??''; $params=[]; $where='';
if ($filtroEstado && in_array($filtroEstado,['Pendiente','En Proceso','Realizado','No Realizado'])) { $where="WHERE t.estado=?"; $params=[$filtroEstado]; }
$stmt=$db->prepare("SELECT t.*,e.modelo,a.nombre_area,u.nombre as asignado_nombre FROM Tareas t LEFT JOIN Equipos e ON e.numero_inventario=t.numero_inventario LEFT JOIN Areas a ON e.id_area=a.id_area LEFT JOIN Usuarios u ON u.id_usuario=t.id_usuario_asignado {$where} ORDER BY CASE t.estado WHEN 'Pendiente' THEN 1 WHEN 'En Proceso' THEN 2 WHEN 'No Realizado' THEN 3 WHEN 'Realizado' THEN 4 END, CASE t.prioridad WHEN 'Alta' THEN 1 WHEN 'Media' THEN 2 WHEN 'Baja' THEN 3 END, t.fecha_programada ASC");
$stmt->execute($params); $tareas=$stmt->fetchAll();
$conteos=$db->query("SELECT estado,COUNT(*) as total FROM Tareas GROUP BY estado")->fetchAll();
$contMap=array_column($conteos,'total','estado');
$equipos=$db->query("SELECT numero_inventario,modelo FROM Equipos ORDER BY numero_inventario")->fetchAll();
$usuarios=$db->query("SELECT id_usuario,nombre,cargo FROM Usuarios ORDER BY nombre")->fetchAll();
$estados=['Pendiente','En Proceso','Realizado','No Realizado'];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Tareas — <?= SITE_NAME ?></title><link rel="stylesheet" href="/css/estilos.css"></head>
<body><div class="app-layout"><?php include '../includes/sidebar.php'; ?>
<main class="main-content">
<div class="page-header"><div><div class="page-title">📋 Tareas</div><div class="page-subtitle">Actividades pendientes y seguimiento</div></div><div class="page-actions"><button class="btn btn-primary" onclick="openModal('modalNuevaTarea')">+ Nueva Tarea</button></div></div>
<?php if($msg): ?><div class="alert <?= str_starts_with($msg,'✅')?'alert-success':(str_starts_with($msg,'🗑')?'alert-info':'alert-error') ?>"><?= e($msg) ?></div><?php endif; ?>

<div class="tareas-filters">
    <a href="/pages/tareas.php" class="filter-btn <?= !$filtroEstado?'active':'' ?>">Todas (<?= array_sum($contMap) ?>)</a>
    <?php foreach(['Pendiente'=>'⏳ Pendientes','En Proceso'=>'🔄 En Proceso','No Realizado'=>'❌ No Realizadas','Realizado'=>'✅ Realizadas'] as $k=>$label): ?>
    <a href="/pages/tareas.php?estado=<?= urlencode($k) ?>" class="filter-btn <?= $filtroEstado===$k?'active':'' ?>"><?= $label ?> (<?= $contMap[$k]??0 ?>)</a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title">📌 Lista de Tareas</div><span class="text-muted" style="font-size:13px"><?= count($tareas) ?> tareas</span></div>
    <div class="table-wrapper">
    <?php if(empty($tareas)): ?><div class="empty-state"><span class="empty-icon">📭</span><p>No hay tareas.</p></div>
    <?php else: ?><table><thead><tr><th>Tarea</th><th>Equipo</th><th>Prioridad</th><th>Fecha</th><th>Estado</th><th>Asignado</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($tareas as $t): ?>
    <tr>
        <td><strong><?= e($t['nombre_tarea']) ?></strong><?php if($t['descripcion']): ?><br><small class="text-muted"><?= e(mb_substr($t['descripcion'],0,60)) ?><?= strlen($t['descripcion'])>60?'…':'' ?></small><?php endif; ?></td>
        <td class="text-mono"><?= $t['numero_inventario']?e($t['numero_inventario']):'<span class="text-muted">—</span>' ?></td>
        <td><span class="badge-estado badge-<?= strtolower($t['prioridad']) ?>"><?= e($t['prioridad']) ?></span></td>
        <td><?php if($t['fecha_programada']){$d=(int)((strtotime($t['fecha_programada'])-time())/86400);$c=$d<0?'danger':($d<=3?'warning':'secondary');echo "<span class=\"text-{$c}\">".fechaES($t['fecha_programada'])."</span>";}else echo '<span class="text-muted">—</span>'; ?></td>
        <td><form method="POST" action="/pages/tareas.php?estado=<?= urlencode($filtroEstado) ?>"><input type="hidden" name="action" value="cambiar_estado"><input type="hidden" name="id_tarea" value="<?= $t['id_tarea'] ?>"><select name="estado" onchange="this.form.submit()" style="background:var(--bg-main);border:1px solid var(--border);color:var(--text-primary);border-radius:6px;padding:4px 8px;font-size:13px;cursor:pointer"><?php foreach($estados as $s): ?><option value="<?= $s ?>" <?= $t['estado']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></form></td>
        <td class="text-secondary" style="font-size:13px"><?= e($t['asignado_nombre']??'—') ?></td>
        <td><div style="display:flex;gap:6px"><button class="btn btn-warning btn-sm btn-icon" onclick="abrirEditar(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)">✏</button><form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar?')"><input type="hidden" name="action" value="eliminar_tarea"><input type="hidden" name="id_tarea" value="<?= $t['id_tarea'] ?>"><button type="submit" class="btn btn-danger btn-sm btn-icon">🗑</button></form></div></td>
    </tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
</div>
</main></div>

<!-- Modal Nueva Tarea -->
<div class="modal-overlay" id="modalNuevaTarea"><div class="modal-box"><div class="modal-header"><div class="modal-title">📋 Nueva Tarea</div><button class="modal-close" onclick="closeModal('modalNuevaTarea')">✕</button></div><div class="modal-body"><form method="POST"><input type="hidden" name="action" value="nueva_tarea"><div class="form-group"><label>Nombre *</label><input type="text" name="nombre_tarea" required></div><div class="form-group"><label>Descripción</label><textarea name="descripcion" rows="3" placeholder="Detalla lo que se necesita hacer..."></textarea></div><div class="form-row"><div class="form-group"><label>Equipo</label><select name="numero_inventario"><option value="">Sin equipo</option><?php foreach($equipos as $eq): ?><option value="<?= e($eq['numero_inventario']) ?>"><?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Prioridad</label><select name="prioridad"><option value="Baja">🟢 Baja</option><option value="Media" selected>🟡 Media</option><option value="Alta">🔴 Alta</option></select></div></div><div class="form-row"><div class="form-group"><label>Fecha Programada</label><input type="date" name="fecha_programada"></div><div class="form-group"><label>Asignar a</label><select name="id_usuario_asignado"><option value="">Sin asignar</option><?php foreach($usuarios as $u): ?><option value="<?= $u['id_usuario'] ?>"><?= e($u['nombre']) ?> (<?= e($u['cargo']) ?>)</option><?php endforeach; ?></select></div></div><button type="submit" class="btn btn-primary btn-full">Guardar Tarea</button></form></div></div></div>

<!-- Modal Editar Tarea -->
<div class="modal-overlay" id="modalEditarTarea"><div class="modal-box"><div class="modal-header"><div class="modal-title">✏ Editar Tarea</div><button class="modal-close" onclick="closeModal('modalEditarTarea')">✕</button></div><div class="modal-body"><form method="POST"><input type="hidden" name="action" value="editar_tarea"><input type="hidden" name="id_tarea" id="editTareaId"><div class="form-group"><label>Nombre *</label><input type="text" name="nombre_tarea" id="editNombre" required></div><div class="form-group"><label>Descripción</label><textarea name="descripcion" id="editDesc" rows="3"></textarea></div><div class="form-row"><div class="form-group"><label>Equipo</label><select name="numero_inventario" id="editEquipo"><option value="">Sin equipo</option><?php foreach($equipos as $eq): ?><option value="<?= e($eq['numero_inventario']) ?>"><?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Prioridad</label><select name="prioridad" id="editPrioridad"><option value="Baja">🟢 Baja</option><option value="Media">🟡 Media</option><option value="Alta">🔴 Alta</option></select></div></div><div class="form-row"><div class="form-group"><label>Fecha</label><input type="date" name="fecha_programada" id="editFecha"></div><div class="form-group"><label>Estado</label><select name="estado" id="editEstadoT"><option value="Pendiente">⏳ Pendiente</option><option value="En Proceso">🔄 En Proceso</option><option value="Realizado">✅ Realizado</option><option value="No Realizado">❌ No Realizado</option></select></div></div><div class="form-group"><label>Asignar a</label><select name="id_usuario_asignado" id="editAsignado"><option value="">Sin asignar</option><?php foreach($usuarios as $u): ?><option value="<?= $u['id_usuario'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?></select></div><button type="submit" class="btn btn-warning btn-full">Guardar Cambios</button></form></div></div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')}));
function abrirEditar(t){
    document.getElementById('editTareaId').value=t.id_tarea;
    document.getElementById('editNombre').value=t.nombre_tarea||'';
    document.getElementById('editDesc').value=t.descripcion||'';
    document.getElementById('editEquipo').value=t.numero_inventario||'';
    document.getElementById('editPrioridad').value=t.prioridad||'Media';
    document.getElementById('editFecha').value=t.fecha_programada||'';
    document.getElementById('editEstadoT').value=t.estado||'Pendiente';
    document.getElementById('editAsignado').value=t.id_usuario_asignado||'';
    openModal('modalEditarTarea');
}
</script>
</body></html>