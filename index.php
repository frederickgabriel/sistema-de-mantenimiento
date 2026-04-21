<?php
// =============================================
// PÁGINA DE LOGIN Y REGISTRO
// Archivo: index.php
// =============================================
require_once 'includes/config.php';
redirectIfLoggedIn();

$error   = '';
$success = '';
$tab     = 'login'; // pestaña activa por defecto

// ==========================================
// Procesar REGISTRO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'registro') {
    $tab      = 'registro';
    $nombre   = trim($_POST['nombre'] ?? '');
    $cargo    = trim($_POST['cargo'] ?? '');
    $correo   = trim($_POST['correo'] ?? '');
    $edad     = (int)($_POST['edad'] ?? 0);
    $password = $_POST['password'] ?? '';

    if (!$nombre || !$cargo || !$correo || !$password) {
        $error = 'Por favor completa todos los campos obligatorios.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            $db   = getDB();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO Usuarios (nombre, cargo, correo, edad, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $cargo, $correo, $edad ?: null, $hash]);
            $success = '¡Cuenta creada exitosamente! Ya puedes iniciar sesión.';
            $tab     = 'login';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Ese correo ya está registrado en el sistema.';
            } else {
                $error = 'Error al registrar. Intenta de nuevo.';
            }
        }
    }
}

// ==========================================
// Procesar LOGIN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $correo   = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$correo || !$password) {
        $error = 'Ingresa tu correo y contraseña.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM Usuarios WHERE correo = ? LIMIT 1");
            $stmt->execute([$correo]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['usuario'] = [
                    'id'     => $user['id_usuario'],
                    'nombre' => $user['nombre'],
                    'cargo'  => $user['cargo'],
                    'correo' => $user['correo'],
                ];
                header('Location: /pages/dashboard.php');
                exit;
            } else {
                $error = 'Correo o contraseña incorrectos.';
            }
        } catch (PDOException $e) {
            $error = 'Error de conexión. Contacta al administrador.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>

<div class="login-wrapper">

    <!-- Panel izquierdo decorativo -->
    <div class="login-left">
        <div class="login-grid-bg"></div>
        <div class="login-logo">🖥</div>
        <div class="login-brand">TechCare</div>
        <p class="login-tagline">
            Sistema institucional para la gestión y seguimiento del mantenimiento de equipos de cómputo.
        </p>
        <div style="margin-top:40px; display:flex; gap:20px; flex-wrap:wrap; justify-content:center;">
            <div style="text-align:center;">
                <div style="font-family:var(--font-mono);font-size:28px;color:var(--accent);">45+</div>
                <div style="font-size:12px;color:var(--text-muted);">Equipos</div>
            </div>
            <div style="text-align:center;">
                <div style="font-family:var(--font-mono);font-size:28px;color:var(--success);">100%</div>
                <div style="font-size:12px;color:var(--text-muted);">Trazabilidad</div>
            </div>
            <div style="text-align:center;">
                <div style="font-family:var(--font-mono);font-size:28px;color:var(--warning);">24/7</div>
                <div style="font-size:12px;color:var(--text-muted);">Alertas</div>
            </div>
        </div>
    </div>

    <!-- Panel derecho: formulario -->
    <div class="login-right">
        <div class="login-card">
            <h2>Bienvenido al sistema</h2>
            <p>Ingresa tus credenciales para continuar</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tab-nav" id="tabNav">
                <button class="tab-btn <?= $tab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Iniciar Sesión</button>
                <button class="tab-btn <?= $tab === 'registro' ? 'active' : '' ?>" onclick="switchTab('registro')">Registrarse</button>
            </div>

            <!-- Tab: Login -->
            <div class="tab-pane <?= $tab === 'login' ? 'active' : '' ?>" id="tab-login">
                <form method="POST" action="/index.php">
                    <input type="hidden" name="action" value="login">

                    <div class="form-group">
                        <label for="loginCorreo">Correo Electrónico</label>
                        <input type="email" id="loginCorreo" name="correo" 
                               value="<?= $tab === 'login' ? e($_POST['correo'] ?? '') : '' ?>"
                               placeholder="usuario@correo.com" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="loginPass">Contraseña</label>
                        <div class="input-group">
                            <input type="password" id="loginPass" name="password" placeholder="••••••••" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('loginPass', this)">👁</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Ingresar al Sistema</button>
                </form>
            </div>

            <!-- Tab: Registro -->
            <div class="tab-pane <?= $tab === 'registro' ? 'active' : '' ?>" id="tab-registro">
                <form method="POST" action="/index.php">
                    <input type="hidden" name="action" value="registro">

                    <div class="form-group">
                        <label>Nombre Completo *</label>
                        <input type="text" name="nombre" value="<?= e($_POST['nombre'] ?? '') ?>" placeholder="Ej: Juan Pérez López" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Cargo *</label>
                            <input type="text" name="cargo" value="<?= e($_POST['cargo'] ?? '') ?>" placeholder="Técnico, Admin..." required>
                        </div>
                        <div class="form-group">
                            <label>Edad</label>
                            <input type="number" name="edad" value="<?= e($_POST['edad'] ?? '') ?>" min="15" max="99" placeholder="25">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Correo Electrónico *</label>
                        <input type="email" name="correo" value="<?= e($_POST['correo'] ?? '') ?>" placeholder="correo@ejemplo.com" required>
                    </div>

                    <div class="form-group">
                        <label>Contraseña * (mínimo 6 caracteres)</label>
                        <div class="input-group">
                            <input type="password" id="regPass" name="password" placeholder="••••••••" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('regPass', this)">👁</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-full">Crear Cuenta</button>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}

function togglePass(id, btn) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}
</script>

</body>
</html>
