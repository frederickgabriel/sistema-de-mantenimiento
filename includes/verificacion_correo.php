<?php
// =============================================
// VERIFICACIÓN DE CORREO POR CÓDIGO
// Archivo: includes/verificacion_correo.php   (se carga desde includes/config.php)
// Al registrarse (o al cambiar de correo) se manda un código de 6 dígitos al correo indicado;
// la cuenta / el cambio solo se concreta cuando el usuario lo escribe. Así no se aceptan
// correos inventados. Los datos pendientes viven en la tabla VerificacionCorreo (se crea sola);
// la contraseña se guarda ya cifrada, nunca en texto plano.
// =============================================

const VC_MINUTOS = 15;   // vigencia del código
const VC_INTENTOS = 5;   // intentos fallidos antes de pedir otro código
const VC_ESPERA   = 60;  // segundos mínimos entre envíos al mismo correo

function vcTabla(): void {
    static $listo = false;
    if ($listo) return;
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS VerificacionCorreo (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            clave        VARCHAR(200) NOT NULL UNIQUE,
            tipo         VARCHAR(12)  NOT NULL,
            correo       VARCHAR(120) NOT NULL,
            id_usuario   INT          NULL,
            datos        TEXT         NULL,
            codigo_hash  VARCHAR(255) NOT NULL,
            intentos     TINYINT      NOT NULL DEFAULT 0,
            expira       DATETIME     NOT NULL,
            ultimo_envio DATETIME     NOT NULL
        )
    ");
    getDB()->exec("DELETE FROM VerificacionCorreo WHERE expira < NOW() - INTERVAL 1 DAY");
    $listo = true;
}

// Dominios permitidos: [] = cualquier dominio real (Gmail, Outlook, Hotmail, Yahoo, iCloud, el corporativo, etc.).
// Para limitar el registro a ciertos dominios escribe aquí la lista, p. ej. ['hyatt.com', 'gmail.com'].
const VC_DOMINIOS_PERMITIDOS = [];

// Correos desechables / temporales: existen, pero se usan para inventar cuentas. Se rechazan.
const VC_DOMINIOS_DESECHABLES = [
    'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'sharklasers.com', 'grr.la',
    '10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmail.net',
    'yopmail.com', 'yopmail.net', 'throwawaymail.com', 'trashmail.com', 'trashmail.net', 'getnada.com',
    'maildrop.cc', 'dispostable.com', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'mohmal.com',
    'emailondeck.com', 'moakt.com', 'mytemp.email', 'tempail.com', 'burnermail.io', 'spamgourmet.com',
    'mailcatch.com', 'tmpmail.org', 'tmpmail.net', 'discard.email', 'inboxkitten.com', 'harakirimail.com',
];

function vcCorreo(string $correo): string { return mb_strtolower(trim($correo)); }
function vcClave(string $tipo, string $correo, ?int $idUsuario): string { return $tipo . '|' . vcCorreo($correo) . '|' . (int)$idUsuario; }

/** El dominio del correo debe existir y poder recibir mensajes (registro MX o, en su defecto, A). */
function vcDominioValido(string $correo): bool {
    $dominio = substr(strrchr($correo, '@') ?: '', 1);
    if ($dominio === '' || !str_contains($dominio, '.')) return false;
    if (!function_exists('checkdnsrr')) return true; // sin DNS disponible no se bloquea; lo valida el código
    return checkdnsrr($dominio . '.', 'MX') || checkdnsrr($dominio . '.', 'A');
}

/** '' si el correo es aceptable; si no, el mensaje de error para mostrar. */
function vcErrorCorreo(string $correo): string {
    $dominio = mb_strtolower(substr(strrchr($correo, '@') ?: '', 1));
    if (VC_DOMINIOS_PERMITIDOS && !in_array($dominio, VC_DOMINIOS_PERMITIDOS, true)) {
        return 'Solo se aceptan correos de: ' . implode(', ', VC_DOMINIOS_PERMITIDOS) . '.';
    }
    if (in_array($dominio, VC_DOMINIOS_DESECHABLES, true)) {
        return 'No se aceptan correos temporales o desechables. Usa tu correo personal o corporativo.';
    }
    if (!vcDominioValido($correo)) {
        return 'Ese correo no existe o su dominio no puede recibir mensajes. Escribe un correo real.';
    }
    return '';
}

