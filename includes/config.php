<?php
// =============================================
// CONFIGURACIÓN DE BASE DE DATOS
// Archivo: includes/config.php
// =============================================

require_once __DIR__ . '/../vendor/autoload.php';

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '2004');  // <-- Cambia esto
define('DB_NAME', 'sistema_mantenimiento');
define('SITE_NAME', 'Gestión de Mantenimiento');
date_default_timezone_set('America/Mexico_City');
 
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;color:#cf222e;background:#f5f6fa">Error de conexión a la base de datos: ' . $e->getMessage() . '</div>');
        }
    }
    return $pdo;
}
 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
function requireLogin(): void {
    if (!isset($_SESSION['usuario'])) {
        header('Location: /index.php');
        exit;
    }
    $stmt = getDB()->prepare("SELECT activo FROM Usuarios WHERE id_usuario=?");
    $stmt->execute([$_SESSION['usuario']['id']]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['activo'] === 0) {
        session_destroy();
        header('Location: /index.php?err=' . urlencode('Tu cuenta ha sido desactivada. Contacta al administrador.'));
        exit;
    }
}
 
function redirectIfLoggedIn(): void {
    if (isset($_SESSION['usuario'])) {
        header('Location: /pages/dashboard.php');
        exit;
    }
}
 
function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

// Renderiza un mensaje flash ($msg/$err) sustituyendo su emoji inicial por un ícono Material Symbols
function renderMsg(?string $msg): string {
    if (!$msg) return '';
    $map = ['✅' => 'check_circle', '❌' => 'cancel', '🚫' => 'block', '🗑' => 'delete', '⚠' => 'warning'];
    foreach ($map as $emoji => $icon) {
        if (str_starts_with($msg, $emoji)) {
            $resto = trim(mb_substr($msg, mb_strlen($emoji)));
            return '<span class="material-symbols-outlined mi-sm">' . $icon . '</span> ' . e($resto);
        }
    }
    return e($msg);
}
 
function fechaES(?string $fecha): string {
    if (!$fecha) return '—';
    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ts = strtotime($fecha);
    return date('d', $ts) . ' ' . $meses[(int)date('m', $ts)] . ' ' . date('Y', $ts);
}
 
function badgeEstado(string $estado): string {
    $map = ['Activo' => 'badge-activo', 'Inactivo' => 'badge-inactivo', 'En Reparacion' => 'badge-reparacion', 'Baja' => 'badge-no-realizado'];
    $css = $map[$estado] ?? 'badge-inactivo';
    return "<span class=\"badge-estado {$css}\">" . e($estado) . "</span>";
}
 
function badgeTarea(string $estado): string {
    $map   = ['Pendiente' => 'badge-pendiente', 'En Proceso' => 'badge-proceso', 'Realizado' => 'badge-realizado', 'No Realizado' => 'badge-no-realizado'];
    $icons = ['Pendiente' => 'hourglass_empty', 'En Proceso' => 'autorenew', 'Realizado' => 'check_circle', 'No Realizado' => 'cancel'];
    $css   = $map[$estado]   ?? 'badge-pendiente';
    $ico   = $icons[$estado] ?? '';
    $icoHtml = $ico ? "<span class=\"material-symbols-outlined mi-xs\">{$ico}</span> " : '';
    return "<span class=\"badge-estado {$css}\">{$icoHtml}" . e($estado) . "</span>";
}

// Ruta pública de una foto de perfil (o null si el usuario no tiene)
function rutaFoto(?string $foto): ?string {
    return $foto ? '/uploads/perfiles/' . $foto : null;
}

// Avatar circular clicable (abre el lightbox) para mostrar la foto de un usuario en listados;
// si no tiene foto, muestra un círculo con su inicial.
function avatarChip(?string $foto, string $nombre, int $size = 30): string {
    $inicial = e(mb_strtoupper(mb_substr($nombre, 0, 1)));
    $px      = $size . 'px';
    if ($foto) {
        $src = e(rutaFoto($foto));
        return "<img src=\"{$src}\" alt=\"{$inicial}\" class=\"avatar-chip\" style=\"width:{$px};height:{$px}\" onclick=\"abrirLightbox('{$src}')\">";
    }
    $fontSize = max(11, (int)round($size * 0.4)) . 'px';
    return "<span class=\"avatar-chip avatar-chip-initial\" style=\"width:{$px};height:{$px};font-size:{$fontSize}\">{$inicial}</span>";
}

