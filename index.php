<?php
require_once 'includes/config.php';
redirectIfLoggedIn();

$error = $success = '';
$tab   = 'login';
if (isset($_GET['err'])) $error = $_GET['err'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'registro') {
        $tab      = 'registro';
        $nombre   = trim($_POST['nombre']   ?? '');
        $cargo    = trim($_POST['cargo']    ?? '');
        $correo   = trim($_POST['correo']   ?? '');
        $edad     = (int)($_POST['edad']    ?? 0);
        $password = $_POST['password']      ?? '';

        if (!$nombre || !$cargo || !$correo || !$password) {
            $error = 'Completa todos los campos obligatorios.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo no es válido.';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                getDB()->prepare("INSERT INTO Usuarios (nombre, cargo, correo, edad, password) VALUES (?,?,?,?,?)")
                       ->execute([$nombre, $cargo, $correo, $edad ?: null, $hash]);
                $success = '¡Cuenta creada! Ya puedes iniciar sesión.';
                $tab     = 'login';
            } catch (PDOException $e) {
                $error = ($e->getCode() == 23000) ? 'Ese correo ya está registrado.' : 'Error al registrar.';
            }
        }
    }

    if ($action === 'login') {
        $correo   = trim($_POST['correo']   ?? '');
        $password = $_POST['password']      ?? '';
        if (!$correo || !$password) {
            $error = 'Ingresa correo y contraseña.';
        } else {
            $stmt = getDB()->prepare("SELECT * FROM Usuarios WHERE correo=? LIMIT 1");
            $stmt->execute([$correo]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Correo o contraseña incorrectos.';
            } elseif ((int)($user['activo'] ?? 1) === 0) {
                $error = 'Tu cuenta ha sido desactivada. Contacta al administrador.';
            } else {
                $_SESSION['usuario'] = ['id' => $user['id_usuario'], 'nombre' => $user['nombre'], 'cargo' => $user['cargo'], 'correo' => $user['correo']];
                header('Location: /pages/dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zilara TechCare — Acceso al Sistema</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Work+Sans:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="/css/estilos.css?v=9">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>

<div class="login-wrapper">

    <!-- Panel izquierdo: Logo Zilara TechCare -->
    <div class="login-left">
        <img src="img/hytta.png" alt="Zilara TechCare" class="login-logo-img">
        <p class="login-tagline">Sistema de Administración y Seguimiento<br>del Mantenimiento de Equipos de Cómputo</p>
        <div class="login-hotel">Hyatt Zilara</div>
        <div class="login-hotel-sub">Riviera Maya</div>
    </div>

    <!-- Panel derecho: Formulario -->
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

            <div class="tab-nav">
                <button class="tab-btn <?= $tab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Iniciar Sesión</button>
                <button class="tab-btn <?= $tab === 'registro' ? 'active' : '' ?>" onclick="switchTab('registro')">Registrarse</button>
            </div>

            <!-- Tab: Login -->
            <div class="tab-pane <?= $tab === 'login' ? 'active' : '' ?>" id="tab-login">
                <form method="POST" action="/index.php">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>Correo Electrónico</label>
                        <input type="email" name="correo"
                               value="<?= $tab === 'login' ? e($_POST['correo'] ?? '') : '' ?>"
                               placeholder="usuario@correo.com" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Contraseña</label>
                        <div class="input-group">
                            <input type="password" id="loginPass" name="password" placeholder="••••••••" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('loginPass', this)"><span class="material-symbols-outlined mi-sm">visibility</span></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px">
                        Ingresar al Sistema
                    </button>
                </form>
            </div>

            <!-- Tab: Registro -->
            <div class="tab-pane <?= $tab === 'registro' ? 'active' : '' ?>" id="tab-registro">
                <form method="POST" action="/index.php">
                    <input type="hidden" name="action" value="registro">
                    <div class="form-group">
                        <label>Nombre Completo *</label>
                        <input type="text" name="nombre"
                               value="<?= e($_POST['nombre'] ?? '') ?>"
                               placeholder="Ej: Juan Pérez López" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Cargo *</label>
                            <input type="text" name="cargo"
                                   value="<?= e($_POST['cargo'] ?? '') ?>"
                                   placeholder="Técnico, Admin..." required>
                        </div>
                        <div class="form-group">
                            <label>Edad</label>
                            <input type="number" name="edad"
                                   value="<?= e($_POST['edad'] ?? '') ?>"
                                   min="15" max="99" placeholder="25">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico *</label>
                        <input type="email" name="correo"
                               value="<?= e($_POST['correo'] ?? '') ?>"
                               placeholder="correo@ejemplo.com" required>
                    </div>
                    <div class="form-group">
                        <label>Contraseña * (mínimo 6 caracteres)</label>
                        <div class="input-group">
                            <input type="password" id="regPass" name="password" placeholder="••••••••" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('regPass', this)"><span class="material-symbols-outlined mi-sm">visibility</span></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-full" style="margin-top:8px">
                        Crear cuenta
                    </button>
                </form>
            </div>

            <div class="google-divider"><span>o continúa con</span></div>

            <div id="g_id_onload"
                 data-client_id="<?= e(GOOGLE_CLIENT_ID) ?>"
                 data-callback="handleGoogleLogin"
                 data-auto_prompt="false">
            </div>
            <div class="g_id_signin" style="display:flex;justify-content:center"
                 data-type="standard" data-size="large" data-theme="outline"
                 data-text="signin_with" data-shape="rectangular" data-width="320">
            </div>

        </div>
    </div>
</div>

<script>
async function handleGoogleLogin(response) {
    try {
        const res  = await fetch('/actions/google_login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'credential=' + encodeURIComponent(response.credential)
        });
        const data = await res.json();
        if (data.ok) {
            window.location.href = '/pages/dashboard.php';
        } else {
            window.location.href = '/index.php?err=' + encodeURIComponent(data.error || 'No se pudo iniciar sesión con Google.');
        }
    } catch (e) {
        window.location.href = '/index.php?err=' + encodeURIComponent('Error de conexión con Google.');
    }
}
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}
function togglePass(id, btn) {
    const input = document.getElementById(id);
    input.type  = input.type === 'password' ? 'text' : 'password';
    btn.querySelector('span').textContent = input.type === 'password' ? 'visibility' : 'visibility_off';
}
</script>

</body>
</html>