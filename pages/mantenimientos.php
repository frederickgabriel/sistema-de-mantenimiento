<?php
require_once '../includes/config.php';
requireLogin();
$db = getDB(); $msg = '';
$miId  = (int)$_SESSION['usuario']['id'];
$esAdm = esAdmin();

// Un usuario normal solo puede gestionar los mantenimientos que él mismo registró; el admin puede con todos.
function puedeGestionarMtto(bool $esAdm, ?array $m, int $miId): bool {
    return $esAdm || ($m && (int)$m['id_tecnico'] === $miId);
}

// A dónde regresar tras procesar el POST: la página de origen (p.ej. empleado_detalle.php) si vino
// en un campo oculto "volver" y es una ruta interna conocida; si no, esta misma página con sus filtros.
function destinoSeguro(?string $volver): ?string {
    if ($volver && preg_match('#^/pages/(mantenimientos|empleado_detalle)\.php(\?[a-zA-Z0-9=&_.%-]*)?$#', $volver)) {
        return $volver;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $destino = destinoSeguro($_POST['volver'] ?? null)
             ?? ('/pages/mantenimientos.php?equipo='.urlencode($_GET['equipo']??'').'&tecnico='.urlencode($_GET['tecnico']??''));

    if ($action === 'nuevo_mantenimiento') {
        $inv=$_POST['numero_inventario']??''; $tipo=$_POST['tipo_mantenimiento']??'Preventivo';
        $fIni=$_POST['fecha_realizacion']??''; $fEnt=$_POST['fecha_entrega']?:null; $det=trim($_POST['detalles']??'');
        $base=$fEnt?:$fIni; $proximo=date('Y-m-d',strtotime($base.' +6 months'));
        $db->prepare("INSERT INTO Mantenimientos (numero_inventario,tipo_mantenimiento,fecha_realizacion,fecha_entrega,proximo_mantenimiento,detalles,id_tecnico) VALUES (?,?,?,?,?,?,?)")
           ->execute([$inv,$tipo,$fIni,$fEnt,$proximo,$det,$miId]);
        $idMtto = (int)$db->lastInsertId();
        $erroresFotos = guardarEvidencias($db, $_FILES['fotos_equipo'] ?? [], 'Mantenimiento', $idMtto, $inv, $miId);
        $msg="✅ Mantenimiento registrado. Próxima cita: ".fechaES($proximo);
        if ($erroresFotos) $msg .= " ⚠ " . implode(' ', $erroresFotos);

    } elseif ($action === 'reagendar') {
        $id=(int)$_POST['id_mantenimiento'];
        $actual=$db->prepare("SELECT id_tecnico FROM Mantenimientos WHERE id_mantenimiento=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionarMtto($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre ese mantenimiento."; }
        else {
            $db->prepare("UPDATE Mantenimientos SET proximo_mantenimiento=? WHERE id_mantenimiento=?")->execute([$_POST['nueva_fecha'],$id]);
            $msg="✅ Fecha reagendada a ".fechaES($_POST['nueva_fecha']);
        }

    } elseif ($action === 'editar_mantenimiento') {
        $id=(int)$_POST['id_mantenimiento'];
        $actual=$db->prepare("SELECT id_tecnico,estado FROM Mantenimientos WHERE id_mantenimiento=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionarMtto($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre ese mantenimiento."; }
        elseif ($actual['estado'] === 'Completado') { $msg="❌ Este mantenimiento ya está completado y no se puede modificar."; }
        else {
            $tipo=$_POST['tipo_mantenimiento']??'Preventivo'; $fIni=$_POST['fecha_realizacion']??''; $fEnt=$_POST['fecha_entrega']?:null; $det=trim($_POST['detalles']??'');
            $base=$fEnt?:$fIni; $proximo=date('Y-m-d',strtotime($base.' +6 months'));
            $db->prepare("UPDATE Mantenimientos SET tipo_mantenimiento=?,fecha_realizacion=?,fecha_entrega=?,proximo_mantenimiento=?,detalles=? WHERE id_mantenimiento=?")
               ->execute([$tipo,$fIni,$fEnt,$proximo,$det,$id]);
            $msg="✅ Mantenimiento actualizado.";
        }

    } elseif ($action === 'marcar_completado') {
        $id=(int)$_POST['id_mantenimiento'];
        $actual=$db->prepare("SELECT id_tecnico,estado FROM Mantenimientos WHERE id_mantenimiento=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionarMtto($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre ese mantenimiento."; }
        else {
            $db->prepare("UPDATE Mantenimientos SET estado='Completado' WHERE id_mantenimiento=?")->execute([$id]);
            $msg="✅ Mantenimiento marcado como completado.";
        }

    } elseif ($action === 'agregar_evidencias') {
        $id=(int)$_POST['id_mantenimiento'];
        $actual=$db->prepare("SELECT id_tecnico,numero_inventario FROM Mantenimientos WHERE id_mantenimiento=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionarMtto($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre ese mantenimiento."; }
        else {
            $stmtCnt=$db->prepare("SELECT COUNT(*) FROM EvidenciasEquipo WHERE origen='Mantenimiento' AND id_origen=?"); $stmtCnt->execute([$id]); $existentes=(int)$stmtCnt->fetchColumn();
            $restante=max(0,5-$existentes);
            if ($restante === 0) { $msg="❌ Ya alcanzaste el máximo de 5 fotos para este mantenimiento."; }
            else {
                $erroresFotos = guardarEvidencias($db, $_FILES['fotos_nuevas'] ?? [], 'Mantenimiento', $id, $actual['numero_inventario'], $miId, $restante);
                $msg = $erroresFotos ? "⚠ ".implode(' ',$erroresFotos) : "✅ Fotos agregadas.";
            }
        }

    } elseif ($action === 'eliminar_evidencia') {
        $idEv=(int)$_POST['id_evidencia'];
        $ev=$db->prepare("SELECT * FROM EvidenciasEquipo WHERE id_evidencia=?"); $ev->execute([$idEv]); $ev=$ev->fetch();
        if (!$ev) { $msg="❌ Foto no encontrada."; }
        else {
            $mtto=null;
            if ($ev['origen']==='Mantenimiento') { $q=$db->prepare("SELECT id_tecnico FROM Mantenimientos WHERE id_mantenimiento=?"); $q->execute([$ev['id_origen']]); $mtto=$q->fetch(); }
            $permitido = $ev['origen']==='Mantenimiento' ? puedeGestionarMtto($esAdm,$mtto,$miId) : ($esAdm || (int)$ev['id_usuario']===$miId);
            if (!$permitido) { $msg="❌ No tienes permiso sobre esa foto."; }
            else {
                $ruta = $_SERVER['DOCUMENT_ROOT'].'/uploads/evidencias/'.$ev['ruta_imagen'];
                if (file_exists($ruta)) unlink($ruta);
                $db->prepare("DELETE FROM EvidenciasEquipo WHERE id_evidencia=?")->execute([$idEv]);
                $msg="🗑 Foto eliminada.";
            }
        }

    } elseif ($action === 'eliminar_mantenimiento') {
        $id=(int)$_POST['id_mantenimiento'];
        $actual=$db->prepare("SELECT id_tecnico FROM Mantenimientos WHERE id_mantenimiento=?"); $actual->execute([$id]); $actual=$actual->fetch();
        if (!puedeGestionarMtto($esAdm,$actual,$miId)) { $msg="❌ No tienes permiso sobre ese mantenimiento."; }
        else {
            $evs=$db->prepare("SELECT * FROM EvidenciasEquipo WHERE origen='Mantenimiento' AND id_origen=?"); $evs->execute([$id]); $evs=$evs->fetchAll();
            foreach ($evs as $ev) { $ruta=$_SERVER['DOCUMENT_ROOT'].'/uploads/evidencias/'.$ev['ruta_imagen']; if (file_exists($ruta)) unlink($ruta); }
            $db->prepare("DELETE FROM EvidenciasEquipo WHERE origen='Mantenimiento' AND id_origen=?")->execute([$id]);
            $db->prepare("DELETE FROM Mantenimientos WHERE id_mantenimiento=?")->execute([$id]);
            $msg="🗑 Registro eliminado.";
        }
    }

    $sep = strpos($destino,'?')!==false ? '&' : '?';
    header("Location: {$destino}{$sep}msg=".urlencode($msg)); exit;
}

if (isset($_GET['msg'])) $msg=$_GET['msg'];
$filtroEquipo = trim($_GET['equipo']??'');
$filtroTecnico = $esAdm ? (int)($_GET['tecnico']??0) : 0;

$conds=[]; $params=[];
if ($filtroEquipo) { $conds[]="m.numero_inventario=?"; $params[]=$filtroEquipo; }
if (!$esAdm) { $conds[]="m.id_tecnico=?"; $params[]=$miId; }
elseif ($filtroTecnico) { $conds[]="m.id_tecnico=?"; $params[]=$filtroTecnico; }
$where = $conds ? "WHERE ".implode(' AND ',$conds) : '';

$stmt=$db->prepare("SELECT m.*,e.modelo,e.marca,a.nombre_area,u.nombre as tecnico_nombre,u.foto_perfil as tecnico_foto FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario LEFT JOIN Areas a ON e.id_area=a.id_area LEFT JOIN Usuarios u ON u.id_usuario=m.id_tecnico {$where} ORDER BY m.fecha_realizacion DESC");
$stmt->execute($params); $mantenimientos=$stmt->fetchAll();
$equipos=$db->query("SELECT numero_inventario,modelo,marca FROM Equipos WHERE estado != 'Baja' ORDER BY numero_inventario")->fetchAll();
$usuarios=$db->query("SELECT id_usuario,nombre,cargo FROM Usuarios ORDER BY nombre")->fetchAll();

// Evidencias agrupadas por mantenimiento, para alimentar el modal "Gestionar Fotos" de cada fila.
$evidenciasPorMtto = [];
if ($mantenimientos) {
    $ids = array_column($mantenimientos,'id_mantenimiento');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $evStmt = $db->prepare("SELECT * FROM EvidenciasEquipo WHERE origen='Mantenimiento' AND id_origen IN ({$in}) ORDER BY fecha_subida DESC");
    $evStmt->execute($ids);
    foreach ($evStmt->fetchAll() as $ev) { $evidenciasPorMtto[$ev['id_origen']][] = $ev; }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Mantenimientos — <?= SITE_NAME ?></title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block"><link rel="stylesheet" href="/css/estilos.css?v=8"></head>
<body><div class="app-layout"><?php include '../includes/sidebar.php'; ?>
<main class="main-content">
<div class="page-header"><div><div class="page-title"><span class="material-symbols-outlined mi-md">build</span> Mantenimientos</div><div class="page-subtitle"><?= $esAdm ? 'Historial y registro de mantenimientos' : 'Tus mantenimientos registrados' ?></div></div><div class="page-actions"><?php if($filtroEquipo): ?><a href="/pages/mantenimientos.php" class="btn btn-ghost"><span class="material-symbols-outlined mi-sm">close</span> Quitar filtro</a><?php endif; ?><button class="btn btn-primary" onclick="openModal('modalNuevoMtto')">+ Registrar Mantenimiento</button></div></div>
<?php if($msg): ?><div class="alert <?= str_starts_with($msg,'✅')?'alert-success':(str_starts_with($msg,'🗑')?'alert-info':'alert-error') ?>"><?= renderMsg($msg) ?></div><?php endif; ?>
<?php if($filtroEquipo): ?><div class="alert alert-info"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">search</span> Mantenimientos del equipo: <strong><?= e($filtroEquipo) ?></strong></div><?php endif; ?>

<?php if($esAdm): ?>
<div class="form-group" style="max-width:280px;margin-bottom:16px">
    <label>Filtrar por técnico</label>
    <select onchange="location.href='/pages/mantenimientos.php?equipo=<?= urlencode($filtroEquipo) ?>&tecnico='+this.value">
        <option value="">Todos los técnicos</option>
        <?php foreach($usuarios as $u): ?>
        <option value="<?= $u['id_usuario'] ?>" <?= $filtroTecnico===(int)$u['id_usuario']?'selected':'' ?>><?= e($u['nombre']) ?> (<?= e($u['cargo']) ?>)</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">checklist</span> Historial de Mantenimientos</div><span class="text-muted" style="font-size:13px"><?= count($mantenimientos) ?> registros</span></div>
    <div class="table-wrapper">
    <?php if(empty($mantenimientos)): ?><div class="empty-state"><span class="empty-icon material-symbols-outlined">build</span><p>No hay mantenimientos registrados.</p></div>
    <?php else: ?><table><thead><tr><th>Equipo</th><th>Área</th><th>Tipo</th><th>Fecha Inicio</th><th>Fecha Entrega</th><th>Próx. Mantenimiento</th><th>Técnico</th><th>Fotos</th><th>Detalles</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($mantenimientos as $m):
        $puede = puedeGestionarMtto($esAdm,$m,$miId);
        $completado = $m['estado'] === 'Completado';
        $evs = $evidenciasPorMtto[$m['id_mantenimiento']] ?? [];
    ?>
    <tr>
        <td><span class="text-mono"><?= e($m['numero_inventario']) ?></span><br><small class="text-muted"><?= e($m['modelo']) ?> <?= e($m['marca']??'') ?></small></td>
        <td class="text-secondary"><?= e($m['nombre_area']??'—') ?></td>
        <td><?= $m['tipo_mantenimiento']==='Preventivo'?'<span class="badge-estado badge-proceso"><span class="material-symbols-outlined mi-sm">shield</span> Preventivo</span>':'<span class="badge-estado badge-reparacion"><span class="material-symbols-outlined mi-sm">handyman</span> Correctivo</span>' ?><br><span class="badge-estado <?= $completado?'badge-realizado':'badge-pendiente' ?>" style="margin-top:4px"><span class="material-symbols-outlined mi-sm"><?= $completado?'check_circle':'hourglass_empty' ?></span> <?= $completado?'Completado':'En Proceso' ?></span></td>
        <td class="text-secondary"><?= fechaES($m['fecha_realizacion']) ?></td>
        <td class="text-secondary"><?= fechaES($m['fecha_entrega']) ?></td>
        <td><?php if($m['proximo_mantenimiento']){$dias=(int)((strtotime($m['proximo_mantenimiento'])-time())/86400);$c=$dias<0?'danger':($dias<=14?'warning':'success');echo "<span class=\"text-{$c}\">".fechaES($m['proximo_mantenimiento'])."</span>";if($dias<0)echo "<br><small class=\"text-danger\">Vencido ".abs($dias)."d</small>";elseif($dias<=14)echo "<br><small class=\"text-warning\">En {$dias} días</small>";}else echo '<span class="text-muted">—</span>'; ?></td>
        <td class="text-secondary" style="font-size:13px"><?php if($m['tecnico_nombre']): ?><div style="display:flex;align-items:center;gap:6px"><?= avatarChip($m['tecnico_foto'],$m['tecnico_nombre'],22) ?> <?= e($m['tecnico_nombre']) ?></div><?php else: ?>—<?php endif; ?></td>
        <td>
            <?php if($puede): ?>
            <button class="btn btn-ghost btn-sm" style="font-size:12px" onclick='abrirFotos(<?= $m["id_mantenimiento"] ?>, <?= json_encode($m["numero_inventario"]) ?>, <?= json_encode(array_map(fn($e)=>["id"=>$e["id_evidencia"],"ruta"=>$e["ruta_imagen"]], $evs)) ?>)'><span class="material-symbols-outlined mi-sm">photo_camera</span> <?= count($evs) ?></button>
            <?php elseif(count($evs)>0): ?>
            <span class="badge-estado badge-proceso"><span class="material-symbols-outlined mi-sm">photo_camera</span> <?= count($evs) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td style="max-width:160px;font-size:12px;color:var(--text-secondary)">
            <?= e(mb_substr($m['detalles']??'',0,60)) ?><?= strlen($m['detalles']??'')>60?'…':'' ?>
            <button class="btn btn-ghost btn-sm btn-icon" style="margin-top:4px" title="Ver detalle" onclick='abrirDetalle(<?= json_encode([
                "numero_inventario"=>$m["numero_inventario"],"modelo"=>$m["modelo"],"marca"=>$m["marca"],"nombre_area"=>$m["nombre_area"],
                "tipo_mantenimiento"=>$m["tipo_mantenimiento"],"estado"=>$m["estado"],"fecha_realizacion"=>$m["fecha_realizacion"],
                "fecha_entrega"=>$m["fecha_entrega"],"proximo_mantenimiento"=>$m["proximo_mantenimiento"],"tecnico_nombre"=>$m["tecnico_nombre"],"detalles"=>$m["detalles"]
            ]) ?>)'><span class="material-symbols-outlined mi-sm">visibility</span></button>
        </td>
        <td><div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if($puede): ?>
            <button class="btn btn-ghost btn-sm btn-icon" title="Reagendar" onclick="abrirReagendar(<?= $m['id_mantenimiento'] ?>,'<?= e($m['numero_inventario']) ?>','<?= $m['proximo_mantenimiento'] ?>')"><span class="material-symbols-outlined mi-sm">calendar_month</span></button>
            <?php if(!$completado): ?>
            <button class="btn btn-warning btn-sm btn-icon" title="Editar" onclick='abrirEditarMtto(<?= json_encode($m) ?>)'><span class="material-symbols-outlined mi-sm">edit</span></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Marcar este mantenimiento como completado? Ya no podrás editarlo.')"><input type="hidden" name="action" value="marcar_completado"><input type="hidden" name="id_mantenimiento" value="<?= $m['id_mantenimiento'] ?>"><button type="submit" class="btn btn-ghost btn-sm btn-icon" title="Marcar como completado"><span class="material-symbols-outlined mi-sm">task_alt</span></button></form>
            <?php endif; ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar?')"><input type="hidden" name="action" value="eliminar_mantenimiento"><input type="hidden" name="id_mantenimiento" value="<?= $m['id_mantenimiento'] ?>"><button type="submit" class="btn btn-danger btn-sm btn-icon" title="Eliminar"><span class="material-symbols-outlined mi-sm">delete</span></button></form>
            <?php else: ?><span class="text-muted" style="font-size:12px">—</span><?php endif; ?>
        </div></td>
    </tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