function vcEnviarCodigo(string $correo, string $nombre, string $codigo, string $motivo): bool {
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
        $mail->Timeout    = 15;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($correo, $nombre);
        $mail->isHTML(true);
        $mail->Subject = 'Tu código de verificación: ' . $codigo;
        $mail->Body = '
            <div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:auto;color:#1f2328">
                <h2 style="margin-bottom:4px">Verifica tu correo</h2>
                <p>Hola ' . e($nombre) . ', usa este código para ' . e($motivo) . ' en Zilara TechCare:</p>
                <p style="text-align:center;margin:24px 0">
                    <span style="display:inline-block;font-size:34px;letter-spacing:10px;font-weight:700;background:#f6f8fa;border:1px solid #d0d7de;border-radius:10px;padding:14px 22px 14px 32px">' . e($codigo) . '</span>
                </p>
                <p style="font-size:13px;color:#57606a">El código vence en ' . VC_MINUTOS . ' minutos. Si no lo pediste tú, ignora este mensaje.</p>
            </div>';
        $mail->AltBody = "Tu código de verificación de Zilara TechCare es {$codigo}. Vence en " . VC_MINUTOS . " minutos.";
        $mail->send();
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Error enviando código de verificación: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Crea (o renueva) una verificación pendiente y envía el código.
 * $datos: información a conservar hasta que se verifique (nombre, cargo, edad, password_hash...).
 * Devuelve '' si todo salió bien o el mensaje de error para mostrar.
 */
function vcIniciar(string $tipo, string $correo, string $nombre, array $datos = [], ?int $idUsuario = null): string {
    vcTabla();
    $db = getDB();
    $correo = vcCorreo($correo);
    $clave  = vcClave($tipo, $correo, $idUsuario);

    $st = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, ultimo_envio, NOW()) FROM VerificacionCorreo WHERE clave=?");
    $st->execute([$clave]);
    $desde = $st->fetchColumn();
    if ($desde !== false && (int)$desde < VC_ESPERA) {
        return 'Espera ' . (VC_ESPERA - (int)$desde) . ' segundos antes de pedir otro código.';
    }

    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare("DELETE FROM VerificacionCorreo WHERE clave=?")->execute([$clave]);
    $db->prepare("INSERT INTO VerificacionCorreo (clave, tipo, correo, id_usuario, datos, codigo_hash, expira, ultimo_envio)
                  VALUES (?,?,?,?,?,?, NOW() + INTERVAL " . VC_MINUTOS . " MINUTE, NOW())")
       ->execute([$clave, $tipo, $correo, $idUsuario, json_encode($datos, JSON_UNESCAPED_UNICODE), password_hash($codigo, PASSWORD_BCRYPT)]);

    $motivo = $tipo === 'registro' ? 'crear tu cuenta' : 'confirmar tu nuevo correo';
    if (!vcEnviarCodigo($correo, $nombre, $codigo, $motivo)) {
        $db->prepare("DELETE FROM VerificacionCorreo WHERE clave=?")->execute([$clave]);
        return 'No se pudo enviar el código. Revisa que el correo exista e inténtalo de nuevo.';
    }
    return '';
}

/** Reenvía el código a una verificación pendiente (con un código nuevo). '' = ok, o mensaje de error. */
function vcReenviar(string $tipo, string $correo, ?int $idUsuario = null): string {
    vcTabla();
    $st = getDB()->prepare("SELECT datos FROM VerificacionCorreo WHERE clave=?");
    $st->execute([vcClave($tipo, $correo, $idUsuario)]);
    $datos = $st->fetchColumn();
    if ($datos === false) return 'La verificación ya venció. Vuelve a empezar.';
    $datos = json_decode($datos, true) ?: [];
    return vcIniciar($tipo, $correo, $datos['nombre'] ?? '', $datos, $idUsuario);
}

/** Datos guardados de una verificación pendiente vigente, o null. */
function vcPendiente(string $tipo, string $correo, ?int $idUsuario = null): ?array {
    vcTabla();
    $st = getDB()->prepare("SELECT datos FROM VerificacionCorreo WHERE clave=? AND expira > NOW()");
    $st->execute([vcClave($tipo, $correo, $idUsuario)]);
    $d = $st->fetchColumn();
    return $d === false ? null : (json_decode($d, true) ?: []);
}

/**
 * Comprueba el código. Devuelve ['ok' => true, 'datos' => [...]] (y consume la verificación)
 * o ['ok' => false, 'error' => '...'].
 */
function vcVerificar(string $tipo, string $correo, string $codigo, ?int $idUsuario = null): array {
    vcTabla();
    $db = getDB();
    $clave = vcClave($tipo, $correo, $idUsuario);
    $st = $db->prepare("SELECT *, (expira < NOW()) AS vencido FROM VerificacionCorreo WHERE clave=?");
    $st->execute([$clave]);
    $v = $st->fetch();
    if (!$v) return ['ok' => false, 'error' => 'No hay una verificación pendiente. Vuelve a empezar.'];
    if ($v['vencido']) {
        $db->prepare("DELETE FROM VerificacionCorreo WHERE clave=?")->execute([$clave]);
        return ['ok' => false, 'error' => 'El código venció. Pide uno nuevo.'];
    }
    if ((int)$v['intentos'] >= VC_INTENTOS) return ['ok' => false, 'error' => 'Demasiados intentos. Pide un código nuevo.'];

    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6 || !password_verify($codigo, $v['codigo_hash'])) {
        $db->prepare("UPDATE VerificacionCorreo SET intentos = intentos + 1 WHERE clave=?")->execute([$clave]);
        $quedan = VC_INTENTOS - (int)$v['intentos'] - 1;
        return ['ok' => false, 'error' => 'Código incorrecto.' . ($quedan > 0 ? " Te quedan {$quedan} intento(s)." : ' Pide un código nuevo.')];
    }
    $db->prepare("DELETE FROM VerificacionCorreo WHERE clave=?")->execute([$clave]);
    return ['ok' => true, 'datos' => json_decode($v['datos'] ?? '[]', true) ?: []];
}
