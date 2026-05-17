<?php
// =============================================
// CONFIGURACIÓN DE BASE DE DATOS
// Archivo: includes/config.php
// =============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');  // <-- Cambia esto
define('DB_NAME', 'sistema_mantenimiento');
define('SITE_NAME', 'Gestión de Mantenimiento');
date_default_timezone_set('America/Mexico_City');
 
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;color:#f85149;background:#0d1117">Error de conexión a la base de datos: ' . $e->getMessage() . '</div>');
        }
    }
    return $pdo;
}
 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
function requireLogin(): void {
    if (!isset($_SESSION['usuario'])) {
        header('Location: /index.php');
        exit;
    }
}
 
function redirectIfLoggedIn(): void {
    if (isset($_SESSION['usuario'])) {
        header('Location: /pages/dashboard.php');
        exit;
    }
}
 
function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}
 
function fechaES(?string $fecha): string {
    if (!$fecha) return '—';
    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ts = strtotime($fecha);
    return date('d', $ts) . ' ' . $meses[(int)date('m', $ts) - 1] . ' ' . date('Y', $ts);
}
 
function badgeEstado(string $estado): string {
    $map = ['Activo' => 'badge-activo', 'Inactivo' => 'badge-inactivo', 'En Reparacion' => 'badge-reparacion', 'Baja' => 'badge-no-realizado'];
    $css = $map[$estado] ?? 'badge-inactivo';
    return "<span class=\"badge-estado {$css}\">" . e($estado) . "</span>";
}
 
function badgeTarea(string $estado): string {
    $map   = ['Pendiente' => 'badge-pendiente', 'En Proceso' => 'badge-proceso', 'Realizado' => 'badge-realizado', 'No Realizado' => 'badge-no-realizado'];
    $icons = ['Pendiente' => '⏳', 'En Proceso' => '🔄', 'Realizado' => '✅', 'No Realizado' => '❌'];
    $css   = $map[$estado]   ?? 'badge-pendiente';
    $ico   = $icons[$estado] ?? '';
    return "<span class=\"badge-estado {$css}\">{$ico} " . e($estado) . "</span>";
}
 
// =============================================
// CONFIGURACIÓN DE ROLES
// =============================================
define('ADMIN_EMAIL', 'frederickaguilar317@gmail.com');
 
// Verificar si el usuario actual es admin
function esAdmin(): bool {
    return ($_SESSION['usuario']['rol'] ?? 'usuario') === 'admin';
}
 
// Redirigir si no es admin
function requireAdmin(): void {
    requireLogin();
    if (!esAdmin()) {
        header('Location: /pages/dashboard.php?err=sin_permiso');
        exit;
    }
}
 
// Badge de rol
function badgeRol(string $rol): string {
    if ($rol === 'admin') {
        return '<span class="badge-estado" style="background:rgba(163,113,247,.15);color:var(--purple);border:1px solid rgba(163,113,247,.3)">👑 Admin</span>';
    }
    return '<span class="badge-estado badge-inactivo">👤 Usuario</span>';
}
 
// Enviar email de solicitud de rol (usa mail() nativo de PHP)
function enviarEmailSolicitudRol(string $nombreUsuario, string $cargoUsuario, string $correoUsuario, string $justificacion, int $idSolicitud): bool {
    $para    = ADMIN_EMAIL;
    $asunto  = '=?UTF-8?B?' . base64_encode('[ManteTech] Solicitud de rol Admin — ' . $nombreUsuario) . '?=';
    $urlPanel = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST'] . '/pages/admin_roles.php';
 
    $cuerpo = "Hola Administrador,
 
"
            . "El siguiente usuario ha solicitado el rol de Administrador en el sistema ManteTech:
 
"
            . "  Nombre:       {$nombreUsuario}
"
            . "  Cargo:        {$cargoUsuario}
"
            . "  Correo:       {$correoUsuario}
"
            . "  ID Solicitud: #{$idSolicitud}
 
"
            . "Justificación:
"
            . "  {$justificacion}
 
"
            . "Para aprobar o rechazar la solicitud, ingresa al panel de administración:
"
            . "  {$urlPanel}
 
"
            . "— Sistema ManteTech";
 
    $headers  = "From: noreply@mantetech.local
";
    $headers .= "Reply-To: {$correoUsuario}
";
    $headers .= "X-Mailer: PHP/" . phpversion() . "
";
    $headers .= "Content-Type: text/plain; charset=UTF-8
";
 
    return mail($para, $asunto, $cuerpo, $headers);
}
 