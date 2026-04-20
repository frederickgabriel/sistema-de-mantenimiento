<?php
// =============================================
// GESTIÓN DE MANTENIMIENTOS
// Archivo: pages/mantenimientos.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db  = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Registrar Mantenimiento ---
    if ($action === 'nuevo_mantenimiento') {
        $inv    = trim($_POST['numero_inventario'] ?? '');
        $tipo   = $_POST['tipo_mantenimiento'] ?? 'Preventivo';
        $fIni   = $_POST['fecha_realizacion'] ?? '';
        $fEnt   = $_POST['fecha_entrega'] ?? null;
        $det    = trim($_POST['detalles'] ?? '');

        // Calcular próximo: 6 meses desde entrega (o inicio si no hay entrega)
        $base = $fEnt ?: $fIni;
        $proximo = date('Y-m-d', strtotime($base . ' +6 months'));

        $db->prepare("INSERT INTO Mantenimientos (numero_inventario, tipo_mantenimiento, fecha_realizacion, fecha_entrega, proximo_mantenimiento, detalles, id_tecnico) VALUES (?,?,?,?,?,?,?)")
           ->execute([$inv, $tipo, $fIni, $fEnt ?: null, $proximo, $det, $_SESSION['usuario']['id']]);
        $msg = "✅ Mantenimiento registrado. Próxima cita: " . fechaES($proximo);
    }

    // --- Reagendar ---
    elseif ($action === 'reagendar') {
        $idMtto   = (int)$_POST['id_mantenimiento'];
        $nuevaFecha= $_POST['nueva_fecha'] ?? '';
        $db->prepare("UPDATE Mantenimientos SET proximo_mantenimiento=? WHERE id_mantenimiento=?")
           ->execute([$nuevaFecha, $idMtto]);
        $msg = "✅ Fecha reagendada a " . fechaES($nuevaFecha);
    }

    // --- Eliminar Mantenimiento ---
    elseif ($action === 'eliminar_mantenimiento') {
        $id = (int)$_POST['id_mantenimiento'];
        $db->prepare("DELETE FROM Mantenimientos WHERE id_mantenimiento=?")->execute([$id]);
        $msg = "🗑 Registro eliminado.";
    }

    header("Location: /pages/mantenimientos.php?msg=" . urlencode($msg));
    exit;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// Filtro por equipo específico
$filtroEquipo = trim($_GET['equipo'] ?? '');

// Cargar mantenimientos
$params = [];
$where  = '';
if ($filtroEquipo) {
    $where  = "WHERE m.numero_inventario = ?";
    $params = [$filtroEquipo];
}

