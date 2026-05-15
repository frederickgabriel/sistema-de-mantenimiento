<?php
// =============================================
// GESTIÓN DE ROLES — Solo Admin
// Archivo: pages/admin_roles.php
// =============================================
require_once '../includes/config.php';
requireAdmin();

$db  = getDB();
$msg = '';

// ==========================================
// Procesar acciones del admin
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';
    $idSolicitud = (int)($_POST['id_solicitud'] ?? 0);
    $respuesta   = trim($_POST['respuesta'] ?? '');

    // --- Aprobar solicitud ---
    if ($action === 'aprobar' && $idSolicitud) {
        // Obtener id_usuario de la solicitud
        $sol = $db->prepare("SELECT id_usuario FROM SolicitudesRol WHERE id_solicitud=?");
        $sol->execute([$idSolicitud]);
        $sol = $sol->fetch();

        if ($sol) {
            // Cambiar rol a admin
            $db->prepare("UPDATE Usuarios SET rol='admin' WHERE id_usuario=?")
               ->execute([$sol['id_usuario']]);
            // Actualizar solicitud
            $db->prepare("UPDATE SolicitudesRol SET estado='Aprobada', respuesta=?, fecha_respuesta=NOW() WHERE id_solicitud=?")
               ->execute([$respuesta ?: 'Solicitud aprobada.', $idSolicitud]);
            $msg = '✅ Rol de Administrador otorgado correctamente.';
        }
    }

    // --- Rechazar solicitud ---
    elseif ($action === 'rechazar' && $idSolicitud) {
        $db->prepare("UPDATE SolicitudesRol SET estado='Rechazada', respuesta=?, fecha_respuesta=NOW() WHERE id_solicitud=?")
           ->execute([$respuesta ?: 'Solicitud rechazada.', $idSolicitud]);
        $msg = '🚫 Solicitud rechazada.';
    }

    // --- Quitar rol admin a un usuario ---
    elseif ($action === 'quitar_admin') {
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        // No se puede quitar el rol al propio admin logueado
        if ($idUsuario === (int)$_SESSION['usuario']['id']) {
            $msg = '❌ No puedes quitarte el rol de administrador a ti mismo.';
        } else {
            $db->prepare("UPDATE Usuarios SET rol='usuario' WHERE id_usuario=?")->execute([$idUsuario]);
            $msg = '✅ Rol actualizado a Usuario.';
        }
    }

    // --- Dar admin directo a un usuario ---
    elseif ($action === 'dar_admin') {
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        $db->prepare("UPDATE Usuarios SET rol='admin' WHERE id_usuario=?")->execute([$idUsuario]);
        $msg = '✅ Rol de Administrador otorgado.';
    }

    header("Location: /pages/admin_roles.php?msg=" . urlencode($msg));
    exit;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// Cargar solicitudes
