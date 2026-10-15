<?php
// =============================================
// EXTRAS › CUENTAS — correos y contraseñas asignados a personas fuera del sistema
// Archivo: includes/cuentas.php   (se carga desde includes/config.php)
// Función independiente del resto del programa: tiene su propia tabla y no toca ninguna otra.
// Las contraseñas se guardan CIFRADAS (AES-256-GCM, clave en CUENTAS_CLAVE de config.php), nunca en
// texto plano: hay que poder mostrarlas de nuevo a los administradores, por eso no se usa hash de un solo sentido.
// =============================================

// Dominios aceptados: los correos más populares + los de la empresa.
const CUENTAS_DOMINIOS_POPULARES = [
    'gmail.com', 'googlemail.com',
    'outlook.com', 'outlook.es', 'hotmail.com', 'hotmail.es', 'live.com', 'live.com.mx', 'msn.com',
    'yahoo.com', 'yahoo.com.mx', 'yahoo.es', 'ymail.com',
    'icloud.com', 'me.com', 'mac.com',
    'aol.com', 'proton.me', 'protonmail.com', 'zoho.com', 'gmx.com', 'mail.com',
];
// Dominios corporativos: agrega aquí los de la empresa (ej. 'miempresa.com.mx').
const CUENTAS_DOMINIOS_EMPRESA = ['hyatt.com'];

// Nombre visible, ícono Material Symbols y color de cada proveedor (por dominio).
const CUENTAS_PROVEEDORES = [
    'gmail.com'     => ['Gmail',     'mail',            '#ea4335'],
    'googlemail.com'=> ['Gmail',     'mail',            '#ea4335'],
    'outlook.com'   => ['Outlook',   'inbox',           '#0a78d4'],
    'outlook.es'    => ['Outlook',   'inbox',           '#0a78d4'],
    'hotmail.com'   => ['Hotmail',   'inbox',           '#0a78d4'],
    'hotmail.es'    => ['Hotmail',   'inbox',           '#0a78d4'],
    'live.com'      => ['Live',      'inbox',           '#0a78d4'],
    'live.com.mx'   => ['Live',      'inbox',           '#0a78d4'],
    'msn.com'       => ['MSN',       'inbox',           '#0a78d4'],
    'yahoo.com'     => ['Yahoo',     'alternate_email', '#6001d2'],
    'yahoo.com.mx'  => ['Yahoo',     'alternate_email', '#6001d2'],
    'yahoo.es'      => ['Yahoo',     'alternate_email', '#6001d2'],
    'ymail.com'     => ['Yahoo',     'alternate_email', '#6001d2'],
    'icloud.com'    => ['iCloud',    'cloud',           '#3b9bf5'],
    'me.com'        => ['iCloud',    'cloud',           '#3b9bf5'],
    'mac.com'       => ['iCloud',    'cloud',           '#3b9bf5'],
    'aol.com'       => ['AOL',       'mail',            '#2d6ee0'],
    'proton.me'     => ['Proton',    'shield_lock',     '#6d4aff'],
    'protonmail.com'=> ['Proton',    'shield_lock',     '#6d4aff'],
    'zoho.com'      => ['Zoho',      'mail',            '#d9382b'],
    'gmx.com'       => ['GMX',       'mail',            '#1c449b'],
    'mail.com'      => ['Mail.com',  'mail',            '#0f6fa8'],
];

/** ¿Puede el usuario en sesión ver Extras › Cuentas? Todos los administradores; los usuarios normales no. */
function puedeVerCuentas(): bool {
    return esAdmin();
}

function requireAccesoCuentas(bool $json = false): void {
    if (!isset($_SESSION['usuario'])) {
        if ($json) { http_response_code(401); echo json_encode(['ok' => false, 'msg' => 'Sesión vencida. Vuelve a iniciar sesión.']); exit; }
        header('Location: /index.php'); exit;
    }
    requireLogin(); // releer rol/activo
    if (!puedeVerCuentas()) {
        if ($json) { http_response_code(403); echo json_encode(['ok' => false, 'msg' => 'No tienes permiso para esta sección.']); exit; }
        header('Location: /pages/dashboard.php?err=sin_permiso'); exit;
    }
}

