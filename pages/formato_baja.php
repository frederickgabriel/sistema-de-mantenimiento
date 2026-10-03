<?php
// =============================================
// FORMATO DE BAJAS — VISTA IMPRIMIBLE
// Archivo: pages/formato_baja.php?ids[]=1&ids[]=2&tipo=activos&fecha=...&depto=...&ubicacion=...
// Réplica en HTML + CSS de includes/plantillas/formato_bajas.xlsx, para imprimir o
// "Guardar como PDF" desde el navegador. La versión Excel sale de formato_baja_excel.php.
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

$total = array_sum(array_column($renglones, 'total'));
$qs = fbQuery($p);

// Formato contable de Excel: "$" a la izquierda, importe a la derecha, "-" si es cero
function fbContable(float $n): string {
    return '<span class="acct"><span>$</span><span>' . ($n ? number_format($n, 2) : '-&nbsp;&nbsp;&nbsp;') . '</span></span>';
}
$f = FB_FIRMAS;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png">
    <link rel="apple-touch-icon" href="/img/favicon/favicon-180.png">
    <link rel="shortcut icon" href="/img/favicon/favicon.ico">
    <title>Formato de Baja — <?= date('d/m/Y', strtotime($p['fecha'])) ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #74777a; }

        .no-print { background: #1a1a2e; color: #fff; padding: 12px 24px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; position: sticky; top: 0; z-index: 10; font-family: Arial, sans-serif; }
        .no-print button, .no-print .btn-xls { background: #5b21b6; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .no-print button:hover { background: #4c1d95; }
        .no-print .btn-xls { background: #1d6f42; }
        .no-print .btn-xls:hover { background: #155a35; }
        .no-print a.volver { color: #cbd5ff; font-size: 13px; text-decoration: none; }
        .no-print .folio-tag { margin-left: auto; color: #aab; font-size: 12px; font-family: monospace; }
        .mi-sm { font-size: 18px; }

        .scroll { overflow-x: auto; padding: 24px 0; }
        .hoja { width: 900px; margin: 0 auto; background: #fff; padding: 22px; }

        /* Hoja1!A2:F55 de la plantilla. La columna C se parte en dos (C1|C2) para la
           línea vertical que separa "VERIFICADO COSTOS" de "VALIDO" en las firmas. */
        table.fb { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9.3px; font-weight: 700; }
        table.fb td, table.fb th { padding: 0 2px; vertical-align: middle; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        table.fb th { white-space: normal; line-height: 1.1; }
        .g   { border: 1px solid #000; }
        .bt2 { border-top: 2px solid #000 !important; }
        .bb2 { border-bottom: 2px solid #000 !important; }
        .bl2 { border-left: 2px solid #000 !important; }
        .br2 { border-right: 2px solid #000 !important; }
        .nbt { border-top: none !important; }
        .nbb { border-bottom: none !important; }
        .c   { text-align: center; }
        .n   { font-weight: 400; }
        .wrap { white-space: normal !important; line-height: 1.25; }

        .logo { text-align: center; vertical-align: middle; }
        .logo img { height: 150px; }
        .empresa { font-size: 24px; text-align: center; }
        .caja { border: 2px solid #000; text-align: center; }
        .acct { display: flex; justify-content: space-between; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .scroll { padding: 0; overflow: visible; }
            .hoja { padding: 0; zoom: .8; }
            @page { size: letter portrait; margin: 8mm; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()"><span class="material-symbols-outlined mi-sm">print</span> Imprimir / Guardar como PDF</button>
        <a class="btn-xls" href="/pages/formato_baja_excel.php?<?= e($qs) ?>"><span class="material-symbols-outlined mi-sm">table_view</span> Descargar Excel</a>
        <a class="volver" href="/pages/bajas.php"><span class="material-symbols-outlined mi-sm" style="vertical-align:-4px">arrow_back</span> Volver a Bajas</a>
        <span class="folio-tag"><?= count($renglones) ?> equipo(s) · Total $<?= number_format($total, 2) ?></span>
    </div>

    <div class="scroll">
    <div class="hoja">
        <table class="fb">
            <colgroup>
                <col style="width:9.14%"><col style="width:7.00%">
                <col style="width:13.69%"><col style="width:23.32%">
                <col style="width:12.29%"><col style="width:14.00%"><col style="width:20.56%">
            </colgroup>

            <!-- Encabezado: razón social + logo -->
            <tr style="height:12px"><td colspan="5"></td><td colspan="2" rowspan="10" class="logo"><img src="/img/formato_bajas_logo.png" alt="<?= e(FB_EMPRESA[0]) ?>"></td></tr>
            <tr style="height:12px"><td colspan="5"></td></tr>
            <tr style="height:12px"><td colspan="5"></td></tr>
            <tr style="height:12px"><td colspan="5"></td></tr>
            <tr style="height:30px"><td colspan="5" class="empresa"><?= e(FB_EMPRESA[0]) ?></td></tr>
            <tr style="height:30px"><td colspan="5" class="empresa"><?= e(FB_EMPRESA[1]) ?></td></tr>
            <tr style="height:12px"><td colspan="5"></td></tr>

            <!-- Tipo de baja -->
            <tr style="height:19px">
                <td colspan="2" class="caja"><?= $p['tipo'] === 'activos' ? '( X )&nbsp; ' : '' ?>BAJA DE ACTIVOS</td>
                <td colspan="2" class="caja"><?= $p['tipo'] === 'operacion' ? '( X )&nbsp; ' : '' ?>BAJA DE EQUIPO OPERACIÓN</td>
                <td></td>
            </tr>
            <tr style="height:12px"><td colspan="4" class="c bt2">* MARCAR CON UNA "X" EL TIPO DE BAJA</td><td></td></tr>
            <tr style="height:19px"><td colspan="5"></td></tr>

            <!-- Fecha / Depto / Ubicación -->
            <tr style="height:22.5px">
                <td class="g bl2 bt2">FECHA</td>
                <td colspan="3" class="g bt2 c"><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                <td colspan="3" class="g bt2 br2 nbb c">FECHA VERIFICACION DE BAJAS</td>
            </tr>
            <tr style="height:22.5px">
                <td class="g bl2">DEPTO</td>
                <td colspan="3" class="g c"><?= e($p['depto']) ?></td>
                <td colspan="3" rowspan="2" class="g br2 bb2 nbt c"><?= $p['fecha_verificacion'] ? date('d/m/Y', strtotime($p['fecha_verificacion'])) : '' ?></td>
            </tr>
            <tr style="height:22.5px">
                <td class="g bl2 bb2">UBICACIÓN</td>
                <td colspan="3" class="g bb2 c"><?= e($p['ubicacion']) ?></td>
            </tr>
            <tr style="height:12px"><td colspan="7"></td></tr>

            <!-- Artículos -->
            <tr style="height:21.5px">
                <th class="g bt2 c">COD. ARTICULO</th>
                <th class="g bt2 c">CANTIDAD</th>
                <th colspan="2" class="g bt2 c">DESCRIPCION DEL ARTICULO</th>
                <th class="g bt2 c">COSTO UNITARIO</th>
                <th class="g bt2 c">TOTAL</th>
                <th class="g bt2 br2 c">OBSERVACIONES BAJA</th>
            </tr>
            <?php foreach ($renglones as $r): ?>
            <tr style="height:24px">
                <td class="g n" title="<?= e($r['codigo'] ?? '') ?>"><?= e($r['codigo'] ?? '') ?></td>
                <td class="g n c"><?= $r ? $r['cantidad'] : '' ?></td>
                <td colspan="2" class="g" title="<?= e($r['descripcion'] ?? '') ?>"><?= e($r['descripcion'] ?? '') ?></td>
                <td class="g n c"><?= ($r && $r['costo'] !== null) ? '$' . number_format($r['costo'], 2) : '' ?></td>
                <td class="g n"><?= fbContable($r['total'] ?? 0) ?></td>
                <td class="g n br2 c" title="<?= e($r['observaciones'] ?? '') ?>"><?= e($r['observaciones'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="height:24px">
                <td colspan="4" class="g bl2 bb2"></td>
                <td class="g bb2 c">TOTAL $</td>
                <td class="g bb2"><?= fbContable($total) ?></td>
                <td class="g br2"></td>
            </tr>

            <!-- Firmas -->
            <tr style="height:12.4px">
                <td colspan="2" class="g bl2 br2 c"><?= $f['solicita']['titulo'] ?></td>
                <td class="g c"><?= $f['costos']['titulo'] ?></td>
                <td class="g c"><?= $f['seguridad']['titulo'] ?></td>
                <td class="g c"><?= $f['finanzas']['titulo'] ?></td>
                <td class="g c"><?= $f['gerente']['titulo'] ?></td>
                <td rowspan="2" class="g br2 c wrap">SELLO DE RETORNO FORMATO COSTOS</td>
            </tr>
            <tr style="height:13.5px">
                <td colspan="2" class="g bl2 br2 c"><?= $f['solicita']['nombre'] ?></td>
                <td class="g c"><?= $f['costos']['nombre'] ?></td>
                <td class="g c"><?= $f['seguridad']['nombre'] ?></td>
                <td class="g c"><?= $f['finanzas']['nombre'] ?></td>
                <td class="g c"><?= $f['gerente']['nombre'] ?></td>
            </tr>
            <tr style="height:71px">
                <td colspan="2" class="g bl2 br2"></td>
                <td class="g"></td>
                <td class="g"></td>
                <td class="g"></td>
                <td class="g"></td>
                <td rowspan="2" class="g br2"></td>
            </tr>
            <tr style="height:20px">
                <td colspan="2" class="g bl2 br2 bb2 c"><?= $f['solicita']['cargo'] ?></td>
                <td class="g bb2 c" style="font-size:8.6px"><?= $f['costos']['cargo'] ?></td>
                <td class="g bb2 c"><?= $f['seguridad']['cargo'] ?></td>
                <td class="g bb2 c"><?= $f['finanzas']['cargo'] ?></td>
                <td class="g bb2 c"><?= $f['gerente']['cargo'] ?></td>
            </tr>

            <!-- Observaciones adicionales -->
            <tr style="height:21.5px">
                <td colspan="2" rowspan="6" class="g bl2 bt2 bb2 br2 c wrap">OBSERVACIONES ADICIONALES</td>
                <td colspan="5" class="g bt2 c" title="<?= e($p['observaciones']) ?>"><?= e(mb_strtoupper($p['observaciones'])) ?></td>
            </tr>
            <?php foreach (FB_NOTAS as $nota): ?>
            <tr style="height:21.5px"><td colspan="5" class="g c"><?= e($nota) ?></td></tr>
            <?php endforeach; ?>
            <tr style="height:21.5px"><td colspan="5" class="g bb2"></td></tr>
        </table>
    </div>
    </div>

</body>
</html>