// Guarda hasta $max fotos (evidencia de equipo "antes de abrirlo") ligadas a un Mantenimiento o una Tarea.
// Reutiliza las mismas reglas de validación que la foto de perfil (jpg/png/webp/gif, 3MB máx).
function guardarEvidencias(PDO $db, array $files, string $origen, int $idOrigen, ?string $inventario, int $idUsuario, int $max = 5): array {
    $errores = [];
    if (empty($files['name']) || !is_array($files['name'])) return $errores;

    $nombres = array_filter($files['name'], fn($n) => $n !== '');
    if (empty($nombres)) return $errores;
    if (count($nombres) > $max) $errores[] = "Solo se permiten {$max} fotos por registro; se subieron las primeras {$max}.";

    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/evidencias/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $maxSize = 3 * 1024 * 1024;
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $subidas = 0;

    foreach ($files['name'] as $i => $nombreOriginal) {
        if ($subidas >= $max) break;
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $nombreOriginal === '') continue;

        $tmp  = $files['tmp_name'][$i];
        $mime = finfo_file($finfo, $tmp);

        if ($files['size'][$i] > $maxSize) {
            $errores[] = "«{$nombreOriginal}» supera 3 MB y no se subió.";
            continue;
        }
        if (!isset($allowed[$mime])) {
            $errores[] = "«{$nombreOriginal}» no es una imagen válida (JPG, PNG, WEBP o GIF).";
            continue;
        }

        $nombreArchivo = strtolower($origen) . '_' . $idOrigen . '_' . ($subidas + 1) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
        if (move_uploaded_file($tmp, $uploadDir . $nombreArchivo)) {
            $db->prepare("INSERT INTO EvidenciasEquipo (origen,id_origen,numero_inventario,id_usuario,ruta_imagen) VALUES (?,?,?,?,?)")
               ->execute([$origen, $idOrigen, $inventario, $idUsuario, $nombreArchivo]);
            $subidas++;
        } else {
            $errores[] = "Error al guardar «{$nombreOriginal}».";
        }
    }
    finfo_close($finfo);
    return $errores;
}

// =============================================
// CONFIGURACIÓN DE ROLES
// =============================================
define('ADMIN_EMAIL', 'frederickaguilar317@gmail.com');

// =============================================
// CONFIGURACIÓN DE GOOGLE SIGN-IN
// =============================================
// Crea un "OAuth client ID" de tipo "Aplicación web" en:
// https://console.cloud.google.com/apis/credentials
// y pega aquí el Client ID (termina en .apps.googleusercontent.com).
define('GOOGLE_CLIENT_ID', '622263573317-j9qv78q5jdeoebn9qcvr1g7m322evpp9.apps.googleusercontent.com');

// Verifica un ID token emitido por Google Identity Services y devuelve su payload
// (correo, nombre, sub, etc.) solo si es válido para este Client ID; null si no.
function verificarTokenGoogle(string $idToken): ?array {
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $ch  = curl_init($url);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    // Algunos entornos de Windows (XAMPP/Laragon) no traen configurado curl.cainfo en php.ini,
    // lo que hace fallar la verificación SSL (curl error 60). Usamos el bundle de Mozilla incluido
    // en el proyecto para no depender de esa configuración del servidor.
    $bundleCA = __DIR__ . '/cacert.pem';
    if (is_file($bundleCA)) {
        $opciones[CURLOPT_CAINFO] = $bundleCA;
    }
    curl_setopt_array($ch, $opciones);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('verificarTokenGoogle: fallo de cURL - ' . $curlError);
        return null;
    }
    if ($httpCode !== 200) return null;

    $payload = json_decode($response, true);
    if (!is_array($payload)) return null;

    $issuerValido     = in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
    $audienciaValida  = ($payload['aud'] ?? '') === GOOGLE_CLIENT_ID;
    $correoVerificado = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$issuerValido || !$audienciaValida || !$correoVerificado || empty($payload['email'])) {
        return null;
    }
    return $payload;
}

// =============================================
// CONFIGURACIÓN SMTP (envío de correo con PHPMailer)
// =============================================
// Genera una "contraseña de aplicación" en https://myaccount.google.com/apppasswords
// (requiere verificación en 2 pasos activada en la cuenta de Gmail) y pégala en SMTP_PASS.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'frederickaguilar317@gmail.com');
define('SMTP_PASS', 'bmtv qfxo jetw rdbg'); // <-- Pega aquí tu contraseña de aplicación de Gmail (16 caracteres, sin espacios)
define('SMTP_FROM_NAME', 'Sistema ManteTech');

