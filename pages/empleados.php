<?php
// =============================================
// EMPLEADOS — Solo Admin
// Archivo: pages/empleados.php
// =============================================
require_once '../includes/config.php';
requireAdmin();

$db = getDB();

$empleados = $db->query("
    SELECT u.id_usuario, u.nombre, u.cargo, u.correo, u.foto_perfil, u.fecha_registro,
           (SELECT COUNT(*) FROM Tareas WHERE id_usuario_asignado = u.id_usuario) AS tareas_total,
           (SELECT COUNT(*) FROM Tareas WHERE id_usuario_asignado = u.id_usuario AND estado = 'Realizado') AS tareas_realizadas,
           (SELECT COUNT(*) FROM Mantenimientos WHERE id_tecnico = u.id_usuario) AS mantenimientos_total,
           (SELECT COUNT(*) FROM EvidenciasEquipo WHERE id_usuario = u.id_usuario) AS evidencias_total
    FROM Usuarios u
    WHERE u.rol = 'usuario'
    ORDER BY u.nombre ASC
")->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Empleados — <?= SITE_NAME ?></title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block"><link rel="stylesheet" href="/css/estilos.css?v=8"></head>
<body><div class="app-layout"><?php include '../includes/sidebar.php'; ?>
<main class="main-content">
<div class="page-header"><div><div class="page-title"><span class="material-symbols-outlined mi-md">groups</span> Empleados</div><div class="page-subtitle">Actividad, tareas realizadas y evidencia fotográfica de equipos</div></div></div>

<?php if (empty($empleados)): ?>
<div class="card"><div class="empty-state"><span class="empty-icon material-symbols-outlined">groups</span><p>No hay empleados registrados todavía.</p></div></div>
<?php else: ?>
<div class="employee-grid">
    <?php foreach ($empleados as $emp): ?>
    <div class="employee-card">
        <div class="employee-card-head">
            <?= avatarChip($emp['foto_perfil'], $emp['nombre'], 56) ?>
            <div class="employee-card-info">
                <span class="employee-card-name"><?= e($emp['nombre']) ?></span>
                <span class="employee-card-cargo"><?= e($emp['cargo']) ?></span>
                <span class="employee-card-correo"><?= e($emp['correo']) ?></span>
            </div>
        </div>
        <div class="employee-card-stats">
            <div class="employee-stat"><strong><?= (int)$emp['tareas_realizadas'] ?>/<?= (int)$emp['tareas_total'] ?></strong><span>Tareas realizadas</span></div>
            <div class="employee-stat"><strong><?= (int)$emp['mantenimientos_total'] ?></strong><span>Mantenimientos</span></div>
            <div class="employee-stat"><strong><?= (int)$emp['evidencias_total'] ?></strong><span>Fotos subidas</span></div>
        </div>
        <a href="/pages/empleado_detalle.php?id=<?= $emp['id_usuario'] ?>" class="btn btn-primary btn-full">
            <span class="material-symbols-outlined mi-sm">visibility</span> Ver actividad
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</main></div>
<?php include '../includes/lightbox.php'; ?>
</body></html>
