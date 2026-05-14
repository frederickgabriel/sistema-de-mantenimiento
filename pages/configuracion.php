<?php
// =============================================
// CONFIGURACIÓN DE PERFIL
// Archivo: pages/configuracion.php
// =============================================
require_once '../includes/config.php';
requireLogin();

$db  = getDB();
$msg = '';
$err = '';
$tab = $_GET['tab'] ?? 'perfil'; // perfil | seguridad | sistema

// Cargar datos actuales del usuario desde BD
$stmt = $db->prepare("SELECT * FROM Usuarios WHERE id_usuario = ?");
$stmt->execute([$_SESSION['usuario']['id']]);
$usuario = $stmt->fetch();

if (!$usuario) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

// ==========================================
// Procesar POST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Actualizar perfil ---
    if ($action === 'actualizar_perfil') {
        $nombre    = trim($_POST['nombre'] ?? '');
        $cargo     = trim($_POST['cargo'] ?? '');
        $correo    = trim($_POST['correo'] ?? '');
        $edad      = (int)($_POST['edad'] ?? 0);
        $telefono  = trim($_POST['telefono'] ?? '');
        $bio       = trim($_POST['bio'] ?? '');

        if (!$nombre || !$cargo || !$correo) {
            $err = 'Nombre, cargo y correo son obligatorios.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $err = 'El correo no es válido.';
        } else {
            try {
                // Verificar correo duplicado (de otro usuario)
                $check = $db->prepare("SELECT id_usuario FROM Usuarios WHERE correo=? AND id_usuario!=?");
                $check->execute([$correo, $usuario['id_usuario']]);
                if ($check->fetch()) {
                    $err = 'Ese correo ya está en uso por otro usuario.';
                } else {
                    $db->prepare("UPDATE Usuarios SET nombre=?, cargo=?, correo=?, edad=?, telefono=?, bio=? WHERE id_usuario=?")
                       ->execute([$nombre, $cargo, $correo, $edad ?: null, $telefono, $bio, $usuario['id_usuario']]);

                    // Actualizar sesión
                    $_SESSION['usuario']['nombre'] = $nombre;
                    $_SESSION['usuario']['cargo']  = $cargo;
                    $_SESSION['usuario']['correo'] = $correo;

                    $msg = '✅ Perfil actualizado correctamente.';
                    $tab = 'perfil';

                    // Recargar usuario
                    $stmt->execute([$usuario['id_usuario']]);
                    $usuario = $stmt->fetch();
                }
            } catch (PDOException $e) {
                $err = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }

    // --- Subir foto de perfil ---
    elseif ($action === 'subir_foto') {
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['foto'];
            $maxSize  = 3 * 1024 * 1024; // 3 MB
            $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mime     = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($file['size'] > $maxSize) {
                $err = 'La imagen no debe superar 3 MB.';
            } elseif (!in_array($mime, $allowed)) {
                $err = 'Solo se permiten imágenes JPG, PNG, WEBP o GIF.';
            } else {
                // Crear directorio de uploads si no existe
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/perfiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Eliminar foto anterior si existe
                if ($usuario['foto_perfil']) {
                    $fotoAnterior = $uploadDir . $usuario['foto_perfil'];
                    if (file_exists($fotoAnterior)) unlink($fotoAnterior);
                }

                // Generar nombre único
                $ext      = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                    default      => 'jpg'
                };
                $nombre_archivo = 'user_' . $usuario['id_usuario'] . '_' . time() . '.' . $ext;

                if (move_uploaded_file($file['tmp_name'], $uploadDir . $nombre_archivo)) {
                    $db->prepare("UPDATE Usuarios SET foto_perfil=? WHERE id_usuario=?")
                       ->execute([$nombre_archivo, $usuario['id_usuario']]);
                    $_SESSION['usuario']['foto_perfil'] = $nombre_archivo;
                    $msg = '✅ Foto de perfil actualizada.';

                    $stmt->execute([$usuario['id_usuario']]);
                    $usuario = $stmt->fetch();
                } else {
                    $err = 'Error al subir la imagen. Verifica los permisos del directorio.';
                }
            }
        } else {
            $err = 'No se recibió ninguna imagen válida.';
        }
        $tab = 'perfil';
    }

    // --- Eliminar foto ---
    elseif ($action === 'eliminar_foto') {
        if ($usuario['foto_perfil']) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/perfiles/';
            $rutaFoto  = $uploadDir . $usuario['foto_perfil'];
            if (file_exists($rutaFoto)) unlink($rutaFoto);
            $db->prepare("UPDATE Usuarios SET foto_perfil=NULL WHERE id_usuario=?")
               ->execute([$usuario['id_usuario']]);
            $_SESSION['usuario']['foto_perfil'] = null;
            $msg = '✅ Foto eliminada.';
            $stmt->execute([$usuario['id_usuario']]);
            $usuario = $stmt->fetch();
        }
        $tab = 'perfil';
    }

    // --- Cambiar contraseña ---
    elseif ($action === 'cambiar_password') {
        $actual    = $_POST['password_actual'] ?? '';
        $nueva     = $_POST['password_nueva'] ?? '';
        $confirmar = $_POST['password_confirmar'] ?? '';
        $tab       = 'seguridad';

        if (!$actual || !$nueva || !$confirmar) {
            $err = 'Completa todos los campos de contraseña.';
        } elseif (!password_verify($actual, $usuario['password'])) {
            $err = 'La contraseña actual es incorrecta.';
        } elseif (strlen($nueva) < 6) {
            $err = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif ($nueva !== $confirmar) {
            $err = 'Las contraseñas nuevas no coinciden.';
        } else {
            $hash = password_hash($nueva, PASSWORD_BCRYPT);
            $db->prepare("UPDATE Usuarios SET password=? WHERE id_usuario=?")
               ->execute([$hash, $usuario['id_usuario']]);
            $msg = '✅ Contraseña actualizada correctamente.';
        }
    }

    // Redirigir para evitar reenvío de formulario
    if ($msg || $err) {
        $param = $msg ? 'msg=' . urlencode($msg) : 'err=' . urlencode($err);
        header("Location: /pages/configuracion.php?tab={$tab}&{$param}");
        exit;
    }
}

