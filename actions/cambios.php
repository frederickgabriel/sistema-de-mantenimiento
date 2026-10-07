<?php
// =============================================
// TIEMPO REAL (sondeo ligero)
// Archivo: actions/cambios.php
// Devuelve una "huella" del estado de la base de datos. js/ui.js la consulta cada pocos
// segundos y, si cambió (otro usuario guardó, subió o aprobó algo), refresca la zona de la
// página actual sin recargarla. También avisa si cambió el rol/estado del usuario actual.
// =============================================
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
$idUsuario = (int)$_SESSION['usuario']['id'];
session_write_close(); // no bloquear otras peticiones de la misma sesión mientras se consulta

$db = getDB();
$tablas = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
             ->fetchAll(PDO::FETCH_COLUMN);
$huella = '';
if ($tablas) {
    $lista = implode(',', array_map(fn($t) => '`' . str_replace('`', '', $t) . '`', $tablas));
    foreach ($db->query("CHECKSUM TABLE $lista")->fetchAll(PDO::FETCH_NUM) as $fila) {
        $huella .= $fila[0] . ':' . $fila[1] . ';';
    }
}

$st = $db->prepare("SELECT rol, activo FROM Usuarios WHERE id_usuario = ?");
$st->execute([$idUsuario]);
$u = $st->fetch();

echo json_encode([
    'ok'     => true,
    'v'      => md5($huella),
    'sesion' => $u ? ($u['rol'] . '|' . (int)$u['activo']) : 'x',
]);