$solicitudes = $db->query("
    SELECT s.*, u.nombre, u.cargo, u.correo, u.rol
    FROM SolicitudesRol s
    JOIN Usuarios u ON u.id_usuario = s.id_usuario
    ORDER BY FIELD(s.estado,'Pendiente','Aprobada','Rechazada'), s.fecha_solicitud DESC
")->fetchAll();

// Cargar todos los usuarios
$usuarios = $db->query("
    SELECT id_usuario, nombre, cargo, correo, rol, fecha_registro,
           (SELECT COUNT(*) FROM Mantenimientos WHERE id_tecnico=Usuarios.id_usuario) as total_mttos,
           (SELECT COUNT(*) FROM Tareas WHERE id_usuario_asignado=Usuarios.id_usuario) as total_tareas
    FROM Usuarios
    ORDER BY rol DESC, nombre ASC
")->fetchAll();

$pendientes = array_filter($solicitudes, fn($s) => $s['estado'] === 'Pendiente');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Roles — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
    <style>
        .solicitud-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 20px;
            margin-bottom: 14px;
            transition: border-color .2s;
        }
        .solicitud-card.pendiente { border-left: 3px solid var(--warning); }
        .solicitud-card.aprobada  { border-left: 3px solid var(--success); }
        .solicitud-card.rechazada { border-left: 3px solid var(--danger); }

        .sol-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .sol-nombre { font-weight: 700; font-size: 15px; color: var(--text-primary); }
        .sol-meta   { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .sol-just   {
            background: var(--bg-main);
            border-left: 3px solid var(--border);
            padding: 10px 14px;
            border-radius: 0 6px 6px 0;
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 12px;
        }
        .sol-respuesta {
            background: var(--bg-main);
            border-left: 3px solid var(--success);
            padding: 10px 14px;
            border-radius: 0 6px 6px 0;
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
        }
        .sol-respuesta.rechazada { border-color: var(--danger); }

        .user-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border-light);
            transition: background .15s;
        }
        .user-row:last-child { border-bottom: none; }
        .user-row:hover { background: var(--bg-hover); }

        .user-avatar-sm {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-dark), var(--purple));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
            font-family: var(--font-mono);
        }
        .user-info-col { flex: 1; min-width: 0; }
        .user-nombre   { font-weight: 600; font-size: 14px; color: var(--text-primary); }
        .user-cargo    { font-size: 12px; color: var(--text-muted); }
        .user-actions  { display: flex; gap: 8px; flex-shrink: 0; }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">👑 Gestión de Roles</div>
                <div class="page-subtitle">Administra permisos y solicitudes de acceso</div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= str_starts_with($msg,'✅') ? 'alert-success' : (str_starts_with($msg,'🚫') ? 'alert-warning' : 'alert-error') ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:28px">
            <?php
            $totalUsuarios = count($usuarios);
            $totalAdmins   = count(array_filter($usuarios, fn($u) => $u['rol'] === 'admin'));
            $totalPend     = count($pendientes);
            $totalSols     = count($solicitudes);
            ?>
            <div class="stat-card">
                <div class="stat-label">Total Usuarios</div>
                <div class="stat-value accent"><?= $totalUsuarios ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Administradores</div>
                <div class="stat-value" style="color:var(--purple)"><?= $totalAdmins ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Solicitudes Pendientes</div>
                <div class="stat-value warning"><?= $totalPend ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Solicitudes</div>
                <div class="stat-value"><?= $totalSols ?></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">

            <!-- ========================
                 SOLICITUDES DE ROL
                 ======================== -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">📨 Solicitudes de Rol Admin</div>
                        <?php if ($totalPend > 0): ?>
                            <span class="badge-estado badge-reparacion">⏳ <?= $totalPend ?> pendiente(s)</span>
                        <?php endif; ?>
                    </div>
                    <div style="padding:16px">
                        <?php if (empty($solicitudes)): ?>
                            <div class="empty-state" style="padding:32px 0">
                                <span class="empty-icon" style="font-size:36px">📭</span>
                                <p>No hay solicitudes aún.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($solicitudes as $s):
                                $claseCard = strtolower($s['estado']);
                            ?>
                            <div class="solicitud-card <?= $claseCard ?>">
                                <div class="sol-header">
                                    <div>
                                        <div class="sol-nombre">👤 <?= e($s['nombre']) ?></div>
                                        <div class="sol-meta"><?= e($s['cargo']) ?> · <?= e($s['correo']) ?></div>
                                        <div class="sol-meta">Solicitado: <?= fechaES(date('Y-m-d', strtotime($s['fecha_solicitud']))) ?></div>
                                    </div>
                                    <?php
                                    $bc = match($s['estado']) {
                                        'Pendiente' => 'badge-reparacion',
                                        'Aprobada'  => 'badge-realizado',
                                        'Rechazada' => 'badge-no-realizado',
                                        default     => 'badge-inactivo'
                                    };
                                    $bi = match($s['estado']) {
                                        'Pendiente' => '⏳', 'Aprobada' => '✅', 'Rechazada' => '❌', default => ''
                                    };
                                    ?>
                                    <span class="badge-estado <?= $bc ?>"><?= $bi ?> <?= e($s['estado']) ?></span>
                                </div>

                                <div class="sol-just">
                                    <strong style="color:var(--text-primary);display:block;margin-bottom:3px">Justificación:</strong>
                                    <?= nl2br(e($s['justificacion'])) ?>
                                </div>

                                <?php if ($s['estado'] === 'Pendiente'): ?>
                                <!-- Formulario de respuesta -->
                                <details style="margin-top:8px">
                                    <summary style="cursor:pointer;font-size:13px;color:var(--accent);margin-bottom:10px">
                                        Responder solicitud →
                                    </summary>
                                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
                                        <div class="form-group" style="margin-bottom:0">
                                            <label>Respuesta / Observación (opcional)</label>
                                            <textarea id="resp_<?= $s['id_solicitud'] ?>" rows="2"
                                                style="width:100%;background:var(--bg-main);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);padding:8px;font-family:var(--font-main);font-size:13px"
                                                placeholder="Mensaje para el usuario..."></textarea>
                                        </div>
                                        <div style="display:flex;gap:8px">
                                            <form method="POST" style="flex:1" onsubmit="document.querySelector('[name=respuesta]',this).value=document.getElementById('resp_<?= $s['id_solicitud'] ?>').value">
                                                <input type="hidden" name="action" value="aprobar">
                                                <input type="hidden" name="id_solicitud" value="<?= $s['id_solicitud'] ?>">
                                                <input type="hidden" name="respuesta" value="">
                                                <button type="submit" class="btn btn-success btn-sm btn-full"
                                                    onclick="this.form.querySelector('[name=respuesta]').value=document.getElementById('resp_<?= $s['id_solicitud'] ?>').value"
                                                    >✅ Aprobar</button>
                                            </form>
                                            <form method="POST" style="flex:1">
                                                <input type="hidden" name="action" value="rechazar">
                                                <input type="hidden" name="id_solicitud" value="<?= $s['id_solicitud'] ?>">
                                                <input type="hidden" name="respuesta" value="">
                                                <button type="submit" class="btn btn-danger btn-sm btn-full"
                                                    onclick="this.form.querySelector('[name=respuesta]').value=document.getElementById('resp_<?= $s['id_solicitud'] ?>').value"
                                                    >❌ Rechazar</button>
                                            </form>
                                        </div>
                                    </div>
                                </details>

                                <?php elseif ($s['respuesta']): ?>
                                <div class="sol-respuesta <?= $s['estado'] === 'Rechazada' ? 'rechazada' : '' ?>">
                                    <strong style="color:var(--text-primary);display:block;margin-bottom:2px">Respuesta del admin:</strong>
                                    <?= nl2br(e($s['respuesta'])) ?>
                                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                                        <?= $s['fecha_respuesta'] ? fechaES(date('Y-m-d', strtotime($s['fecha_respuesta']))) : '' ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ========================
                 LISTADO DE USUARIOS
                 ======================== -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">👥 Usuarios del Sistema</div>
                        <span class="text-muted" style="font-size:12px"><?= $totalUsuarios ?> usuarios</span>
                    </div>
                    <div>
                        <?php foreach ($usuarios as $u): ?>
                        <div class="user-row">
                            <div class="user-avatar-sm">
                                <?= strtoupper(substr($u['nombre'], 0, 1)) ?>
                            </div>
                            <div class="user-info-col">
                                <div class="user-nombre">
                                    <?= e($u['nombre']) ?>
                                    <?= $u['id_usuario'] == $_SESSION['usuario']['id'] ? '<span style="font-size:11px;color:var(--text-muted)">(tú)</span>' : '' ?>
                                </div>
                                <div class="user-cargo"><?= e($u['cargo']) ?> · <?= e($u['correo']) ?></div>
                                <div class="user-cargo" style="margin-top:2px">
                                    🔧 <?= $u['total_mttos'] ?> mttos · 📋 <?= $u['total_tareas'] ?> tareas
                                </div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
                                <?= badgeRol($u['rol']) ?>
                                <?php if ($u['id_usuario'] != $_SESSION['usuario']['id']): ?>
                                    <?php if ($u['rol'] === 'usuario'): ?>
                                        <form method="POST" onsubmit="return confirm('¿Dar rol Admin a <?= e($u['nombre']) ?>?')">
                                            <input type="hidden" name="action" value="dar_admin">
                                            <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                                            <button class="btn btn-ghost btn-sm" style="font-size:11px">👑 Dar Admin</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" onsubmit="return confirm('¿Quitar rol Admin a <?= e($u['nombre']) ?>?')">
                                            <input type="hidden" name="action" value="quitar_admin">
                                            <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                                            <button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--danger)">↩ Quitar Admin</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tabla de permisos -->
                <div class="card" style="margin-top:20px">
                    <div class="card-header">
                        <div class="card-title">🔐 Tabla de Permisos</div>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr><th>Acción</th><th style="text-align:center">👑 Admin</th><th style="text-align:center">👤 Usuario</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $permisos = [
                                    ['Ver Dashboard y Reportes',     true,  true],
                                    ['Ver Equipos e inventario',      true,  true],
                                    ['Registrar Mantenimientos',      true,  true],
                                    ['Gestionar Tareas',              true,  true],
                                    ['Ver Calendario',                true,  true],
                                    ['Crear / Editar Áreas',          true,  false],
                                    ['Crear / Editar Equipos',        true,  false],
                                    ['Eliminar Equipos',              true,  false],
                                    ['Eliminar Mantenimientos',       true,  false],
                                    ['Dar de Baja Equipos',          true,  false],
                                    ['Validar / Rechazar Bajas',     true,  false],
                                    ['Gestionar Roles de Usuario',   true,  false],
                                ];
                                foreach ($permisos as [$accion, $admin, $usuario]):
                                ?>
                                <tr>
                                    <td style="font-size:13px"><?= $accion ?></td>
                                    <td style="text-align:center"><?= $admin   ? '<span style="color:var(--success)">✅</span>' : '<span style="color:var(--danger)">❌</span>' ?></td>
                                    <td style="text-align:center"><?= $usuario ? '<span style="color:var(--success)">✅</span>' : '<span style="color:var(--danger)">❌</span>' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>