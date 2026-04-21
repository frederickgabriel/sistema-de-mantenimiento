<?php
// =============================================
// BAJAS DE EQUIPOS
// Archivo: pages/bajas.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db  = getDB();
$msg = '';

// ==========================================
// Procesar POST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Registrar Baja ---
    if ($action === 'registrar_baja') {
        $inv            = trim($_POST['numero_inventario'] ?? '');
        $motivo         = $_POST['motivo_baja'] ?? '';
        $desc_falla     = trim($_POST['descripcion_falla'] ?? '');
        $diagnostico    = trim($_POST['diagnostico_tecnico'] ?? '');
        $intentos       = trim($_POST['intentos_reparacion'] ?? '');
        $costo_rep      = $_POST['costo_reparacion_estimado'] ?: null;
        $valor_actual   = $_POST['valor_actual_estimado'] ?: null;
        $recomendacion  = $_POST['recomendacion'] ?? 'Destrucción';
        $nombre_autoriza= trim($_POST['nombre_autoriza'] ?? '');
        $cargo_autoriza = trim($_POST['cargo_autoriza'] ?? '');
        $fecha_baja     = $_POST['fecha_baja'] ?? date('Y-m-d');

        if ($inv && $motivo && $desc_falla && $diagnostico) {
            try {
                // Insertar baja
                $db->prepare("
                    INSERT INTO Bajas (numero_inventario, motivo_baja, descripcion_falla, diagnostico_tecnico,
                        intentos_reparacion, costo_reparacion_estimado, valor_actual_estimado, recomendacion,
                        id_tecnico_responsable, nombre_autoriza, cargo_autoriza, fecha_baja)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $inv, $motivo, $desc_falla, $diagnostico,
                    $intentos ?: null, $costo_rep, $valor_actual, $recomendacion,
                    $_SESSION['usuario']['id'], $nombre_autoriza, $cargo_autoriza, $fecha_baja
                ]);

                // Cambiar estado del equipo a 'Baja'
                $db->prepare("UPDATE Equipos SET estado='Baja' WHERE numero_inventario=?")
                   ->execute([$inv]);

                $idBaja = $db->lastInsertId();
                header("Location: /pages/bajas.php?msg=" . urlencode("✅ Baja registrada correctamente.") . "&ver_pdf={$idBaja}");
                exit;
            } catch (PDOException $e) {
                $msg = "❌ Error al registrar la baja: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Completa todos los campos obligatorios.";
        }
    }

    // --- Validar / Rechazar baja ---
    elseif ($action === 'validar_baja') {
        $id      = (int)$_POST['id_baja'];
        $estado  = $_POST['estado_validacion'] ?? 'Validado';
        $obs     = trim($_POST['observaciones_validacion'] ?? '');
        $db->prepare("UPDATE Bajas SET estado_validacion=?, observaciones_validacion=?, fecha_validacion=NOW() WHERE id_baja=?")
           ->execute([$estado, $obs, $id]);
        header("Location: /pages/bajas.php?msg=" . urlencode("✅ Baja {$estado} correctamente."));
        exit;
    }

    // --- Eliminar baja y reactivar equipo ---
    elseif ($action === 'eliminar_baja') {
        $id  = (int)$_POST['id_baja'];
        $inv = trim($_POST['numero_inventario'] ?? '');
        $db->prepare("DELETE FROM Bajas WHERE id_baja=?")->execute([$id]);
        $db->prepare("UPDATE Equipos SET estado='Inactivo' WHERE numero_inventario=?")->execute([$inv]);
        header("Location: /pages/bajas.php?msg=" . urlencode("🗑 Baja eliminada. Equipo reactivado como Inactivo."));
        exit;
    }

    header("Location: /pages/bajas.php?msg=" . urlencode($msg));
    exit;
}

// Leer mensajes
if (isset($_GET['msg'])) $msg = $_GET['msg'];
$verPdf = isset($_GET['ver_pdf']) ? (int)$_GET['ver_pdf'] : 0;