</div>
</main></div>

<div class="modal-overlay" id="modalNuevoMtto"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">build</span> Registrar Mantenimiento</div><button class="modal-close" onclick="closeModal('modalNuevoMtto')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="nuevo_mantenimiento"><div class="form-group"><label>Equipo *</label><select name="numero_inventario" required><option value="">Selecciona...</option><?php foreach($equipos as $eq): ?><option value="<?= e($eq['numero_inventario']) ?>" <?= $filtroEquipo===$eq['numero_inventario']?'selected':'' ?>><?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Tipo *</label><select name="tipo_mantenimiento" required><option value="Preventivo">Preventivo</option><option value="Correctivo">Correctivo</option></select></div><div class="form-row"><div class="form-group"><label>Fecha Inicio *</label><input type="date" name="fecha_realizacion" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label>Fecha Entrega</label><input type="date" name="fecha_entrega"></div></div><div class="form-group"><label>Detalles</label><textarea name="detalles" rows="3"></textarea></div><div class="form-group"><label>Fotos del equipo (antes de abrirlo)</label><input type="file" name="fotos_equipo[]" accept="image/*" multiple><span style="font-size:12px;color:var(--text-muted)">Documenta cómo llegó el equipo antes de abrirlo — opcional, máx. 5 fotos.</span></div><p style="font-size:12px;color:var(--text-muted);margin-bottom:12px"><span class="material-symbols-outlined mi-xs" style="vertical-align:-2px">lightbulb</span> La próxima cita se agenda automáticamente a 6 meses.</p><button type="submit" class="btn btn-primary btn-full">Guardar Historial</button></form></div></div></div>

