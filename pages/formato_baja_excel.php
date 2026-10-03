<?php
// =============================================
// FORMATO DE BAJAS — DESCARGA EN EXCEL (.xlsx)
// Archivo: pages/formato_baja_excel.php?ids[]=1&ids[]=2&tipo=activos&fecha=...&depto=...&ubicacion=...
// Rellena la plantilla includes/plantillas/formato_bajas.xlsx (idéntica al formato en papel).
// =============================================
require_once '../includes/config.php';
requireLogin();
require_once '../includes/formato_bajas.php';

$p = fbParametros();
$renglones = fbRenglones(getDB(), $p['ids']);

if (!$renglones) {
    flash('msg', '❌ Selecciona al menos una baja para generar el formato.');
    header('Location: /pages/bajas.php');
    exit;
}

try {
    $archivo = fbGenerarXlsx($p, $renglones);
} catch (RuntimeException $e) {
    flash('msg', '❌ ' . $e->getMessage());
    header('Location: /pages/bajas.php');
    exit;
}

$nombre = 'Formato_Baja_' . date('Ymd', strtotime($p['fecha'])) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($archivo));
header('Cache-Control: no-store');
readfile($archivo);
unlink($archivo);