// Cargar bajas
$bajas = $db->query("
    SELECT b.*, e.modelo, e.marca, e.procesador, e.ram, e.disco, a.nombre_area,
           u.nombre as tecnico_nombre, u.cargo as tecnico_cargo
    FROM Bajas b
    JOIN Equipos e ON e.numero_inventario = b.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = b.id_tecnico_responsable
    ORDER BY b.fecha_baja DESC
")->fetchAll();

// Equipos activos/inactivos/en reparación que se pueden dar de baja
$equiposActivos = $db->query("
    SELECT numero_inventario, modelo, marca, estado FROM Equipos 
    WHERE estado IN ('Activo','Inactivo','En Reparacion') 
    ORDER BY numero_inventario
")->fetchAll();

// ¿Ver baja específica para PDF?
$bajaDetalle = null;
if ($verPdf) {
    $stmt = $db->prepare("
        SELECT b.*, e.modelo, e.marca, e.procesador, e.ram, e.disco, e.numero_inventario,
               a.nombre_area, a.ubicacion, u.nombre as tecnico_nombre, u.cargo as tecnico_cargo
        FROM Bajas b
        JOIN Equipos e ON e.numero_inventario = b.numero_inventario
        LEFT JOIN Areas a ON e.id_area = a.id_area
        LEFT JOIN Usuarios u ON u.id_usuario = b.id_tecnico_responsable
        WHERE b.id_baja = ?
    ");
    $stmt->execute([$verPdf]);
    $bajaDetalle = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bajas de Equipos — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
    <style>
        .baja-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 14px;
            transition: border-color .2s;
        }
        .baja-card:hover { border-color: var(--accent); }
        .baja-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .baja-inv { font-family: var(--font-mono); color: var(--danger); font-size: 15px; font-weight: 700; }
        .baja-modelo { color: var(--text-secondary); font-size: 13px; margin-top: 2px; }
        .baja-meta { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; }
        .baja-meta-item { font-size: 12px; color: var(--text-muted); }
        .baja-meta-item strong { color: var(--text-secondary); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
        .baja-diag { 
            background: var(--bg-main); 
            border-left: 3px solid var(--danger);
            padding: 10px 14px; 
            border-radius: 0 6px 6px 0;
            font-size: 13px; 
            color: var(--text-secondary); 
            margin-top: 10px;
            line-height: 1.6;
        }
        .validacion-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-light);
            flex-wrap: wrap;
        }
        .badge-validado   { background: rgba(63,185,80,.15); color: var(--success); border: 1px solid rgba(63,185,80,.3); }
        .badge-rechazado  { background: rgba(248,81,73,.15);  color: var(--danger);  border: 1px solid rgba(248,81,73,.3); }
        .badge-pendiente-val { background: rgba(210,153,34,.15); color: var(--warning); border: 1px solid rgba(210,153,34,.3); }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">🗑 Bajas de Equipos</div>
                <div class="page-subtitle">Diagnóstico y validación institucional de equipos dados de baja</div>
            </div>
            <div class="page-actions">
                <button class="btn btn-danger" onclick="openModal('modalNuevaBaja')">+ Dar de Baja Equipo</button>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= str_starts_with($msg,'✅') ? 'alert-success' : (str_starts_with($msg,'🗑') ? 'alert-info' : 'alert-error') ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php if ($verPdf && $bajaDetalle): ?>
        <!-- Banner: PDF listo -->
        <div class="alert alert-success" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <span>📄 Baja registrada. El dictamen PDF está listo para descargar.</span>
            <a href="/pages/baja_pdf.php?pdf=<?= $verPdf ?>" target="_blank" class="btn btn-success btn-sm">📥 Descargar Dictamen PDF</a>
        </div>
        <?php endif; ?>

        <!-- Stats rápidas -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:24px">
            <?php
            $totalBajas    = $db->query("SELECT COUNT(*) FROM Bajas")->fetchColumn();
            $pendVal       = $db->query("SELECT COUNT(*) FROM Bajas WHERE estado_validacion='Pendiente'")->fetchColumn();
            $validadas     = $db->query("SELECT COUNT(*) FROM Bajas WHERE estado_validacion='Validado'")->fetchColumn();
            $rechazadas    = $db->query("SELECT COUNT(*) FROM Bajas WHERE estado_validacion='Rechazado'")->fetchColumn();
            ?>
            <div class="stat-card">
                <div class="stat-label">Total Bajas</div>
                <div class="stat-value danger"><?= $totalBajas ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pendientes Validación</div>
                <div class="stat-value warning"><?= $pendVal ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Validadas</div>
                <div class="stat-value success"><?= $validadas ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Rechazadas</div>
                <div class="stat-value"><?= $rechazadas ?></div>
            </div>
        </div>

        <!-- Lista de bajas -->
        <?php if (empty($bajas)): ?>
            <div class="card">
                <div class="empty-state" style="padding:60px 20px">
                    <span class="empty-icon">🖥</span>
                    <p>No hay equipos dados de baja. Usa el botón superior para registrar una baja.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($bajas as $b): ?>
            <div class="baja-card">
                <div class="baja-card-header">
                    <div>
                        <div class="baja-inv">📛 <?= e($b['numero_inventario']) ?></div>
                        <div class="baja-modelo"><?= e($b['modelo']) ?> <?= e($b['marca'] ?? '') ?> — <?= e($b['nombre_area'] ?? 'Sin área') ?></div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <!-- Badge validación -->
                        <?php
                        $vClass = match($b['estado_validacion']) {
                            'Validado'  => 'badge-validado',
                            'Rechazado' => 'badge-rechazado',
                            default     => 'badge-pendiente-val'
                        };
                        $vIcon = match($b['estado_validacion']) {
                            'Validado'  => '✅',
                            'Rechazado' => '❌',
                            default     => '⏳'
                        };
                        ?>
                        <span class="badge-estado <?= $vClass ?>"><?= $vIcon ?> <?= e($b['estado_validacion']) ?></span>
                        <a href="/pages/baja_pdf.php?pdf=<?= $b['id_baja'] ?>" target="_blank" class="btn btn-ghost btn-sm">📄 PDF</a>
                        <?php if ($b['estado_validacion'] === 'Pendiente'): ?>
                        <button class="btn btn-success btn-sm" onclick="abrirValidar(<?= $b['id_baja'] ?>, 'Validado')">✅ Validar</button>
                        <button class="btn btn-danger btn-sm" onclick="abrirValidar(<?= $b['id_baja'] ?>, 'Rechazado')">❌ Rechazar</button>
                        <?php endif; ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta baja? El equipo volverá a estado Inactivo.')">
                            <input type="hidden" name="action" value="eliminar_baja">
                            <input type="hidden" name="id_baja" value="<?= $b['id_baja'] ?>">
                            <input type="hidden" name="numero_inventario" value="<?= e($b['numero_inventario']) ?>">
                            <button type="submit" class="btn btn-ghost btn-sm btn-icon">🗑</button>
                        </form>
                    </div>
                </div>

                <div class="baja-meta">
                    <div class="baja-meta-item"><strong>Motivo</strong><?= e($b['motivo_baja']) ?></div>
                    <div class="baja-meta-item"><strong>Fecha de Baja</strong><?= fechaES($b['fecha_baja']) ?></div>
                    <div class="baja-meta-item"><strong>Recomendación</strong><?= e($b['recomendacion']) ?></div>
                    <div class="baja-meta-item"><strong>Técnico</strong><?= e($b['tecnico_nombre'] ?? '—') ?></div>
                    <?php if ($b['costo_reparacion_estimado']): ?>
                    <div class="baja-meta-item"><strong>Costo Reparación Est.</strong>$<?= number_format($b['costo_reparacion_estimado'],2) ?></div>
                    <?php endif; ?>
                    <?php if ($b['nombre_autoriza']): ?>
                    <div class="baja-meta-item"><strong>Autoriza</strong><?= e($b['nombre_autoriza']) ?> — <?= e($b['cargo_autoriza']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="baja-diag">
                    <strong style="color:var(--text-primary);display:block;margin-bottom:4px">🔍 Diagnóstico:</strong>
                    <?= nl2br(e($b['diagnostico_tecnico'])) ?>
                </div>

                <?php if ($b['estado_validacion'] !== 'Pendiente' && $b['observaciones_validacion']): ?>
                <div class="baja-diag" style="border-color:<?= $b['estado_validacion'] === 'Validado' ? 'var(--success)' : 'var(--danger)' ?>;margin-top:8px">
                    <strong style="color:var(--text-primary);display:block;margin-bottom:4px">📝 Observaciones de validación:</strong>
                    <?= nl2br(e($b['observaciones_validacion'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>

<!-- Modal: Nueva Baja -->
<div class="modal-overlay" id="modalNuevaBaja">
    <div class="modal-box" style="max-width:620px">
        <div class="modal-header">
            <div class="modal-title">📛 Dar de Baja un Equipo</div>
            <button class="modal-close" onclick="closeModal('modalNuevaBaja')">✕</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning">⚠ Esta acción cambiará el estado del equipo a <strong>Baja</strong> y generará un dictamen PDF oficial.</div>
            <form method="POST" action="/pages/bajas.php">
                <input type="hidden" name="action" value="registrar_baja">

                <div class="form-row">
                    <div class="form-group">
                        <label>Equipo a dar de baja *</label>
                        <select name="numero_inventario" required>
                            <option value="">Selecciona equipo...</option>
                            <?php foreach ($equiposActivos as $eq): ?>
                                <option value="<?= e($eq['numero_inventario']) ?>">
                                    <?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?>
                                    (<?= e($eq['estado']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha de Baja *</label>
                        <input type="date" name="fecha_baja" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Motivo de Baja *</label>
                        <select name="motivo_baja" required>
                            <option value="">Selecciona motivo...</option>
                            <option value="Daño Irreparable">💥 Daño Irreparable</option>
                            <option value="Obsolescencia">🕰 Obsolescencia Tecnológica</option>
                            <option value="Robo/Extravío">🚨 Robo / Extravío</option>
                            <option value="Siniestro">⚡ Siniestro (incendio, inundación, etc.)</option>
                            <option value="Vida Útil Cumplida">📅 Vida Útil Cumplida</option>
                            <option value="Otro">📝 Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Recomendación Final</label>
                        <select name="recomendacion">
                            <option value="Destrucción">🗑 Destrucción</option>
                            <option value="Donación">🤝 Donación</option>
                            <option value="Subasta">💰 Subasta</option>
                            <option value="Reciclaje">♻ Reciclaje</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Descripción de la falla / problema *</label>
                    <textarea name="descripcion_falla" rows="2" required placeholder="Describe el problema o falla que presenta el equipo..."></textarea>
                </div>

                <div class="form-group">
                    <label>Diagnóstico técnico *</label>
                    <textarea name="diagnostico_tecnico" rows="3" required placeholder="Diagnóstico técnico detallado. Explica por qué no es viable reparar o continuar usando el equipo..."></textarea>
                </div>

                <div class="form-group">
                    <label>Intentos de reparación previos</label>
                    <textarea name="intentos_reparacion" rows="2" placeholder="Describe los intentos de reparación que se realizaron anteriormente (si aplica)..."></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Costo estimado de reparación ($)</label>
                        <input type="number" name="costo_reparacion_estimado" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Valor actual estimado del equipo ($)</label>
                        <input type="number" name="valor_actual_estimado" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>

                <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:16px 0 10px">Validación Institucional</p>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre de quien autoriza</label>
                        <input type="text" name="nombre_autoriza" placeholder="Ej: Lic. Juan Pérez">
                    </div>
                    <div class="form-group">
                        <label>Cargo</label>
                        <input type="text" name="cargo_autoriza" placeholder="Ej: Director de Tecnología">
                    </div>
                </div>

                <button type="submit" class="btn btn-danger btn-full" style="margin-top:8px">
                    📛 Registrar Baja y Generar Dictamen
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Validar/Rechazar -->
<div class="modal-overlay" id="modalValidar">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title" id="validarTitulo">✅ Validar Baja</div>
            <button class="modal-close" onclick="closeModal('modalValidar')">✕</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/bajas.php">
                <input type="hidden" name="action" value="validar_baja">
                <input type="hidden" name="id_baja" id="validarId">
                <input type="hidden" name="estado_validacion" id="validarEstado">
                <div class="form-group">
                    <label>Observaciones / Justificación</label>
                    <textarea name="observaciones_validacion" rows="4" placeholder="Escribe las observaciones o justificación de la decisión (opcional)..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-full" id="validarBtn">Confirmar</button>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

function abrirValidar(id, estado) {
    document.getElementById('validarId').value    = id;
    document.getElementById('validarEstado').value = estado;
    document.getElementById('validarTitulo').textContent = estado === 'Validado' ? '✅ Validar Baja' : '❌ Rechazar Baja';
    document.getElementById('validarBtn').textContent    = estado === 'Validado' ? '✅ Confirmar Validación' : '❌ Confirmar Rechazo';
    document.getElementById('validarBtn').className = estado === 'Validado'
        ? 'btn btn-success btn-full'
        : 'btn btn-danger btn-full';
    openModal('modalValidar');
}

// Abrir modal de nueva baja si viene de agregar uno
<?php if ($verPdf): ?>
// Auto-abrir modal PDF tras registro
window.addEventListener('load', () => {
    document.getElementById('modalNuevaBaja')?.classList.remove('open');
});
<?php endif; ?>
</script>
</body>
</html>