<?php
// =============================================
// GESTIÓN DE EQUIPOS Y ÁREAS
// Archivo: pages/equipos.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db  = getDB();
$msg = '';
$err = '';

// ==========================================
// Procesar POST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Nueva Área ---
    if ($action === 'nueva_area') {
        $nombre   = trim($_POST['nombre_area'] ?? '');
        $ubicacion= trim($_POST['ubicacion'] ?? '');
        if ($nombre) {
            $db->prepare("INSERT INTO Areas (nombre_area, ubicacion) VALUES (?, ?)")
               ->execute([$nombre, $ubicacion]);
            $msg = "✅ Área «{$nombre}» registrada correctamente.";
        } else { $err = 'El nombre del área es obligatorio.'; }
    }

    // --- Eliminar Área ---
    elseif ($action === 'eliminar_area') {
        $id = (int)$_POST['id_area'];
        $db->prepare("DELETE FROM Areas WHERE id_area = ?")->execute([$id]);
        $msg = '🗑 Área eliminada.';
    }

    // --- Nuevo Equipo ---
    elseif ($action === 'nuevo_equipo') {
        $inv   = trim($_POST['numero_inventario'] ?? '');
        $model = trim($_POST['modelo'] ?? '');
        $marca = trim($_POST['marca'] ?? '');
        $proc  = trim($_POST['procesador'] ?? '');
        $ram   = trim($_POST['ram'] ?? '');
        $disco = trim($_POST['disco'] ?? '');
        $area  = $_POST['id_area'] ?: null;
        if ($inv && $model) {
            try {
                $db->prepare("INSERT INTO Equipos (numero_inventario, modelo, marca, procesador, ram, disco, id_area, estado) VALUES (?,?,?,?,?,?,?,'Activo')")
                   ->execute([$inv, $model, $marca, $proc, $ram, $disco, $area]);
                $msg = "✅ Equipo «{$inv}» registrado.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) $err = 'Ese número de inventario ya existe.';
                else $err = 'Error al guardar el equipo.';
            }
        } else { $err = 'Inventario y modelo son obligatorios.'; }
    }

    // --- Editar Equipo ---
    elseif ($action === 'editar_equipo') {
        $inv   = trim($_POST['numero_inventario'] ?? '');
        $model = trim($_POST['modelo'] ?? '');
        $marca = trim($_POST['marca'] ?? '');
        $proc  = trim($_POST['procesador'] ?? '');
        $ram   = trim($_POST['ram'] ?? '');
        $disco = trim($_POST['disco'] ?? '');
        $estado= $_POST['estado'] ?? 'Activo';
        $area  = $_POST['id_area'] ?: null;
        $db->prepare("UPDATE Equipos SET modelo=?, marca=?, procesador=?, ram=?, disco=?, estado=?, id_area=? WHERE numero_inventario=?")
           ->execute([$model, $marca, $proc, $ram, $disco, $estado, $area, $inv]);
        $msg = "✅ Equipo «{$inv}» actualizado.";
    }

    // --- Eliminar Equipo ---
    elseif ($action === 'eliminar_equipo') {
        $inv = trim($_POST['numero_inventario'] ?? '');
        $db->prepare("DELETE FROM Equipos WHERE numero_inventario=?")->execute([$inv]);
        $msg = "🗑 Equipo eliminado del sistema.";
    }

    header("Location: /pages/equipos.php?msg=" . urlencode($msg ?: $err));
    exit;
}

// ---- Leer mensajes de redirección ----
if (isset($_GET['msg'])) $msg = $_GET['msg'];