<div class="modal-overlay" id="modalReagendar"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">calendar_month</span> Reagendar: <span id="reagendarLabel"></span></div><button class="modal-close" onclick="closeModal('modalReagendar')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body"><form method="POST"><input type="hidden" name="action" value="reagendar"><input type="hidden" name="id_mantenimiento" id="reagendarId"><div class="form-group"><label>Nueva Fecha</label><input type="date" name="nueva_fecha" id="reagendarFecha" required></div><button type="submit" class="btn btn-primary btn-full">Confirmar Nueva Fecha</button></form></div></div></div>

<!-- Modal Editar Mantenimiento -->
<div class="modal-overlay" id="modalEditarMtto"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">edit</span> Editar Mantenimiento: <span id="editarMttoLabel"></span></div><button class="modal-close" onclick="closeModal('modalEditarMtto')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body"><form method="POST"><input type="hidden" name="action" value="editar_mantenimiento"><input type="hidden" name="id_mantenimiento" id="editarMttoId"><div class="form-group"><label>Tipo *</label><select name="tipo_mantenimiento" id="editarMttoTipo" required><option value="Preventivo">Preventivo</option><option value="Correctivo">Correctivo</option></select></div><div class="form-row"><div class="form-group"><label>Fecha Inicio *</label><input type="date" name="fecha_realizacion" id="editarMttoFIni" required></div><div class="form-group"><label>Fecha Entrega</label><input type="date" name="fecha_entrega" id="editarMttoFEnt"></div></div><div class="form-group"><label>Detalles</label><textarea name="detalles" id="editarMttoDet" rows="3"></textarea></div><p style="font-size:12px;color:var(--text-muted);margin-bottom:12px"><span class="material-symbols-outlined mi-xs" style="vertical-align:-2px">info</span> El equipo no se puede cambiar; si te equivocaste de equipo, elimina este registro y crea uno nuevo.</p><button type="submit" class="btn btn-warning btn-full">Guardar Cambios</button></form></div></div></div>