$mantenimientos = $db->prepare("
    SELECT m.*, e.modelo, e.marca, a.nombre_area, u.nombre as tecnico_nombre
    FROM Mantenimientos m
    JOIN Equipos e ON e.numero_inventario = m.numero_inventario
    LEFT JOIN Areas a ON e.id_area = a.id_area
    LEFT JOIN Usuarios u ON u.id_usuario = m.id_tecnico
    {$where}
    ORDER BY m.fecha_realizacion DESC
");
$mantenimientos->execute($params);
$mantenimientos = $mantenimientos->fetchAll();

// Para el select de equipos
$equipos = $db->query("SELECT numero_inventario, modelo, marca FROM Equipos ORDER BY numero_inventario")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mantenimientos — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">🔧 Mantenimientos</div>
                <div class="page-subtitle">Historial y registro de mantenimientos</div>
            </div>
            <div class="page-actions">
                <?php if ($filtroEquipo): ?>
                    <a href="/pages/mantenimientos.php" class="btn btn-ghost">✕ Quitar filtro</a>
                <?php endif; ?>
                <button class="btn btn-primary" onclick="openModal('modalNuevoMtto')">+ Registrar Mantenimiento</button>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= str_starts_with($msg,'✅') ? 'alert-success' : (str_starts_with($msg,'🗑') ? 'alert-info' : 'alert-error') ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <?php if ($filtroEquipo): ?>
            <div class="alert alert-info">🔍 Mostrando mantenimientos del equipo: <strong><?= e($filtroEquipo) ?></strong></div>
        <?php endif; ?>

        <!-- Tabla de mantenimientos -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📋 Historial de Mantenimientos</div>
                <span class="text-muted" style="font-size:13px"><?= count($mantenimientos) ?> registros</span>
            </div>
            <div class="table-wrapper">
                <?php if (empty($mantenimientos)): ?>
                    <div class="empty-state">
                        <span class="empty-icon">🔧</span>
                        <p>No hay mantenimientos registrados<?= $filtroEquipo ? " para este equipo" : "" ?>.</p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Equipo</th><th>Área</th><th>Tipo</th>
                            <th>Fecha Inicio</th><th>Fecha Entrega</th>
                            <th>Próx. Mantenimiento</th><th>Técnico</th><th>Detalles</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mantenimientos as $m): ?>
                    <tr>
                        <td>
                            <span class="text-mono"><?= e($m['numero_inventario']) ?></span><br>
                            <small class="text-muted"><?= e($m['modelo']) ?> <?= e($m['marca'] ?? '') ?></small>
                        </td>
                        <td class="text-secondary"><?= e($m['nombre_area'] ?? '—') ?></td>
                        <td>
                            <?php if ($m['tipo_mantenimiento'] === 'Preventivo'): ?>
                                <span class="badge-estado badge-proceso">🛡 Preventivo</span>
                            <?php else: ?>
                                <span class="badge-estado badge-reparacion">🔨 Correctivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary"><?= fechaES($m['fecha_realizacion']) ?></td>
                        <td class="text-secondary"><?= fechaES($m['fecha_entrega']) ?></td>
                        <td>
                            <?php
                            if ($m['proximo_mantenimiento']) {
                                $dias = (int)((strtotime($m['proximo_mantenimiento']) - time()) / 86400);
                                $color = $dias < 0 ? 'danger' : ($dias <= 14 ? 'warning' : 'success');
                                echo "<span class=\"text-{$color}\">" . fechaES($m['proximo_mantenimiento']) . "</span>";
                                if ($dias < 0) echo "<br><small class=\"text-danger\">Vencido " . abs($dias) . "d</small>";
                                elseif ($dias <= 14) echo "<br><small class=\"text-warning\">En {$dias} días</small>";
                            } else { echo '<span class="text-muted">—</span>'; }
                            ?>
                        </td>
                        <td class="text-secondary" style="font-size:13px"><?= e($m['tecnico_nombre'] ?? '—') ?></td>
                        <td style="max-width:180px;font-size:12px;color:var(--text-secondary)">
                            <?= e(mb_substr($m['detalles'] ?? '', 0, 80)) ?><?= strlen($m['detalles'] ?? '') > 80 ? '…' : '' ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px">
                                <button class="btn btn-ghost btn-sm btn-icon" title="Reagendar"
                                    onclick="abrirReagendar(<?= $m['id_mantenimiento'] ?>, '<?= e($m['numero_inventario']) ?>', '<?= $m['proximo_mantenimiento'] ?>')">📅</button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este registro?')">
                                    <input type="hidden" name="action" value="eliminar_mantenimiento">
                                    <input type="hidden" name="id_mantenimiento" value="<?= $m['id_mantenimiento'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Eliminar">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- Modal: Nuevo Mantenimiento -->
<div class="modal-overlay" id="modalNuevoMtto">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">🔧 Registrar Mantenimiento</div>
            <button class="modal-close" onclick="closeModal('modalNuevoMtto')">✕</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/mantenimientos.php">
                <input type="hidden" name="action" value="nuevo_mantenimiento">
                <div class="form-group">
                    <label>Equipo (No. Inventario) *</label>
                    <select name="numero_inventario" required>
                        <option value="">Selecciona un equipo...</option>
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= e($eq['numero_inventario']) ?>" <?= $filtroEquipo === $eq['numero_inventario'] ? 'selected' : '' ?>>
                                <?= e($eq['numero_inventario']) ?> — <?= e($eq['modelo']) ?> <?= e($eq['marca'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de Mantenimiento *</label>
                    <select name="tipo_mantenimiento" required>
                        <option value="Preventivo">🛡 Preventivo</option>
                        <option value="Correctivo">🔨 Correctivo</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fecha de Inicio *</label>
                        <input type="date" name="fecha_realizacion" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Fecha de Entrega</label>
                        <input type="date" name="fecha_entrega">
                    </div>
                </div>
                <div class="form-group">
                    <label>Detalles / Observaciones</label>
                    <textarea name="detalles" rows="3" placeholder="Describe el trabajo realizado, piezas cambiadas, etc."></textarea>
                </div>
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
                    💡 El próximo mantenimiento se agendará automáticamente a 6 meses de la fecha de entrega.
                </p>
                <button type="submit" class="btn btn-primary btn-full">Guardar Historial</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Reagendar -->
<div class="modal-overlay" id="modalReagendar">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">📅 Reagendar Mantenimiento: <span id="reagendarLabel"></span></div>
            <button class="modal-close" onclick="closeModal('modalReagendar')">✕</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/mantenimientos.php">
                <input type="hidden" name="action" value="reagendar">
                <input type="hidden" name="id_mantenimiento" id="reagendarId">
                <div class="form-group">
                    <label>Nueva Fecha para Próximo Mantenimiento</label>
                    <input type="date" name="nueva_fecha" id="reagendarFecha" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Confirmar Nueva Fecha</button>
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

function abrirReagendar(idMtto, inv, fechaActual) {
    document.getElementById('reagendarLabel').textContent = inv;
    document.getElementById('reagendarId').value   = idMtto;
    document.getElementById('reagendarFecha').value = fechaActual || '';
    openModal('modalReagendar');
}
</script>

</body>
</html>
