<?php
// =============================================
// CONFIGURACIÓN DE BASE DE DATOS
// Archivo: includes/config.php
// =============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '2004');  // <-- Cambia esto
define('DB_NAME', 'sistema_mantenimiento');
define('SITE_NAME', 'Gestión de Mantenimiento');

// Zona horaria
date_default_timezone_set('America/Mexico_City');

// Conexión PDO (más segura que mysqli)
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
            die(json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// Iniciar sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Función: verificar si el usuario está logueado
function requireLogin(): void {
    if (!isset($_SESSION['usuario'])) {
        header('Location: /index.php');
        exit;
    }
}

// Función: redirigir si ya está logueado
function redirectIfLoggedIn(): void {
    if (isset($_SESSION['usuario'])) {
        header('Location: /pages/dashboard.php');
        exit;
    }
}

// Función: escapar output HTML
function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

// Función: formatear fecha a español
function fechaES(?string $fecha): string {
    if (!$fecha) return '—';
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ts = strtotime($fecha);
    return date('d', $ts) . ' ' . $meses[(int)date('m', $ts) - 1] . ' ' . date('Y', $ts);
}

// Función: badge de estado del equipo
function badgeEstado(string $estado): string {
    $map = [
        'Activo'       => 'badge-activo',
        'Inactivo'     => 'badge-inactivo',
        'En Reparacion'=> 'badge-reparacion',
    ];
    $css = $map[$estado] ?? 'badge-inactivo';
    return "<span class=\"badge-estado {$css}\">" . e($estado) . "</span>";
}

// Función: badge de estado de tarea
function badgeTarea(string $estado): string {
    $map = [
        'Pendiente'    => 'badge-pendiente',
        'En Proceso'   => 'badge-proceso',
        'Realizado'    => 'badge-realizado',
        'No Realizado' => 'badge-no-realizado',
    ];
    $css = $map[$estado] ?? 'badge-pendiente';
    $icons = [
        'Pendiente'    => '⏳',
        'En Proceso'   => '🔄',
        'Realizado'    => '✅',
        'No Realizado' => '❌',
    ];
    $ico = $icons[$estado] ?? '';
    return "<span class=\"badge-estado {$css}\">{$ico} " . e($estado) . "</span>";
}