<!-- Modal Ver Detalle -->
<div class="modal-overlay" id="modalVerDetalle"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">description</span> Detalle del Mantenimiento</div><button class="modal-close" onclick="closeModal('modalVerDetalle')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body" id="detalleBody" style="font-size:14px;line-height:1.7"></div></div></div>

<!-- Modal Gestionar Fotos -->
<div class="modal-overlay" id="modalFotos"><div class="modal-box"><div class="modal-header"><div class="modal-title"><span class="material-symbols-outlined mi-md">photo_camera</span> Fotos: <span id="fotosLabel"></span></div><button class="modal-close" onclick="closeModal('modalFotos')"><span class="material-symbols-outlined mi-sm">close</span></button></div><div class="modal-body">
    <div class="evidencia-grid" id="fotosGrid" style="padding:0 0 16px"></div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="agregar_evidencias"><input type="hidden" name="id_mantenimiento" id="fotosIdMtto"><div class="form-group"><label>Agregar fotos</label><input type="file" name="fotos_nuevas[]" id="fotosInput" accept="image/*" multiple><span style="font-size:12px;color:var(--text-muted)">Hasta completar un máximo de 5 fotos en total.</span></div><button type="submit" class="btn btn-primary btn-full">Subir Fotos</button></form>
</div></div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')}));
function abrirReagendar(id,inv,fecha){document.getElementById('reagendarLabel').textContent=inv;document.getElementById('reagendarId').value=id;document.getElementById('reagendarFecha').value=fecha||'';openModal('modalReagendar');}

