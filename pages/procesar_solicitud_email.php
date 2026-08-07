<?php
// =============================================
// PROCESAR SOLICITUD DE ROL DESDE EL CORREO
// Archivo: pages/procesar_solicitud_email.php
// Enlace de un solo uso, sin necesidad de iniciar sesión.
// =============================================
require_once '../includes/config.php';

$db     = getDB();
$token  = $_GET['token']  ?? '';
$accion = $_GET['accion'] ?? '';

$titulo  = '';
$icono   = '';
$color   = '';
$detalle = '';

if (!$token || !in_array($accion, ['aprobar', 'rechazar'], true)) {
    $titulo  = 'Enlace inválido';
    $icono   = 'error';
    $color   = 'var(--danger)';
    $detalle = 'El enlace no contiene los datos necesarios para procesar la solicitud.';
} else {
    $sol = $db->prepare("
        SELECT s.id_solicitud, s.id_usuario, s.estado, u.nombre
        FROM SolicitudesRol s
        JOIN Usuarios u ON u.id_usuario = s.id_usuario
        WHERE s.token = ?
    ");
    $sol->execute([$token]);
    $sol = $sol->fetch();

    if (!$sol) {
        $titulo  = 'Enlace inválido';
        $icono   = 'error';
        $color   = 'var(--danger)';
        $detalle = 'No se encontró ninguna solicitud asociada a este enlace.';
    } elseif ($sol['estado'] !== 'Pendiente') {
        $titulo  = 'Solicitud ya procesada';
        $icono   = 'info';
        $color   = 'var(--warning)';
        $detalle = "La solicitud de <strong>" . e($sol['nombre']) . "</strong> ya fue " . strtolower(e($sol['estado'])) . " anteriormente. Este enlace ya no está activo.";
    } else {
        // Marcar el token como usado de forma atómica para evitar doble procesamiento
        $respuesta = $accion === 'aprobar' ? 'Solicitud aprobada vía correo electrónico.' : 'Solicitud rechazada vía correo electrónico.';
        $nuevoEstado = $accion === 'aprobar' ? 'Aprobada' : 'Rechazada';

        $upd = $db->prepare("
            UPDATE SolicitudesRol
            SET estado = ?, respuesta = ?, fecha_respuesta = NOW(), token_usado = 1
            WHERE token = ? AND token_usado = 0 AND estado = 'Pendiente'
        ");
        $upd->execute([$nuevoEstado, $respuesta, $token]);

        if ($upd->rowCount() === 1) {
            if ($accion === 'aprobar') {
                $db->prepare("UPDATE Usuarios SET rol='admin' WHERE id_usuario=?")->execute([$sol['id_usuario']]);
                $titulo  = 'Solicitud aprobada';
                $icono   = 'check_circle';
                $color   = 'var(--success)';
                $detalle = "Se otorgó el rol de Administrador a <strong>" . e($sol['nombre']) . "</strong>.";
            } else {
                $titulo  = 'Solicitud rechazada';
                $icono   = 'cancel';
                $color   = 'var(--danger)';
                $detalle = "Se rechazó la solicitud de rol de <strong>" . e($sol['nombre']) . "</strong>.";
            }
        } else {
            // Otro proceso ya lo resolvió justo antes (doble clic, prefetch de correo, etc.)
            $titulo  = 'Solicitud ya procesada';
            $icono   = 'info';
            $color   = 'var(--warning)';
            $detalle = 'Esta solicitud ya fue procesada. Este enlace ya no está activo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo) ?> — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="/css/estilos.css?v=8">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-main)">
    <div class="card" style="max-width:460px;width:90%;padding:36px;text-align:center">
        <span class="material-symbols-outlined" style="font-size:56px;color:<?= $color ?>"><?= $icono ?></span>
        <h2 style="margin:16px 0 8px"><?= e($titulo) ?></h2>
        <p style="color:var(--text-secondary);line-height:1.6"><?= $detalle ?></p>
        <a href="/pages/admin_roles.php" class="btn btn-primary" style="margin-top:20px;display:inline-block">
            Ir a Gestión de Roles
        </a>
    </div>
</body>
</html>
