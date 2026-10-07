<?php
// =============================================
// NOTIFICACIONES INTERNAS (campanita)
// Archivo: includes/notificaciones.php   (se carga desde includes/config.php)
// Las notificaciones se guardan en la tabla Notificaciones y las muestra js/notificaciones.js
// consultando actions/notificaciones.php. La tabla se crea sola la primera vez.
// =============================================

function notifAsegurarTabla(): void {
    static $listo = false;
    if ($listo) return;
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS Notificaciones (
            id_notificacion INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario      INT          NOT NULL,
            titulo          VARCHAR(150) NOT NULL,
            mensaje         VARCHAR(255) NOT NULL DEFAULT '',
            enlace          VARCHAR(255) NOT NULL DEFAULT '',
            icono           VARCHAR(40)  NOT NULL DEFAULT 'notifications',
            leida           TINYINT(1)   NOT NULL DEFAULT 0,
            fecha           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario_leida (id_usuario, leida),
            FOREIGN KEY (id_usuario) REFERENCES Usuarios(id_usuario) ON DELETE CASCADE
        )
    ");
    $listo = true;
}

/** Envía una notificación a un usuario. No avisa a quien realizó la acción. Nunca lanza errores. */
function notificar(?int $idUsuario, string $titulo, string $mensaje = '', string $enlace = '', string $icono = 'notifications'): void {
    if (!$idUsuario || $idUsuario === (int)($_SESSION['usuario']['id'] ?? 0)) return;
    try {
        notifAsegurarTabla();
        getDB()->prepare("INSERT INTO Notificaciones (id_usuario, titulo, mensaje, enlace, icono) VALUES (?,?,?,?,?)")
               ->execute([$idUsuario, mb_substr($titulo, 0, 150), mb_substr($mensaje, 0, 255), $enlace, $icono]);
        if (random_int(1, 50) === 1) { // limpieza ocasional: nada de más de 30 días
            getDB()->exec("DELETE FROM Notificaciones WHERE fecha < NOW() - INTERVAL 30 DAY");
        }
    } catch (Throwable $e) {
        error_log('notificar(): ' . $e->getMessage());
    }
}

/** Envía una notificación a todos los administradores activos (menos a quien hizo la acción). */
function notificarAdmins(string $titulo, string $mensaje = '', string $enlace = '', string $icono = 'notifications'): void {
    try {
        foreach (getDB()->query("SELECT id_usuario FROM Usuarios WHERE rol='admin' AND activo=1")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            notificar((int)$id, $titulo, $mensaje, $enlace, $icono);
        }
    } catch (Throwable $e) {
        error_log('notificarAdmins(): ' . $e->getMessage());
    }
}
