<?php
// =============================================
// DICTAMEN PDF DE BAJA DE EQUIPO
// Archivo: pages/bajas.php?pdf=ID
// Se activa cuando $_GET['pdf'] está presente
// =============================================
require_once '../includes/config.php';
requireLogin();

$id = (int)($_GET['pdf'] ?? 0);
if (!$id) {
    header('Location: /pages/bajas.php');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT b.*, 
           e.modelo, e.marca, e.procesador, e.ram, e.disco, e.numero_inventario, e.fecha_registro as fecha_registro_equipo,
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
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Dictamen de Baja — <?= $folio ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;600;700&family=Space+Mono:wght@400;700&display=swap');

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            color: #1a1a2e;
            background: #fff;
            font-size: 13px;
            line-height: 1.5;
        }

        /* ---- Barra de acciones (no imprimible) ---- */
        .no-print {
            background: #1a1a2e;
            color: #e6edf3;
            padding: 12px 40px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .no-print button {
            background: #004085;
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            font-weight: 600;
        }

        .no-print button:hover {
            background: #0056b3;
        }

        .no-print a {
            color: #79c0ff;
            font-size: 14px;
            text-decoration: none;
        }

        /* ---- Documento ---- */
        .documento {
            max-width: 780px;
            margin: 0 auto;
            padding: 40px;
        }

        /* Header institucional */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid #004085;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }

        .inst-nombre {
            font-size: 18px;
            font-weight: 700;
            color: #004085;
        }

        .inst-sub {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        .doc-folio {
            text-align: right;
        }

        .folio-num {
            font-family: 'Space Mono', monospace;
            font-size: 18px;
            font-weight: 700;
            color: #004085;
        }

        .folio-fecha {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        /* Título del dictamen */
        .doc-titulo {
            text-align: center;
            margin-bottom: 28px;
        }

        .doc-titulo h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .doc-titulo p {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }

        /* Secciones */
        .seccion {
            margin-bottom: 22px;
        }

        .seccion-titulo {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #004085;
            border-bottom: 1px solid #004085;
            padding-bottom: 4px;
            margin-bottom: 12px;
        }

        /* Grid de datos */
        .datos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
        }


        .dato-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #888;
            margin-bottom: 2px;
        }

        .dato-valor {
            font-size: 13px;
            color: #1a1a2e;
            font-weight: 500;
        }

        /* Alerta de baja */
        .alerta-baja {
            background: #fff0f0;
            border: 2px solid #da3633;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .alerta-baja-icon {
            font-size: 28px;
        }

        .alerta-baja-titulo {
            font-size: 15px;
            font-weight: 700;
            color: #da3633;
        }

        .alerta-baja-motivo {
            font-size: 13px;
            color: #555;
            margin-top: 3px;
        }

        /* Bloques de texto diagnóstico */
        .texto-block {
            background: #f8f9ff;
            border-left: 3px solid #004085;
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            font-size: 13px;
            color: #333;
            line-height: 1.7;
            white-space: pre-line;
            margin-bottom: 12px;
        }

        .texto-block.danger {
            border-color: #da3633;
            background: #fff8f8;
        }

        .texto-block.warning {
            border-color: #e3a008;
            background: #fffbf0;
        }

        /* Validación */
        .validacion-box {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 16px 20px;
        }

        .validacion-box.validado {
            border-color: #2ea043;
            background: #f0fff4;
        }

        .validacion-box.rechazado {
            border-color: #da3633;
            background: #fff0f0;
        }

        .validacion-box.pendiente {
            border-color: #e3a008;
            background: #fffbf0;
        }

        .validacion-estado {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .validacion-estado.validado {
            color: #2ea043;
        }

        .validacion-estado.rechazado {
            color: #da3633;
        }

        .validacion-estado.pendiente {
            color: #e3a008;
        }

        /* Firmas */
        .firmas-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 40px;
        }

        .firma-box {
            text-align: center;
            padding-top: 50px;
            border-top: 1px solid #333;
        }

        .firma-nombre {
            font-size: 12px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .firma-cargo {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }

        /* Footer */
        .doc-footer {
            border-top: 1px solid #ddd;
            padding-top: 12px;
            margin-top: 32px;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #aaa;
        }

        /* Estampilla de validación */
        .estampilla {
            position: relative;
            display: inline-block;
        }

        .sello {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px double;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 12px;
            transform: rotate(-15deg);
            float: right;
            margin-top: -20px;
        }

        .sello.validado {
            border-color: #2ea043;
            color: #2ea043;
        }

        .sello.rechazado {
            border-color: #da3633;
            color: #da3633;
        }

        .sello.pendiente {
            border-color: #e3a008;
            color: #e3a008;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                font-size: 12px;
            }

            .documento {
                padding: 20px;
            }

            @page {
                margin: 1.5cm;
            }
        }
    </style>
</head>

<body>

    <!-- Barra de acciones (no se imprime) -->
    <div class="no-print">
        <button onclick="window.print()">🖨 Imprimir / Guardar como PDF</button>
        <a href="/pages/bajas.php">← Volver a Bajas</a>
        <span style="color:#8b949e;font-size:13px">Usa Ctrl+P → Guardar como PDF · Folio: <?= $folio ?></span>
    </div>

    <div class="documento">

        <!-- Header institucional -->
        <div class="doc-header">
            <div>
                <div class="inst-nombre">🖥 <?= SITE_NAME ?></div>
                <div class="inst-sub">Sistema Institucional de Gestión de Equipos de Cómputo</div>
                <?php if ($b['nombre_area']): ?>
                    <div class="inst-sub" style="margin-top:2px">Área: <?= e($b['nombre_area']) ?><?= $b['ubicacion'] ? " — {$b['ubicacion']}" : '' ?></div>
                <?php endif; ?>
            </div>
            <div class="doc-folio">
                <div class="folio-num"><?= $folio ?></div>
                <div class="folio-fecha">Fecha de emisión: <?= fechaES(date('Y-m-d')) ?></div>
                <div class="folio-fecha">Fecha de baja: <?= fechaES($b['fecha_baja']) ?></div>
            </div>
        </div>

        <!-- Título -->
        <div class="doc-titulo">
            <h1>Dictamen de Baja de Equipo de Cómputo</h1>
            <p>Documento oficial para la baja definitiva del inventario institucional</p>
        </div>

        <!-- Alerta motivo de baja -->
        <div class="alerta-baja">
            <div class="alerta-baja-icon">📛</div>
            <div>
                <div class="alerta-baja-titulo">BAJA POR: <?= strtoupper(e($b['motivo_baja'])) ?></div>
                <div class="alerta-baja-motivo">Recomendación final: <strong><?= e($b['recomendacion']) ?></strong></div>
            </div>
            <!-- Sello de validación -->
            <div style="margin-left:auto">
                <div class="sello <?= strtolower($b['estado_validacion']) ?>">
                    <?= $b['estado_validacion'] === 'Validado' ? "✓\nValidado\nInstitucionalmente" : ($b['estado_validacion'] === 'Rechazado' ? "✗\nRechazado" : "⏳\nPendiente\nValidación") ?>
                </div>
            </div>
        </div>

        <!-- Datos del equipo -->
        <div class="seccion">
            <div class="seccion-titulo">I. Identificación del Equipo</div>
            <div class="datos-grid">
                <div class="dato-item">
                    <div class="dato-label">No. de Inventario</div>
                    <div class="dato-valor" style="font-family:'Space Mono',monospace;color:#004085;font-size:15px"><?= e($b['numero_inventario']) ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Modelo</div>
                    <div class="dato-valor"><?= e($b['modelo']) ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Marca / Fabricante</div>
                    <div class="dato-valor"><?= e($b['marca'] ?? '—') ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Área / Salón</div>
                    <div class="dato-valor"><?= e($b['nombre_area'] ?? '—') ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Procesador</div>
                    <div class="dato-valor"><?= e($b['procesador'] ?? '—') ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">RAM</div>
                    <div class="dato-valor"><?= e($b['ram'] ?? '—') ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Disco / Almacenamiento</div>
                    <div class="dato-valor"><?= e($b['disco'] ?? '—') ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Fecha de registro en sistema</div>
                    <div class="dato-valor"><?= fechaES($b['fecha_registro_equipo']) ?></div>
                </div>
                <div class="dato-item">
                    <div class="dato-label">Total mantenimientos realizados</div>
                    <div class="dato-valor"><?= $totalMttos ?></div>
                </div>
                <?php if ($ultimoMtto): ?>
                    <div class="dato-item">
                        <div class="dato-label">Último mantenimiento</div>
                        <div class="dato-valor"><?= fechaES($ultimoMtto['fecha_realizacion']) ?> — <?= e($ultimoMtto['tipo_mantenimiento']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Descripción de la falla -->
        <div class="seccion">
            <div class="seccion-titulo">II. Descripción de la Falla o Problema</div>
            <div class="texto-block danger"><?= nl2br(e($b['descripcion_falla'])) ?></div>
        </div>

        <!-- Diagnóstico técnico -->
        <div class="seccion">
            <div class="seccion-titulo">III. Diagnóstico Técnico</div>
            <div class="texto-block"><?= nl2br(e($b['diagnostico_tecnico'])) ?></div>
        </div>

        <!-- Intentos de reparación -->
        <?php if ($b['intentos_reparacion']): ?>
            <div class="seccion">
                <div class="seccion-titulo">IV. Intentos de Reparación Previos</div>
                <div class="texto-block warning"><?= nl2br(e($b['intentos_reparacion'])) ?></div>
            </div>
        <?php endif; ?>

        <!-- Análisis económico -->
        <?php if ($b['costo_reparacion_estimado'] || $b['valor_actual_estimado']): ?>
            <div class="seccion">
                <div class="seccion-titulo">V. Análisis Económico</div>
                <div class="datos-grid">
                    <?php if ($b['costo_reparacion_estimado']): ?>
                        <div class="dato-item">
                            <div class="dato-label">Costo estimado de reparación</div>
                            <div class="dato-valor" style="color:#da3633;font-size:15px;font-weight:700">$<?= number_format($b['costo_reparacion_estimado'], 2) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($b['valor_actual_estimado']): ?>
                        <div class="dato-item">
                            <div class="dato-label">Valor actual estimado del equipo</div>
                            <div class="dato-valor" style="font-size:15px;font-weight:700">$<?= number_format($b['valor_actual_estimado'], 2) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($b['costo_reparacion_estimado'] && $b['valor_actual_estimado']): ?>
                        <div class="dato-item" style="grid-column:1/-1">
                            <div class="dato-label">Conclusión económica</div>
                            <div class="dato-valor">
                                <?php if ($b['costo_reparacion_estimado'] >= $b['valor_actual_estimado']): ?>
                                    ⚠ El costo de reparación supera o iguala el valor actual del equipo, lo que hace inviable económicamente su reparación.
                                <?php else: ?>
                                    El costo de reparación es inferior al valor del equipo. La baja se justifica por razones técnicas o de obsolescencia.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Validación institucional -->
        <div class="seccion">
            <div class="seccion-titulo">VI. Estado de Validación Institucional</div>
            <div class="validacion-box <?= strtolower($b['estado_validacion']) ?>">
                <div class="validacion-estado <?= strtolower($b['estado_validacion']) ?>">
                    <?= $b['estado_validacion'] === 'Validado' ? '✅ BAJA VALIDADA' : ($b['estado_validacion'] === 'Rechazado' ? '❌ BAJA RECHAZADA' : '⏳ PENDIENTE DE VALIDACIÓN') ?>
                </div>
                <?php if ($b['estado_validacion'] !== 'Pendiente' && $b['fecha_validacion']): ?>
                    <div style="font-size:12px;color:#555;margin-bottom:8px">
                        Fecha de validación: <?= fechaES(date('Y-m-d', strtotime($b['fecha_validacion']))) ?>
                    </div>
                <?php endif; ?>
                <?php if ($b['observaciones_validacion']): ?>
                    <div style="font-size:13px;color:#333;background:rgba(255,255,255,.6);padding:8px 12px;border-radius:4px">
                        <?= nl2br(e($b['observaciones_validacion'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Firmas -->
        <div class="firmas-grid">
            <div class="firma-box">
                <div class="firma-nombre"><?= e($b['tecnico_nombre'] ?? '___________________') ?></div>
                <div class="firma-cargo"><?= e($b['tecnico_cargo'] ?? 'Técnico Responsable') ?></div>
            </div>
            <div class="firma-box">
                <div class="firma-nombre"><?= $b['nombre_autoriza'] ? e($b['nombre_autoriza']) : '___________________' ?></div>
                <div class="firma-cargo"><?= $b['cargo_autoriza'] ? e($b['cargo_autoriza']) : 'Autoridad Institucional' ?></div>
            </div>
            <div class="firma-box">
                <div class="firma-nombre">___________________</div>
                <div class="firma-cargo">Vo.Bo. Dirección General</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="doc-footer">
            <span>Folio: <?= $folio ?> · <?= SITE_NAME ?></span>
            <span>Generado: <?= date('d/m/Y H:i') ?> por <?= e($_SESSION['usuario']['nombre']) ?></span>
            <span>Documento oficial — Válido con firmas autógrafas</span>
        </div>

    </div>

    <script>
        // Auto-imprimir si viene con parámetro autoprint
        const params = new URLSearchParams(window.location.search);
        if (params.get('autoprint') === '1') {
            window.addEventListener('load', () => setTimeout(() => window.print(), 500));
        }
    </script>

</body>

</html>