// Verificar si el usuario actual es admin
function esAdmin(): bool {
    return ($_SESSION['usuario']['rol'] ?? 'usuario') === 'admin';
}
 
// Redirigir si no es admin
function requireAdmin(): void {
    requireLogin();
    if (!esAdmin()) {
        header('Location: /pages/dashboard.php?err=sin_permiso');
        exit;
    }
}
 
// Badge de rol
function badgeRol(string $rol): string {
    if ($rol === 'admin') {
        return '<span class="badge-estado" style="background:rgba(139,92,246,.15);color:var(--purple);border:1px solid rgba(139,92,246,.35)"><span class="material-symbols-outlined mi-xs">admin_panel_settings</span> Admin</span>';
    }
    return '<span class="badge-estado badge-inactivo"><span class="material-symbols-outlined mi-xs">person</span> Usuario</span>';
}
 
// Enviar email de solicitud de rol con botones de Aprobar/Rechazar (PHPMailer + SMTP)
function enviarEmailSolicitudRol(string $nombreUsuario, string $cargoUsuario, string $correoUsuario, string $justificacion, int $idSolicitud, string $token): bool {
    $urlBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST'] . '/pages/procesar_solicitud_email.php';
    $urlAprobar  = $urlBase . '?token=' . urlencode($token) . '&accion=aprobar';
    $urlRechazar = $urlBase . '?token=' . urlencode($token) . '&accion=rechazar';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress(ADMIN_EMAIL);
        $mail->addReplyTo($correoUsuario, $nombreUsuario);

        $mail->isHTML(true);
        $mail->Subject = '[ManteTech] Solicitud de rol Admin — ' . $nombreUsuario;
        $mail->Body    = '
            <div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:auto;color:#1f2328">
                <h2 style="margin-bottom:4px">Solicitud de rol Administrador</h2>
                <p>El siguiente usuario ha solicitado el rol de <strong>Administrador</strong> en el sistema ManteTech:</p>
                <table style="width:100%;border-collapse:collapse;margin:16px 0">
                    <tr><td style="padding:4px 0;color:#57606a">Nombre</td><td style="padding:4px 0"><strong>' . e($nombreUsuario) . '</strong></td></tr>
                    <tr><td style="padding:4px 0;color:#57606a">Cargo</td><td style="padding:4px 0">' . e($cargoUsuario) . '</td></tr>
                    <tr><td style="padding:4px 0;color:#57606a">Correo</td><td style="padding:4px 0">' . e($correoUsuario) . '</td></tr>
                    <tr><td style="padding:4px 0;color:#57606a">ID Solicitud</td><td style="padding:4px 0">#' . $idSolicitud . '</td></tr>
                </table>
                <p style="color:#57606a">Justificación:</p>
                <p style="background:#f6f8fa;border-left:3px solid #d0d7de;padding:10px 14px;border-radius:0 6px 6px 0">' . nl2br(e($justificacion)) . '</p>
                <div style="margin:26px 0;text-align:center">
                    <a href="' . $urlAprobar . '" style="display:inline-block;background:#1f883d;color:#fff;text-decoration:none;padding:10px 22px;border-radius:6px;font-weight:600;margin-right:10px">Aprobar solicitud</a>
                    <a href="' . $urlRechazar . '" style="display:inline-block;background:#cf222e;color:#fff;text-decoration:none;padding:10px 22px;border-radius:6px;font-weight:600">Rechazar solicitud</a>
                </div>
                <p style="font-size:12px;color:#8b949e">Estos enlaces son de un solo uso y solo funcionan una vez. También puedes gestionar esta solicitud desde el panel de Gestión de Roles.</p>
            </div>';
        $mail->AltBody = "El usuario {$nombreUsuario} ({$cargoUsuario}, {$correoUsuario}) solicitó el rol de Administrador.\n\n"
                        . "Justificación: {$justificacion}\n\n"
                        . "Aprobar: {$urlAprobar}\n"
                        . "Rechazar: {$urlRechazar}";

        $mail->send();
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Error enviando email de solicitud de rol: ' . $mail->ErrorInfo);
        return false;
    }
}
 