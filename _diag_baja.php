<?php
// DIAGNÓSTICO TEMPORAL — súbelo, ábrelo una vez y BÓRRALO del host.
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

function ok($c) { return $c ? 'OK' : '*** FALTA / FALLA ***'; }

echo "PHP: " . PHP_VERSION . "\n";
echo "zip: " . ok(class_exists('ZipArchive')) . "\n";
echo "mbstring: " . ok(function_exists('mb_strtoupper')) . "\n\n";

echo "== Archivos (deben existir con este nombre exacto, respetando mayúsculas) ==\n";
foreach ([
    'includes/config.php',
    'includes/formato_bajas.php',
    'includes/plantillas/formato_bajas.xlsx',
    'img/formato_bajas_logo.png',
    'pages/formato_baja.php',
    'pages/formato_baja_excel.php',
    'pages/bajas.php',
] as $f) {
    echo str_pad($f, 42) . ok(is_file(__DIR__ . '/' . $f)) . "\n";
}

echo "\n== Base de datos ==\n";
try {
    require_once __DIR__ . '/includes/config.php';
    $db = getDB();
    echo "Conexión: OK\n";
    $cols = $db->query("SHOW COLUMNS FROM Equipos")->fetchAll(PDO::FETCH_COLUMN);
    echo "Equipos.numero_serie: " . ok(in_array('numero_serie', $cols)) . "\n";
    $cols = $db->query("SHOW COLUMNS FROM Bajas")->fetchAll(PDO::FETCH_COLUMN);
    echo "Bajas.motivo_baja / valor_actual_estimado: " . ok(in_array('motivo_baja', $cols) && in_array('valor_actual_estimado', $cols)) . "\n";
    $t = $db->query("SHOW COLUMNS FROM Bajas WHERE Field='motivo_baja'")->fetch();
    echo "Tipo de motivo_baja: {$t['Type']}  (debe ser varchar(255); si dice enum, falta ejecutar el script SQL)\n";
} catch (Throwable $e) {
    echo "ERROR BD: " . $e->getMessage() . "\n";
}

echo "\n== Prueba del formato ==\n";
try {
    require_once __DIR__ . '/includes/formato_bajas.php';
    echo "formato_bajas.php carga: OK\n";
    $id = (int)$db->query("SELECT id_baja FROM Bajas LIMIT 1")->fetchColumn();
    $r = fbRenglones($db, [$id]);
    echo "Consulta de renglones (baja #$id): " . count($r) . " renglón(es) OK\n";
    $p = ['ids' => [$id], 'tipo' => 'activos', 'fecha' => date('Y-m-d'), 'fecha_verificacion' => '', 'depto' => 'X', 'ubicacion' => 'X', 'observaciones' => ''];
    $x = fbGenerarXlsx($p, $r);
    echo "Generar Excel: OK (" . filesize($x) . " bytes)\n";
    unlink($x);
} catch (Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n  en " . basename($e->getFile()) . " línea " . $e->getLine() . "\n";
}
