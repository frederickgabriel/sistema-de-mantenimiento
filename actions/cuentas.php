<?php
// =============================================
// API DE EXTRAS › CUENTAS — solo administradores
// Archivo: actions/cuentas.php
// GET  ?accion=lista[&v=HUELLA]  → lista de cuentas (o {sin_cambios:true} si la huella no cambió)
// GET  ?accion=validar&correo=   → valida un correo sin guardarlo
// GET  ?accion=revelar&id=N      → contraseña descifrada de una cuenta
// POST accion=guardar (id?, correo, password, asignado_a, notas) | accion=eliminar (id)
// Toda petición debe traer el header X-Ajax-Request (lo envía js/cuentas.js): un formulario de otro sitio no puede ponerlo.
// =============================================
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireAccesoCuentas(true);
if (!isAjax()) { http_response_code(400); echo json_encode(['ok' => false, 'msg' => 'Petición no válida.']); exit; }
session_write_close(); // el sondeo en tiempo real no debe bloquear otras páginas

function salir(bool $ok, string $msg = '', array $extra = [], int $http = 200): void {
    http_response_code($http);
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    cuentasTabla();
    $db     = getDB();
    $metodo = $_SERVER['REQUEST_METHOD'];
    $accion = $_REQUEST['accion'] ?? '';

    // ---------- Lectura ----------
    if ($metodo === 'GET') {
        if ($accion === 'lista') {
            $d = cuentasLista();
            if (($_GET['v'] ?? '') === $d['version']) salir(true, '', ['sin_cambios' => true, 'version' => $d['version']]);
            salir(true, '', $d);
        }
        if ($accion === 'validar') {
            $correo = mb_strtolower(trim((string)($_GET['correo'] ?? '')));
            $err = cuentasErrorCorreo($correo);
            if ($err !== '') salir(false, $err);
            $dup = $db->prepare("SELECT id_cuenta FROM CuentasExternas WHERE correo=? AND id_cuenta<>?");
            $dup->execute([$correo, (int)($_GET['id'] ?? 0)]);
            if ($dup->fetchColumn()) salir(false, 'Ese correo ya está registrado en Cuentas.');
            salir(true, 'Correo válido.', ['proveedor' => cuentasProveedor($correo)]);
        }
        if ($accion === 'revelar') {
            $st = $db->prepare("SELECT password_cifrada FROM CuentasExternas WHERE id_cuenta=?");
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $cif = $st->fetchColumn();
            if ($cif === false) salir(false, 'La cuenta ya no existe.', [], 404);
            $claro = cuentasDescifrar($cif);
            if ($claro === null) salir(false, 'No se pudo descifrar la contraseña (¿cambió CUENTAS_CLAVE?).', [], 500);
            salir(true, '', ['password' => $claro]);
        }
        salir(false, 'Acción desconocida.', [], 400);
    }

    // ---------- Escritura ----------
    if ($metodo !== 'POST') salir(false, 'Método no permitido.', [], 405);

    if ($accion === 'guardar') {
        $id       = (int)($_POST['id'] ?? 0);
        $correo   = mb_strtolower(trim((string)($_POST['correo'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $asignado = mb_substr(trim((string)($_POST['asignado_a'] ?? '')), 0, 120);
        $notas    = mb_substr(trim((string)($_POST['notas'] ?? '')), 0, 300);

        $err = cuentasErrorCorreo($correo);
        if ($err !== '') salir(false, $err, ['campo' => 'correo'], 422);
        if ($asignado === '') salir(false, 'Indica a quién se asigna la cuenta.', ['campo' => 'asignado_a'], 422);

        $dup = $db->prepare("SELECT id_cuenta FROM CuentasExternas WHERE correo=? AND id_cuenta<>?");
        $dup->execute([$correo, $id]);
        if ($dup->fetchColumn()) salir(false, 'Ese correo ya está registrado en Cuentas.', ['campo' => 'correo'], 422);

        if ($id) {
            $ex = $db->prepare("SELECT 1 FROM CuentasExternas WHERE id_cuenta=?");
            $ex->execute([$id]);
            if (!$ex->fetchColumn()) salir(false, 'La cuenta ya no existe.', [], 404);
            if ($password !== '') {
                if (mb_strlen($password) < 8) salir(false, 'La contraseña debe tener al menos 8 caracteres.', ['campo' => 'password'], 422);
                $db->prepare("UPDATE CuentasExternas SET correo=?, password_cifrada=?, asignado_a=?, notas=? WHERE id_cuenta=?")
                   ->execute([$correo, cuentasCifrar($password), $asignado, $notas, $id]);
            } else {
                $db->prepare("UPDATE CuentasExternas SET correo=?, asignado_a=?, notas=? WHERE id_cuenta=?")
                   ->execute([$correo, $asignado, $notas, $id]);
            }
            salir(true, 'Cuenta actualizada.', cuentasLista());
        }

        if (mb_strlen($password) < 8) salir(false, 'La contraseña debe tener al menos 8 caracteres.', ['campo' => 'password'], 422);
        $db->prepare("INSERT INTO CuentasExternas (correo, password_cifrada, asignado_a, notas, id_usuario_creador) VALUES (?,?,?,?,?)")
           ->execute([$correo, cuentasCifrar($password), $asignado, $notas, (int)$_SESSION['usuario']['id']]);
        $nuevaId = (int)$db->lastInsertId(); // antes de cualquier otra consulta: un SELECT lo reinicia
        salir(true, 'Cuenta creada.', cuentasLista() + ['nueva_id' => $nuevaId]);
    }

    if ($accion === 'eliminar') {
        $del = $db->prepare("DELETE FROM CuentasExternas WHERE id_cuenta=?");
        $del->execute([(int)($_POST['id'] ?? 0)]);
        salir($del->rowCount() > 0, $del->rowCount() ? 'Cuenta eliminada.' : 'La cuenta ya no existe.', cuentasLista());
    }

    salir(false, 'Acción desconocida.', [], 400);
} catch (PDOException $e) {
    error_log('actions/cuentas.php: ' . $e->getMessage());
    salir(false, 'Error de base de datos. Inténtalo de nuevo.', [], 500);
}