// Leer mensajes de redirección
if (isset($_GET['msg'])) $msg = $_GET['msg'];
if (isset($_GET['err'])) $err = $_GET['err'];

// URL de la foto de perfil
function fotoURL(?string $foto): string {
    if ($foto) return '/uploads/perfiles/' . $foto;
    return ''; // sin foto → se muestra inicial
}

$iniciales = strtoupper(implode('', array_map(fn($p) => $p[0], explode(' ', trim($usuario['nombre'])))));
$iniciales  = substr($iniciales, 0, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
    <style>
        /* ---- Layout configuración ---- */
        .config-layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 28px;
            align-items: start;
        }

        /* Panel de navegación lateral */
        .config-nav {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            position: sticky;
            top: 24px;
        }

        .config-nav-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            text-align: center;
        }

        /* Avatar de perfil */
        .avatar-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 12px;
        }

        .avatar-img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border);
            display: block;
        }

        .avatar-initials {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-dark), var(--purple));
            color: #fff;
            font-size: 26px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid var(--border);
            font-family: var(--font-mono);
        }

        .avatar-badge {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            background: var(--success);
            border-radius: 50%;
            border: 2px solid var(--bg-card);
        }

        .config-nav-name {
            font-weight: 700;
            font-size: 15px;
            color: var(--text-primary);
        }

        .config-nav-role {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .config-nav-menu {
            list-style: none;
            padding: 8px;
        }

        .config-nav-menu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all .2s;
        }

        .config-nav-menu li a:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .config-nav-menu li a.active {
            background: var(--accent-glow);
            color: var(--accent);
        }

        .config-nav-menu .nav-icon { font-size: 16px; width: 20px; text-align: center; }

        /* Contenido principal */
        .config-content { min-width: 0; }

        .config-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .config-section-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .config-section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .config-section-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .config-section-body { padding: 22px; }

        /* Zona de foto */
        .foto-zona {
            display: flex;
            align-items: center;
            gap: 24px;
            padding: 20px;
            background: var(--bg-main);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px dashed var(--border);
        }

        .foto-preview-wrap {
            flex-shrink: 0;
        }

        .foto-preview {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            display: block;
        }

        .foto-initials-big {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-dark), var(--purple));
            color: #fff;
            font-size: 30px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid var(--accent);
            font-family: var(--font-mono);
        }

        .foto-info { flex: 1; }
        .foto-info p { font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.6; }

        .foto-btns { display: flex; gap: 10px; flex-wrap: wrap; }

        /* Input file oculto */
        .input-file-hidden { display: none; }

        /* Preview en vivo */
        #previewImg {
            display: none;
        }

        /* Separador de sección */
        .divider {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 20px 0;
        }

        /* Info item (solo lectura) */
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-size: 13px; color: var(--text-secondary); }
        .info-value { font-size: 13px; color: var(--text-primary); font-weight: 500; font-family: var(--font-mono); }

        /* Password strength */
        .pass-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 6px;
            background: var(--border);
            overflow: hidden;
        }
        .pass-strength-fill {
            height: 100%;
            border-radius: 2px;
            transition: all .3s;
            width: 0;
        }
        .pass-hint {
            font-size: 11px;
            margin-top: 4px;
            color: var(--text-muted);
        }

        @media (max-width: 768px) {
            .config-layout { grid-template-columns: 1fr; }
            .config-nav { position: static; }
        }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <div class="page-title">⚙ Configuración</div>
                <div class="page-subtitle">Gestiona tu perfil y preferencias del sistema</div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= e($msg) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="alert alert-error"><?= e($err) ?></div>
        <?php endif; ?>

        <div class="config-layout">

            <!-- ============================
                 NAV LATERAL DE CONFIGURACIÓN
                 ============================ -->
            <div class="config-nav">
                <div class="config-nav-header">
                    <div class="avatar-wrap">
                        <?php if ($usuario['foto_perfil'] ?? null): ?>
                            <img src="/uploads/perfiles/<?= e($usuario['foto_perfil']) ?>"
                                 alt="Foto" class="avatar-img">
                        <?php else: ?>
                            <div class="avatar-initials"><?= e($iniciales) ?></div>
                        <?php endif; ?>
                        <div class="avatar-badge"></div>
                    </div>
                    <div class="config-nav-name"><?= e($usuario['nombre']) ?></div>
                    <div class="config-nav-role"><?= e($usuario['cargo']) ?></div>
                </div>
                <ul class="config-nav-menu">
                    <li>
                        <a href="?tab=perfil" class="<?= $tab === 'perfil' ? 'active' : '' ?>">
                            <span class="nav-icon">👤</span> Mi Perfil
                        </a>
                    </li>
                    <li>
                        <a href="?tab=seguridad" class="<?= $tab === 'seguridad' ? 'active' : '' ?>">
                            <span class="nav-icon">🔒</span> Seguridad
                        </a>
                    </li>
                    <li>
                        <a href="?tab=cuenta" class="<?= $tab === 'cuenta' ? 'active' : '' ?>">
                            <span class="nav-icon">ℹ</span> Info de Cuenta
                        </a>
                    </li>
                </ul>
            </div>

            <!-- ============================
                 CONTENIDO POR PESTAÑA
                 ============================ -->
            <div class="config-content">

                <?php if ($tab === 'perfil'): ?>
                <!-- ========================
                     PESTAÑA: MI PERFIL
                     ======================== -->

                <!-- Foto de perfil -->
                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">🖼 Foto de Perfil</div>
                        <div class="config-section-sub">Se mostrará en el menú lateral y en los documentos generados</div>
                    </div>
                    <div class="config-section-body">
                        <div class="foto-zona">
                            <div class="foto-preview-wrap">
                                <?php if ($usuario['foto_perfil'] ?? null): ?>
                                    <img src="/uploads/perfiles/<?= e($usuario['foto_perfil']) ?>"
                                         alt="Foto de perfil" class="foto-preview" id="fotoActual">
                                    <img src="" class="foto-preview" id="previewImg">
                                <?php else: ?>
                                    <div class="foto-initials-big" id="fotoActualInitials"><?= e($iniciales) ?></div>
                                    <img src="" class="foto-preview" id="previewImg">
                                <?php endif; ?>
                            </div>
                            <div class="foto-info">
                                <p>
                                    Sube una foto de perfil. Se aceptan imágenes <strong>JPG, PNG, WEBP o GIF</strong>
                                    de hasta <strong>3 MB</strong>. Se recomienda una imagen cuadrada de al menos 200×200 px.
                                </p>
                                <div class="foto-btns">
                                    <!-- Subir foto -->
                                    <form method="POST" action="/pages/configuracion.php"
                                          enctype="multipart/form-data" id="formFoto">
                                        <input type="hidden" name="action" value="subir_foto">
                                        <input type="file" name="foto" id="inputFoto"
                                               class="input-file-hidden" accept="image/*">
                                        <button type="button" class="btn btn-primary btn-sm"
                                                onclick="document.getElementById('inputFoto').click()">
                                            📷 Subir Foto
                                        </button>
                                    </form>
                                    <!-- Eliminar foto -->
                                    <?php if ($usuario['foto_perfil'] ?? null): ?>
                                    <form method="POST" action="/pages/configuracion.php"
                                          onsubmit="return confirm('¿Eliminar tu foto de perfil?')">
                                        <input type="hidden" name="action" value="eliminar_foto">
                                        <button type="submit" class="btn btn-ghost btn-sm">🗑 Eliminar foto</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Datos personales -->
                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">✏ Datos Personales</div>
                        <div class="config-section-sub">Actualiza tu nombre, correo y demás información</div>
                    </div>
                    <div class="config-section-body">
                        <form method="POST" action="/pages/configuracion.php">
                            <input type="hidden" name="action" value="actualizar_perfil">

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Nombre Completo *</label>
                                    <input type="text" name="nombre"
                                           value="<?= e($usuario['nombre']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Cargo / Puesto *</label>
                                    <input type="text" name="cargo"
                                           value="<?= e($usuario['cargo']) ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Correo Electrónico *</label>
                                    <input type="email" name="correo"
                                           value="<?= e($usuario['correo']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Edad</label>
                                    <input type="number" name="edad" min="15" max="99"
                                           value="<?= e($usuario['edad'] ?? '') ?>" placeholder="25">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Teléfono / Extensión</label>
                                <input type="text" name="telefono"
                                       value="<?= e($usuario['telefono'] ?? '') ?>"
                                       placeholder="Ej: 998 123 4567 ext. 101">
                            </div>

                            <div class="form-group">
                                <label>Descripción / Bio</label>
                                <textarea name="bio" rows="3"
                                          placeholder="Una breve descripción de tu rol o área de especialidad..."><?= e($usuario['bio'] ?? '') ?></textarea>
                            </div>

                            <div style="display:flex;justify-content:flex-end">
                                <button type="submit" class="btn btn-primary">💾 Guardar Cambios</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php elseif ($tab === 'seguridad'): ?>
                <!-- ========================
                     PESTAÑA: SEGURIDAD
                     ======================== -->

                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">🔑 Cambiar Contraseña</div>
                        <div class="config-section-sub">Usa una contraseña segura de al menos 6 caracteres</div>
                    </div>
                    <div class="config-section-body">
                        <form method="POST" action="/pages/configuracion.php?tab=seguridad">
                            <input type="hidden" name="action" value="cambiar_password">

                            <div class="form-group">
                                <label>Contraseña Actual *</label>
                                <div class="input-group">
                                    <input type="password" name="password_actual" id="passActual"
                                           placeholder="••••••••" required>
                                    <button type="button" class="toggle-pass"
                                            onclick="togglePass('passActual',this)">👁</button>
                                </div>
                            </div>

                            <hr class="divider">

                            <div class="form-group">
                                <label>Nueva Contraseña *</label>
                                <div class="input-group">
                                    <input type="password" name="password_nueva" id="passNueva"
                                           placeholder="••••••••" required
                                           oninput="evaluarPass(this.value)">
                                    <button type="button" class="toggle-pass"
                                            onclick="togglePass('passNueva',this)">👁</button>
                                </div>
                                <div class="pass-strength">
                                    <div class="pass-strength-fill" id="passStrengthBar"></div>
                                </div>
                                <div class="pass-hint" id="passHint">Escribe una contraseña</div>
                            </div>

                            <div class="form-group">
                                <label>Confirmar Nueva Contraseña *</label>
                                <div class="input-group">
                                    <input type="password" name="password_confirmar" id="passConfirm"
                                           placeholder="••••••••" required
                                           oninput="verificarMatch()">
                                    <button type="button" class="toggle-pass"
                                            onclick="togglePass('passConfirm',this)">👁</button>
                                </div>
                                <div class="pass-hint" id="matchHint"></div>
                            </div>

                            <div style="background:var(--bg-main);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:18px">
                                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:6px;font-weight:600">Recomendaciones de seguridad:</p>
                                <ul style="font-size:12px;color:var(--text-muted);padding-left:16px;line-height:1.8">
                                    <li>Mínimo 8 caracteres</li>
                                    <li>Combina mayúsculas, minúsculas y números</li>
                                    <li>Incluye al menos un símbolo (!@#$%)</li>
                                    <li>No uses tu nombre ni fecha de nacimiento</li>
                                </ul>
                            </div>

                            <div style="display:flex;justify-content:flex-end">
                                <button type="submit" class="btn btn-primary">🔒 Actualizar Contraseña</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sesión activa -->
                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">🌐 Sesión Activa</div>
                        <div class="config-section-sub">Información de tu sesión actual</div>
                    </div>
                    <div class="config-section-body">
                        <div class="info-row">
                            <span class="info-label">ID de Usuario</span>
                            <span class="info-value">#<?= e($usuario['id_usuario']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Correo de sesión</span>
                            <span class="info-value"><?= e($usuario['correo']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Fecha de registro</span>
                            <span class="info-value"><?= fechaES(date('Y-m-d', strtotime($usuario['fecha_registro']))) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Estado</span>
                            <span class="badge-estado badge-activo">● Sesión Activa</span>
                        </div>
                        <div style="margin-top:16px">
                            <a href="/actions/logout.php" class="btn btn-danger btn-sm">⏻ Cerrar Sesión</a>
                        </div>
                    </div>
                </div>

                <?php elseif ($tab === 'cuenta'): ?>
                <!-- ========================
                     PESTAÑA: INFO DE CUENTA
                     ======================== -->

                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">ℹ Información de la Cuenta</div>
                        <div class="config-section-sub">Datos de registro y actividad en el sistema</div>
                    </div>
                    <div class="config-section-body">
                        <div class="info-row">
                            <span class="info-label">ID de Usuario</span>
                            <span class="info-value">#<?= e($usuario['id_usuario']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Nombre completo</span>
                            <span class="info-value"><?= e($usuario['nombre']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Correo electrónico</span>
                            <span class="info-value"><?= e($usuario['correo']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Cargo / Puesto</span>
                            <span class="info-value"><?= e($usuario['cargo']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Edad</span>
                            <span class="info-value"><?= $usuario['edad'] ? e($usuario['edad']) . ' años' : '—' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Teléfono</span>
                            <span class="info-value"><?= e($usuario['telefono'] ?? '—') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Foto de perfil</span>
                            <span class="info-value"><?= ($usuario['foto_perfil'] ?? null) ? '✅ Configurada' : '— Sin foto' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Fecha de registro</span>
                            <span class="info-value"><?= fechaES(date('Y-m-d', strtotime($usuario['fecha_registro']))) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Actividad en el sistema -->
                <?php
                $misMantenimientos = $db->prepare("SELECT COUNT(*) FROM Mantenimientos WHERE id_tecnico=?");
                $misMantenimientos->execute([$usuario['id_usuario']]);
                $misMantenimientos = $misMantenimientos->fetchColumn();

                $misTareas = $db->prepare("SELECT COUNT(*) FROM Tareas WHERE id_usuario_asignado=?");
                $misTareas->execute([$usuario['id_usuario']]);
                $misTareas = $misTareas->fetchColumn();

                $misBajas = $db->prepare("SELECT COUNT(*) FROM Bajas WHERE id_tecnico_responsable=?");
                $misBajas->execute([$usuario['id_usuario']]);
                $misBajas = $misBajas->fetchColumn();
                ?>
                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">📊 Mi Actividad en el Sistema</div>
                        <div class="config-section-sub">Resumen de tu participación</div>
                    </div>
                    <div class="config-section-body">
                        <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:0">
                            <div class="stat-card">
                                <div class="stat-label">Mantenimientos</div>
                                <div class="stat-value accent"><?= $misMantenimientos ?></div>
                                <div class="stat-meta">realizados por mí</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Tareas Asignadas</div>
                                <div class="stat-value warning"><?= $misTareas ?></div>
                                <div class="stat-meta">a mi usuario</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label">Bajas Registradas</div>
                                <div class="stat-value danger"><?= $misBajas ?></div>
                                <div class="stat-meta">por mí</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bio -->
                <?php if ($usuario['bio'] ?? null): ?>
                <div class="config-section">
                    <div class="config-section-header">
                        <div class="config-section-title">📝 Descripción / Bio</div>
                    </div>
                    <div class="config-section-body">
                        <p style="color:var(--text-secondary);font-size:14px;line-height:1.7">
                            <?= nl2br(e($usuario['bio'])) ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>

            </div><!-- /config-content -->
        </div><!-- /config-layout -->

    </main>
</div>

<script>
// ---- Preview foto en tiempo real ----
document.getElementById('inputFoto')?.addEventListener('change', function() {
    if (!this.files || !this.files[0]) return;
    const file   = this.files[0];
    const reader = new FileReader();
    reader.onload = (e) => {
        const preview = document.getElementById('previewImg');
        preview.src   = e.target.result;
        preview.style.display = 'block';
        // Ocultar lo anterior
        document.getElementById('fotoActual')?.style.setProperty('display','none');
        document.getElementById('fotoActualInitials')?.style.setProperty('display','none');
        // Auto-submit
        document.getElementById('formFoto').submit();
    };
    reader.readAsDataURL(file);
});

// ---- Toggle contraseña ----
function togglePass(id, btn) {
    const input = document.getElementById(id);
    input.type  = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

// ---- Evaluador de fortaleza de contraseña ----
function evaluarPass(val) {
    const bar  = document.getElementById('passStrengthBar');
    const hint = document.getElementById('passHint');
    if (!bar) return;

    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const niveles = [
        { pct:  '0%', color: 'var(--border)', label: 'Escribe una contraseña' },
        { pct: '20%', color: 'var(--danger)',  label: '⚠ Muy débil' },
        { pct: '40%', color: 'var(--danger)',  label: '⚠ Débil' },
        { pct: '60%', color: 'var(--warning)', label: '👍 Aceptable' },
        { pct: '80%', color: 'var(--accent)',  label: '✅ Fuerte' },
        { pct:'100%', color: 'var(--success)', label: '🔒 Muy fuerte' },
    ];

    const n = niveles[val.length === 0 ? 0 : score] || niveles[score];
    bar.style.width      = n.pct;
    bar.style.background = n.color;
    hint.textContent     = n.label;
    hint.style.color     = n.color;
}

// ---- Verificar que las contraseñas coincidan ----
function verificarMatch() {
    const nueva    = document.getElementById('passNueva')?.value;
    const confirma = document.getElementById('passConfirm')?.value;
    const hint     = document.getElementById('matchHint');
    if (!hint || !confirma) return;

    if (nueva === confirma) {
        hint.textContent = '✅ Las contraseñas coinciden';
        hint.style.color = 'var(--success)';
    } else {
        hint.textContent = '❌ No coinciden';
        hint.style.color = 'var(--danger)';
    }
}
</script>

</body>
</html>