function abrirEditarMtto(m){
    document.getElementById('editarMttoLabel').textContent=m.numero_inventario;
    document.getElementById('editarMttoId').value=m.id_mantenimiento;
    document.getElementById('editarMttoTipo').value=m.tipo_mantenimiento||'Preventivo';
    document.getElementById('editarMttoFIni').value=m.fecha_realizacion||'';
    document.getElementById('editarMttoFEnt').value=m.fecha_entrega||'';
    document.getElementById('editarMttoDet').value=m.detalles||'';
    openModal('modalEditarMtto');
}

function abrirDetalle(m){
    const dias = m.proximo_mantenimiento ? m.proximo_mantenimiento : '—';
    document.getElementById('detalleBody').innerHTML = `
        <p><strong>Equipo:</strong> ${m.numero_inventario} — ${m.modelo||''} ${m.marca||''}</p>
        <p><strong>Área:</strong> ${m.nombre_area||'—'}</p>
        <p><strong>Tipo:</strong> ${m.tipo_mantenimiento} &nbsp; <strong>Estado:</strong> ${m.estado}</p>
        <p><strong>Fecha inicio:</strong> ${m.fecha_realizacion||'—'} &nbsp; <strong>Entrega:</strong> ${m.fecha_entrega||'—'}</p>
        <p><strong>Próx. mantenimiento:</strong> ${dias}</p>
        <p><strong>Técnico:</strong> ${m.tecnico_nombre||'—'}</p>
        <hr class="divider">
        <p style="white-space:pre-wrap">${m.detalles ? m.detalles.replace(/</g,'&lt;') : 'Sin detalles registrados.'}</p>
    `;
    openModal('modalVerDetalle');
}

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
            <form method="POST" onsubmit="return confirm('¿Eliminar esta foto?')">
                <input type="hidden" name="action" value="eliminar_evidencia">
                <input type="hidden" name="id_evidencia" value="${f.id}">
                <button type="submit" class="evidencia-delete-btn" title="Eliminar foto"><span class="material-symbols-outlined mi-sm">close</span></button>
            </form>`;
        grid.appendChild(item);
    });
    openModal('modalFotos');
}
</script>
<?php include '../includes/lightbox.php'; ?>
</body></html>
