<?php
// =============================================
// API DE NOTIFICACIONES
// Archivo: actions/notificaciones.php
// GET  ?desde=ID[&lista=1] → no leídas, notificaciones nuevas (id > desde) y, con lista=1, las últimas 20
// POST accion=leer&id=N | accion=todas → marcar como leídas
// =============================================
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
$yo = (int)$_SESSION['usuario']['id'];
session_write_close();

try {
    notifAsegurarTabla();
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['accion'] ?? '') === 'todas') {
            $db->prepare("UPDATE Notificaciones SET leida=1 WHERE id_usuario=?")->execute([$yo]);
        } elseif (($_POST['accion'] ?? '') === 'leer') {
            $db->prepare("UPDATE Notificaciones SET leida=1 WHERE id_usuario=? AND id_notificacion=?")->execute([$yo, (int)($_POST['id'] ?? 0)]);
        }
    }

    $fila = function (array $n): array {
        return [
            'id'      => (int)$n['id_notificacion'],
            'titulo'  => $n['titulo'],
            'mensaje' => $n['mensaje'],
            'enlace'  => $n['enlace'],
            'icono'   => $n['icono'],
            'leida'   => (int)$n['leida'] === 1,
            'fecha'   => date('c', strtotime($n['fecha'])),
        ];
    };

    $st = $db->prepare("SELECT COUNT(*) FROM Notificaciones WHERE id_usuario=? AND leida=0");
    $st->execute([$yo]);
    $out = ['ok' => true, 'no_leidas' => (int)$st->fetchColumn()];

    $st = $db->prepare("SELECT COALESCE(MAX(id_notificacion),0) FROM Notificaciones WHERE id_usuario=?");
    $st->execute([$yo]);
    $out['max_id'] = (int)$st->fetchColumn();

    $desde = isset($_GET['desde']) ? (int)$_GET['desde'] : -1;
    $out['nuevas'] = [];
    if ($desde >= 0) {
        $st = $db->prepare("SELECT * FROM Notificaciones WHERE id_usuario=? AND id_notificacion>? ORDER BY id_notificacion LIMIT 5");
        $st->execute([$yo, $desde]);
        $out['nuevas'] = array_map($fila, $st->fetchAll());
    }
    if (!empty($_GET['lista']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $st = $db->prepare("SELECT * FROM Notificaciones WHERE id_usuario=? ORDER BY id_notificacion DESC LIMIT 20");
        $st->execute([$yo]);
        $out['lista'] = array_map($fila, $st->fetchAll());
    }
    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
