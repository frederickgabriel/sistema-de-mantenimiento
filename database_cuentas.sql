-- =============================================
-- EXTRAS > CUENTAS
-- Correos y contraseñas asignados a personas fuera del programa (pages/cuentas.php).
-- Las contraseñas se guardan CIFRADAS (AES-256-GCM) en password_cifrada, nunca en texto plano.
-- Es una tabla independiente: no tiene llaves foráneas ni toca ninguna otra tabla.
-- El sistema también la crea sola la primera vez que se abre Extras > Cuentas; este script es opcional pero
-- sirve para dejarla lista en el host desde phpMyAdmin.
-- =============================================

CREATE TABLE IF NOT EXISTS CuentasExternas (
    id_cuenta           INT AUTO_INCREMENT PRIMARY KEY,
    correo              VARCHAR(160) NOT NULL UNIQUE,
    password_cifrada    TEXT         NOT NULL,
    asignado_a          VARCHAR(120) NOT NULL DEFAULT '',
    notas               VARCHAR(300) NOT NULL DEFAULT '',
    id_usuario_creador  INT          NULL,
    fecha_creacion      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4;