// ---- Cargar datos ----
$areas   = $db->query("SELECT *, (SELECT COUNT(*) FROM Equipos WHERE id_area=Areas.id_area) as total_equipos FROM Areas ORDER BY nombre_area")->fetchAll();
$equipos = $db->query("
    SELECT e.*, a.nombre_area 
    FROM Equipos e
    LEFT JOIN Areas a ON e.id_area = a.id_area
    ORDER BY e.fecha_registro DESC
")->fetchAll();

// Para el select de áreas en formularios
$areasSelect = $db->query("SELECT id_area, nombre_area FROM Areas ORDER BY nombre_area")->fetchAll();

// Obtener equipo a editar (si viene por GET)
$equipoEditar = null;
if (isset($_GET['editar'])) {
    $stmt = $db->prepare("SELECT e.*, a.nombre_area FROM Equipos e LEFT JOIN Areas a ON e.id_area=a.id_area WHERE e.numero_inventario=?");
    $stmt->execute([trim($_GET['editar'])]);
    $equipoEditar = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipos y Áreas — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">🖥 Equipos y Áreas</div>
                <div class="page-subtitle">Inventario y gestión de salas de cómputo</div>
            </div>
            <div class="page-actions">
                <button class="btn btn-ghost" onclick="openModal('modalNuevaArea')">+ Nueva Área</button>
                <button class="btn btn-primary" onclick="openModal('modalNuevoEquipo')">+ Nuevo Equipo</button>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= str_starts_with($msg,'✅') ? 'alert-success' : (str_starts_with($msg,'🗑') ? 'alert-info' : 'alert-error') ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <!-- Áreas -->
        <div class="card" style="margin-bottom:24px">
            <div class="card-header">
                <div class="card-title">🏫 Áreas / Salones Registradas</div>
                <span class="text-muted" style="font-size:13px"><?= count($areas) ?> áreas</span>
            </div>
            <div class="table-wrapper">
                <?php if (empty($areas)): ?>
                    <div class="empty-state"><span class="empty-icon">🏫</span><p>No hay áreas. Agrega una con el botón superior.</p></div>
                <?php else: ?>
                <table>
                    <thead><tr><th>#</th><th>Nombre del Área</th><th>Ubicación</th><th>Equipos</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($areas as $a): ?>
                    <tr>
                        <td class="text-muted" style="font-size:12px"><?= e($a['id_area']) ?></td>
                        <td><strong><?= e($a['nombre_area']) ?></strong></td>
                        <td class="text-secondary"><?= e($a['ubicacion'] ?? '—') ?></td>
                        <td><span class="badge-estado badge-proceso"><?= $a['total_equipos'] ?> equipos</span></td>
                        <td>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta área? Se desvincularán sus equipos.')">
                                <input type="hidden" name="action" value="eliminar_area">
                                <input type="hidden" name="id_area" value="<?= $a['id_area'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Eliminar">🗑</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Equipos -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">🖥 Inventario de Equipos</div>
                <span class="text-muted" style="font-size:13px"><?= count($equipos) ?> equipos</span>
            </div>
            <div class="table-wrapper">
                <?php if (empty($equipos)): ?>
                    <div class="empty-state"><span class="empty-icon">🖥</span><p>No hay equipos. Regístralos con el botón superior.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>No. Inventario</th><th>Modelo / Marca</th><th>Especificaciones</th>
                            <th>Área</th><th>Estado</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($equipos as $eq): ?>
                    <tr>
                        <td class="text-mono"><?= e($eq['numero_inventario']) ?></td>
                        <td>
                            <strong><?= e($eq['modelo']) ?></strong>
                            <?php if ($eq['marca']): ?><br><small class="text-muted"><?= e($eq['marca']) ?></small><?php endif; ?>
                        </td>
                        <td class="text-secondary" style="font-size:12px">
                            <?php $specs = array_filter([$eq['procesador'], $eq['ram'] ? $eq['ram'].' RAM' : null, $eq['disco']]); echo implode(' · ', $specs) ?: '—'; ?>
                        </td>
                        <td class="text-secondary"><?= e($eq['nombre_area'] ?? '—') ?></td>
                        <td><?= badgeEstado($eq['estado']) ?></td>
                        <td style="display:flex;gap:6px;flex-wrap:wrap">
                            <a href="/pages/mantenimientos.php?equipo=<?= urlencode($eq['numero_inventario']) ?>" class="btn btn-ghost btn-sm btn-icon" title="Mantenimiento">🔧</a>
                            <button class="btn btn-warning btn-sm btn-icon" title="Editar" 
                                onclick="abrirEditar(<?= htmlspecialchars(json_encode($eq), ENT_QUOTES) ?>)">✏</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar el equipo <?= e($eq['numero_inventario']) ?>?')">
                                <input type="hidden" name="action" value="eliminar_equipo">
                                <input type="hidden" name="numero_inventario" value="<?= e($eq['numero_inventario']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Eliminar">🗑</button>
                            </form>
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

<!-- Modal: Nueva Área -->
<div class="modal-overlay" id="modalNuevaArea">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">🏫 Registrar Nueva Área</div>
            <button class="modal-close" onclick="closeModal('modalNuevaArea')">✕</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/equipos.php">
                <input type="hidden" name="action" value="nueva_area">
                <div class="form-group">
                    <label>Nombre del Área *</label>
                    <input type="text" name="nombre_area" placeholder="Ej: Sala de Cómputo A" required>
                </div>
                <div class="form-group">
                    <label>Ubicación</label>
                    <input type="text" name="ubicacion" placeholder="Ej: Edificio Principal, Planta Baja">
                </div>
                <button type="submit" class="btn btn-success btn-full">Guardar Área</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Nuevo Equipo -->
<div class="modal-overlay" id="modalNuevoEquipo">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">🖥 Registrar Nuevo Equipo</div>
            <button class="modal-close" onclick="closeModal('modalNuevoEquipo')">✕</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/equipos.php">
                <input type="hidden" name="action" value="nuevo_equipo">
                <div class="form-row">
                    <div class="form-group">
                        <label>No. Inventario *</label>
                        <input type="text" name="numero_inventario" placeholder="INV-001" required>
                    </div>
                    <div class="form-group">
                        <label>Modelo *</label>
                        <input type="text" name="modelo" placeholder="OptiPlex 7090" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Marca</label>
                        <input type="text" name="marca" placeholder="Dell, HP, Lenovo...">
                    </div>
                    <div class="form-group">
                        <label>Área / Salón</label>
                        <select name="id_area">
                            <option value="">Sin asignar</option>
                            <?php foreach ($areasSelect as $a): ?>
                                <option value="<?= $a['id_area'] ?>"><?= e($a['nombre_area']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Procesador</label>
                        <input type="text" name="procesador" placeholder="Intel i5-10400">
                    </div>
                    <div class="form-group">
                        <label>RAM</label>
                        <input type="text" name="ram" placeholder="8 GB DDR4">
                    </div>
                </div>
                <div class="form-group">
                    <label>Disco / Almacenamiento</label>
                    <input type="text" name="disco" placeholder="SSD 256 GB">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Guardar Equipo</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Equipo -->
<div class="modal-overlay" id="modalEditarEquipo">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">✏ Editar Equipo: <span id="editInvLabel"></span></div>
            <button class="modal-close" onclick="closeModal('modalEditarEquipo')">✕</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="/pages/equipos.php">
                <input type="hidden" name="action" value="editar_equipo">
                <input type="hidden" name="numero_inventario" id="editInv">
                <div class="form-row">
                    <div class="form-group">
                        <label>Modelo *</label>
                        <input type="text" name="modelo" id="editModelo" required>
                    </div>
                    <div class="form-group">
                        <label>Marca</label>
                        <input type="text" name="marca" id="editMarca">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Procesador</label>
                        <input type="text" name="procesador" id="editProc">
                    </div>
                    <div class="form-group">
                        <label>RAM</label>
                        <input type="text" name="ram" id="editRam">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Disco</label>
                        <input type="text" name="disco" id="editDisco">
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" id="editEstado">
                            <option value="Activo">Activo</option>
                            <option value="Inactivo">Inactivo</option>
                            <option value="En Reparacion">En Reparación</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Área / Salón</label>
                    <select name="id_area" id="editArea">
                        <option value="">Sin asignar</option>
                        <?php foreach ($areasSelect as $a): ?>
                            <option value="<?= $a['id_area'] ?>"><?= e($a['nombre_area']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-warning btn-full">Guardar Cambios</button>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Cerrar al click fuera del modal
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

function abrirEditar(eq) {
    document.getElementById('editInvLabel').textContent = eq.numero_inventario;
    document.getElementById('editInv').value    = eq.numero_inventario;
    document.getElementById('editModelo').value  = eq.modelo || '';
    document.getElementById('editMarca').value   = eq.marca || '';
    document.getElementById('editProc').value    = eq.procesador || '';
    document.getElementById('editRam').value     = eq.ram || '';
    document.getElementById('editDisco').value   = eq.disco || '';
    document.getElementById('editEstado').value  = eq.estado || 'Activo';
    document.getElementById('editArea').value    = eq.id_area || '';
    openModal('modalEditarEquipo');
}
</script>

</body>
</html>