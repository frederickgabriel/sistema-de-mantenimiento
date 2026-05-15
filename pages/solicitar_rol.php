<?php
// =============================================
// ACCIÓN: Solicitar Rol de Admin
// Archivo: actions/solicitar_rol.php
// =============================================
require_once '../includes/config.php';
requireLogin();

// Si ya es admin, redirigir
if (esAdmin()) {
    header('Location: /pages/configuracion.php?tab=perfil');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /pages/configuracion.php?tab=rol');
    exit;
}

$justificacion = trim($_POST['justificacion'] ?? '');
$db = getDB();

if (!$justificacion) {
    header('Location: /pages/configuracion.php?tab=rol&err=' . urlencode('La justificación es obligatoria.'));
    exit;
}

// Verificar si ya tiene solicitud pendiente
$check = $db->prepare("SELECT id_solicitud FROM SolicitudesRol WHERE id_usuario=? AND estado='Pendiente'");
$check->execute([$_SESSION['usuario']['id']]);
if ($check->fetch()) {
    header('Location: /pages/configuracion.php?tab=rol&err=' . urlencode('Ya tienes una solicitud pendiente.'));
    exit;
}

// Guardar la solicitud
$stmt = $db->prepare("INSERT INTO SolicitudesRol (id_usuario, justificacion) VALUES (?,?)");
$stmt->execute([$_SESSION['usuario']['id'], $justificacion]);
$idSolicitud = (int)$db->lastInsertId();

// Obtener datos completos del usuario
$stUser = $db->prepare("SELECT nombre, cargo, correo FROM Usuarios WHERE id_usuario=?");
$stUser->execute([$_SESSION['usuario']['id']]);
$datosUser = $stUser->fetch();

// Enviar email al administrador
$emailEnviado = enviarEmailSolicitudRol(
    $datosUser['nombre'],
    $datosUser['cargo'],
    $datosUser['correo'],
    $justificacion,
    $idSolicitud
);

$msgOk = '✅ Solicitud enviada correctamente. El administrador la revisará pronto.';
if (!$emailEnviado) {
    // La solicitud se guardó aunque el email falle
    $msgOk = '✅ Solicitud registrada. (Nota: el correo de notificación no pudo enviarse, pero el admin verá tu solicitud en el panel.)';
}

header('Location: /pages/configuracion.php?tab=rol&msg=' . urlencode($msgOk));
exit;