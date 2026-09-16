<?php
// =============================================
// DICTAMEN PDF DE BAJA DE EQUIPO (individual)
// Archivo: pages/baja_pdf.php?pdf=ID
// HTML + CSS puro (impresión / "Guardar como PDF" del navegador) — mismo estilo
// que bajas_reporte_pdf.php, sin librerías de PDF ni Composer.
// =============================================
require_once '../includes/config.php';
requireLogin();
require_once '../includes/pdf/branding.php'; // solo lectura de marca (logo/nombre), sin Dompdf

$id = (int)($_GET['pdf'] ?? 0);
if (!$id) {
    header('Location: /pages/bajas.php');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT b.*,
           e.modelo, e.marca, e.numero_serie, e.procesador, e.ram, e.disco, e.numero_inventario, e.fecha_registro as fecha_registro_equipo,
           a.nombre_area, a.ubicacion,
           u.nombre as tecnico_nombre, u.cargo as tecnico_cargo, u.correo as tecnico_correo
    FROM Bajas b
    JOIN Equipos e ON e.numero_inventario = b.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = b.id_tecnico_responsable
    WHERE b.id_baja = ?
");
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    header('Location: /pages/bajas.php');
    exit;
}

// Total de mantenimientos que tuvo el equipo
$totalMttos = $db->prepare("SELECT COUNT(*) FROM Mantenimientos WHERE numero_inventario=?");
$totalMttos->execute([$b['numero_inventario']]);
$totalMttos = $totalMttos->fetchColumn();

// Último mantenimiento
$ultimoMtto = $db->prepare("SELECT * FROM Mantenimientos WHERE numero_inventario=? ORDER BY fecha_realizacion DESC LIMIT 1");
$ultimoMtto->execute([$b['numero_inventario']]);
$ultimoMtto = $ultimoMtto->fetch();

$folio = 'BAJA-' . str_pad($b['id_baja'], 5, '0', STR_PAD_LEFT);

// ---- Marca: logo del programa (respaldo al logo real si aún no se configuró uno) ----
$marca = getBranding();
$logoPath = brandingLogoPath();
$logoSrc = (!empty($marca['mostrar_logo']) && $logoPath && is_file($logoPath))
    ? '/uploads/marca/' . e($marca['logo'])
    : '/img/hytta.png';