function cuentasTabla(): void {
    static $listo = false;
    if ($listo) return;
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS CuentasExternas (
            id_cuenta           INT AUTO_INCREMENT PRIMARY KEY,
            correo              VARCHAR(160) NOT NULL UNIQUE,
            password_cifrada    TEXT         NOT NULL,
            asignado_a          VARCHAR(120) NOT NULL DEFAULT '',
            notas               VARCHAR(300) NOT NULL DEFAULT '',
            id_usuario_creador  INT          NULL,
            fecha_creacion      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4
    ");
    $listo = true;
}

// ---------- Cifrado ----------
function cuentasClave(): string { return hash('sha256', CUENTAS_CLAVE, true); }

function cuentasCifrar(string $claro): string {
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($claro, 'aes-256-gcm', cuentasClave(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function cuentasDescifrar(string $b64): ?string {
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $claro = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', cuentasClave(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $claro === false ? null : $claro;
}

// ---------- Validación de correo ----------
function cuentasDominio(string $correo): string {
    return mb_strtolower(substr(strrchr($correo, '@') ?: '', 1));
}

function cuentasProveedor(string $correo): array {
    $dom = cuentasDominio($correo);
    if (isset(CUENTAS_PROVEEDORES[$dom])) {
        [$nombre, $icono, $color] = CUENTAS_PROVEEDORES[$dom];
        return ['nombre' => $nombre, 'icono' => $icono, 'color' => $color, 'empresa' => false];
    }
    if (in_array($dom, CUENTAS_DOMINIOS_EMPRESA, true)) {
        return ['nombre' => 'Empresa', 'icono' => 'business', 'color' => '#5b21b6', 'empresa' => true];
    }
    return ['nombre' => 'Otro', 'icono' => 'mail', 'color' => '#6e7781', 'empresa' => false];
}

/** '' si el correo es aceptable; si no, el mensaje de error. Revisa formato, dominio permitido, desechables y MX. */
function cuentasErrorCorreo(string $correo): string {
    if ($correo === '' || mb_strlen($correo) > 160 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return 'Escribe un correo con formato válido (nombre@dominio.com).';
    }
    $dom = cuentasDominio($correo);
    if (!in_array($dom, CUENTAS_DOMINIOS_POPULARES, true) && !in_array($dom, CUENTAS_DOMINIOS_EMPRESA, true)) {
        return 'Solo se aceptan correos de proveedores conocidos (Gmail, Outlook, Hotmail, Yahoo, iCloud…) o de la empresa.';
    }
    return vcErrorCorreo($correo); // desechables + el dominio debe poder recibir correo (MX)
}

/** Fila de la tabla → arreglo público (sin la contraseña). */
function cuentasFila(array $r): array {
    $prov = cuentasProveedor($r['correo']);
    return [
        'id'         => (int)$r['id_cuenta'],
        'correo'     => $r['correo'],
        'asignado_a' => $r['asignado_a'],
        'notas'      => $r['notas'],
        'proveedor'  => $prov['nombre'],
        'icono'      => $prov['icono'],
        'color'      => $prov['color'],
        'empresa'    => $prov['empresa'],
        'creada'     => $r['fecha_creacion'],
        'actualizada'=> $r['fecha_actualizacion'],
    ];
}

/** Lista completa + huella de versión: si la huella no cambia, el cliente no necesita redibujar nada. */
function cuentasLista(): array {
    cuentasTabla();
    $filas = getDB()->query("SELECT id_cuenta, correo, asignado_a, notas, fecha_creacion, fecha_actualizacion
                             FROM CuentasExternas ORDER BY fecha_creacion DESC, id_cuenta DESC")->fetchAll();
    $cuentas = array_map('cuentasFila', $filas);
    return ['cuentas' => $cuentas, 'version' => md5(json_encode($cuentas))];
}
