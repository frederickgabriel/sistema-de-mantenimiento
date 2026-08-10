<?php
// Recibe el ID token generado por el botón de Google (Google Identity Services),
// lo valida y crea sesión iniciando sesión o registrando la cuenta automáticamente.
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$idToken = $_POST['credential'] ?? '';
if (!$idToken) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de Google.']);
    exit;
}

$payload = verificarTokenGoogle($idToken);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No se pudo verificar tu cuenta de Google.']);
    exit;
}

$correo   = trim($payload['email']);
$nombre   = trim($payload['name'] ?? '') ?: $correo;
$googleId = $payload['sub'] ?? '';

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM Usuarios WHERE correo = ? LIMIT 1");
$stmt->execute([$correo]);
$user = $stmt->fetch();

if ($user) {
    if ((int)($user['activo'] ?? 1) === 0) {
        echo json_encode(['ok' => false, 'error' => 'Tu cuenta ha sido desactivada. Contacta al administrador.']);
        exit;
    }
    if (empty($user['google_id'])) {
        $db->prepare("UPDATE Usuarios SET google_id = ? WHERE id_usuario = ?")->execute([$googleId, $user['id_usuario']]);
    }
} else {
    try {
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO Usuarios (nombre, cargo, correo, password, google_id) VALUES (?,?,?,?,?)")
           ->execute([$nombre, 'Sin especificar', $correo, $hash, $googleId]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear tu cuenta.']);
        exit;
    }
    $stmt = $db->prepare("SELECT * FROM Usuarios WHERE correo = ? LIMIT 1");
    $stmt->execute([$correo]);
    $user = $stmt->fetch();
}

$_SESSION['usuario'] = ['id' => $user['id_usuario'], 'nombre' => $user['nombre'], 'cargo' => $user['cargo'], 'correo' => $user['correo']];
echo json_encode(['ok' => true]);