$tonoVal = match ($b['estado_validacion']) { 'Validado' => 'ok', 'Rechazado' => 'bad', default => 'warn' };
$tituloVal = match ($b['estado_validacion']) { 'Validado' => 'BAJA VALIDADA', 'Rechazado' => 'BAJA RECHAZADA', default => 'PENDIENTE DE VALIDACIÓN' };
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
    <title>Dictamen de Baja — <?= $folio ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', Arial, sans-serif; color: #000; background: #74777a; font-size: 11px; }

        .no-print { background: #1a1a2e; color: #fff; padding: 12px 24px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; position: sticky; top: 0; z-index: 10; }
        .no-print button { background: #5b21b6; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; font-family: 'DM Sans', sans-serif; font-weight: 600; }
        .no-print button:hover { background: #4c1d95; }
        .no-print a { color: #cbd5ff; font-size: 13px; text-decoration: none; }
        .no-print .folio-tag { margin-left: auto; color: #aab; font-size: 12px; font-family: monospace; }

        .hoja { max-width: 800px; margin: 24px auto; background: #fff; padding: 18px; border: 1px solid #000; }

        table.fmt { width: 100%; border-collapse: collapse; }
        table.fmt td, table.fmt th { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }

        .fmt-logo-cell { width: 22%; text-align: center; padding: 6px; }
        .fmt-logo-cell img { max-width: 100%; max-height: 62px; }
        .fmt-title-cell { width: 56%; text-align: center; font-weight: 700; font-size: 13px; line-height: 1.35; padding: 8px; }
        .fmt-code-cell { width: 22%; padding: 0; }
        .fmt-code-cell table { width: 100%; border-collapse: collapse; }
        .fmt-code-cell td { border: 1px solid #000; font-size: 9px; padding: 3px 5px; }
        .fmt-code-cell .lbl { color: #333; }
        .fmt-code-cell .val { font-weight: 700; text-align: right; }

        .fmt-fecha td { border: 1px solid #000; text-align: center; font-weight: 700; font-size: 12px; padding: 5px; }
        .fmt-fecha .fmt-fecha-label { background: #d9d9d9; font-weight: 700; text-align: left; font-size: 10px; }
        .fmt-fecha .fmt-fecha-sub { background: #f2f2f2; font-weight: 400; font-size: 8px; padding: 2px; }

        .fmt-section-title { background: #bfbfbf; text-align: center; font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em; padding: 5px; }
        .fmt-dato-label { width: 32%; font-weight: 400; font-size: 10px; background: #f7f7f7; }
        .fmt-dato-valor { font-weight: 700; text-align: center; font-size: 11px; }

        /* Caja de motivo de baja (destacada) */
        .fmt-motivo { border: 2px solid #b00020; border-top: none; padding: 8px 10px; }
        .fmt-motivo .titulo { color: #b00020; font-weight: 700; font-size: 13px; text-transform: uppercase; }
        .fmt-motivo .sub { font-size: 10.5px; margin-top: 3px; }

        /* Bloques de texto largo */
        .fmt-texto { border: 1px solid #000; border-top: none; padding: 8px 10px; font-size: 10.5px; line-height: 1.55; min-height: 22px; }

        /* Estado de validación */
        .fmt-validacion { border: 2px solid #000; border-top: none; padding: 8px 10px; }
        .fmt-validacion .titulo { font-weight: 700; font-size: 12.5px; }
        .fmt-validacion .sub { font-size: 10px; margin-top: 4px; color: #333; }
        .fmt-validacion.ok   { border-color: #1a7f37; } .fmt-validacion.ok .titulo   { color: #1a7f37; }
        .fmt-validacion.bad  { border-color: #b00020; } .fmt-validacion.bad .titulo  { color: #b00020; }
        .fmt-validacion.warn { border-color: #9a6700; } .fmt-validacion.warn .titulo { color: #9a6700; }

        table.fmt-firmas { width: 100%; border-collapse: collapse; margin-top: 30px; }
        table.fmt-firmas td { border: none; width: 33.33%; text-align: center; padding: 0 14px; font-size: 10px; font-weight: 700; }
        .fmt-firma-linea { border-top: 1px solid #000; margin: 0 6px 4px; }
        .fmt-firma-cargo { font-weight: 400; font-size: 9px; color: #333; margin-top: 2px; }

        .fmt-nota { font-size: 8.5px; color: #333; margin-top: 14px; text-align: center; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .hoja { margin: 0; border: none; max-width: none; }
            @page { size: letter portrait; margin: 1cm; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">print</span> Imprimir / Guardar como PDF</button>
        <a href="/pages/bajas.php"><span class="material-symbols-outlined mi-sm" style="vertical-align:-3px">arrow_back</span> Volver a Bajas</a>
        <span class="folio-tag">Folio: <?= $folio ?></span>
    </div>

    <div class="hoja">

        <table class="fmt">
            <tr>
                <td class="fmt-logo-cell"><img src="<?= $logoSrc ?>" alt="Logo"></td>
                <td class="fmt-title-cell">DICTAMEN DE BAJA<br>DE EQUIPO DE CÓMPUTO</td>
                <td class="fmt-code-cell">
                    <table>
                        <tr><td class="lbl">Folio:</td><td class="val"><?= e($folio) ?></td></tr>
                        <tr><td class="lbl">Emisión:</td><td class="val"><?= date('d/m/Y') ?></td></tr>
                        <tr><td class="lbl">Baja:</td><td class="val"><?= fechaES($b['fecha_baja']) ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="fmt fmt-fecha" style="margin-top:-1px">
            <tr>
                <td class="fmt-fecha-label" rowspan="2" style="width:14%">FECHA:</td>
                <td style="width:28.6%"><?= date('d') ?></td>
                <td style="width:28.6%"><?= date('m') ?></td>
                <td style="width:28.6%"><?= date('Y') ?></td>
            </tr>
            <tr>
                <td class="fmt-fecha-sub">DÍA</td>
                <td class="fmt-fecha-sub">MES</td>
                <td class="fmt-fecha-sub">AÑO</td>
            </tr>
        </table>

        <table class="fmt" style="margin-top:-1px">
            <tr><td class="fmt-section-title">MOTIVO DE LA BAJA</td></tr>
        </table>
        <div class="fmt-motivo">
            <table style="width:100%;border:none"><tr>
                <td style="border:none;padding:0">
                    <div class="titulo">Baja por: <?= e($b['motivo_baja']) ?></div>
                    <div class="sub">Recomendación final: <strong><?= e($b['recomendacion']) ?></strong></div>
                </td>
                <?php if ($b['valor_actual_estimado']): ?>
                <td style="border:none;padding:0;text-align:right;white-space:nowrap;vertical-align:middle">
                    <div style="font-size:8.5px;color:#333;text-transform:uppercase;letter-spacing:.03em">Valor Estimado</div>
                    <div style="font-size:16px;font-weight:700">$<?= number_format($b['valor_actual_estimado'], 2) ?></div>
                </td>
                <?php endif; ?>
            </tr></table>
        </div>

        <table class="fmt" style="margin-top:14px">
            <tr><td colspan="2" class="fmt-section-title">I. IDENTIFICACIÓN DEL EQUIPO</td></tr>
            <tr><td class="fmt-dato-label">NO. DE INVENTARIO</td><td class="fmt-dato-valor"><?= e($b['numero_inventario']) ?></td></tr>
            <tr><td class="fmt-dato-label">NO. DE SERIE</td><td class="fmt-dato-valor"><?= e($b['numero_serie'] ?: 'Sin Serie') ?></td></tr>
            <tr><td class="fmt-dato-label">MARCA / MODELO</td><td class="fmt-dato-valor"><?= e($b['marca'] ?? '—') ?> — <?= e($b['modelo']) ?></td></tr>
            <tr><td class="fmt-dato-label">ÁREA / SALÓN</td><td class="fmt-dato-valor"><?= e($b['nombre_area'] ?? '—') ?><?= $b['ubicacion'] ? ' — ' . e($b['ubicacion']) : '' ?></td></tr>
            <tr><td class="fmt-dato-label">PROCESADOR / RAM / DISCO</td><td class="fmt-dato-valor"><?= e($b['procesador'] ?? '—') ?> · <?= e($b['ram'] ?? '—') ?> · <?= e($b['disco'] ?? '—') ?></td></tr>
            <tr><td class="fmt-dato-label">FECHA DE REGISTRO EN SISTEMA</td><td class="fmt-dato-valor"><?= fechaES($b['fecha_registro_equipo']) ?></td></tr>
            <tr><td class="fmt-dato-label">TOTAL MANTENIMIENTOS REALIZADOS</td><td class="fmt-dato-valor"><?= $totalMttos ?><?= $ultimoMtto ? ' (último: ' . fechaES($ultimoMtto['fecha_realizacion']) . ' — ' . e($ultimoMtto['tipo_mantenimiento']) . ')' : '' ?></td></tr>
        </table>

        <table class="fmt" style="margin-top:14px">
            <tr><td class="fmt-section-title">II. DESCRIPCIÓN DE LA FALLA O PROBLEMA</td></tr>
        </table>
        <div class="fmt-texto"><?= nl2br(e($b['descripcion_falla'])) ?></div>

        <table class="fmt" style="margin-top:14px">
            <tr><td class="fmt-section-title">III. DIAGNÓSTICO TÉCNICO</td></tr>
        </table>
        <div class="fmt-texto"><?= nl2br(e($b['diagnostico_tecnico'])) ?></div>

        <table class="fmt" style="margin-top:14px">
            <tr><td class="fmt-section-title">IV. ESTADO DE VALIDACIÓN INSTITUCIONAL</td></tr>
        </table>
        <div class="fmt-validacion <?= $tonoVal ?>">
            <div class="titulo"><?= e($tituloVal) ?></div>
            <?php if ($b['estado_validacion'] !== 'Pendiente' && $b['fecha_validacion']): ?>
            <div class="sub">Fecha de validación: <?= fechaES(date('Y-m-d', strtotime($b['fecha_validacion']))) ?></div>
            <?php endif; ?>
            <?php if ($b['observaciones_validacion']): ?>
            <div class="sub"><?= nl2br(e($b['observaciones_validacion'])) ?></div>
            <?php endif; ?>
        </div>

        <table class="fmt-firmas">
            <tr>
                <td style="width:50%">
                    <div class="fmt-firma-linea"></div>
                    <?= e($b['tecnico_nombre'] ?? $_SESSION['usuario']['nombre']) ?>
                    <div class="fmt-firma-cargo">Generó el Reporte<?= $b['tecnico_cargo'] ? ' — ' . e($b['tecnico_cargo']) : '' ?></div>
                </td>
                <td style="width:50%">
                    <div class="fmt-firma-linea"></div>
                    ___________________
                    <div class="fmt-firma-cargo">Vo.Bo. Dirección General</div>
                </td>
            </tr>
        </table>

        <p class="fmt-nota">Folio: <?= e($folio) ?> · <?= e($marca['nombre_empresa'] ?? SITE_NAME) ?> · Generado: <?= date('d/m/Y H:i') ?> por <?= e($_SESSION['usuario']['nombre']) ?> · Documento oficial — válido con firmas autógrafas.</p>

    </div>

</body>
</html>
