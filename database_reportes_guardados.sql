-- =============================================
-- Reportes guardados: archivo de reportes PDF (Mantenimientos / Equipos).
-- Cada registro conserva una copia fija (html) del reporte tal como se generó.
-- Ejecutar una sola vez sobre bases de datos existentes.
-- =============================================
CREATE TABLE IF NOT EXISTS Reportes_Guardados (
    id_reporte      INT AUTO_INCREMENT PRIMARY KEY,
    tipo            VARCHAR(20)  NOT NULL,
    titulo          VARCHAR(150) NOT NULL,
    filtro_texto    VARCHAR(255) NOT NULL DEFAULT '',
    params          VARCHAR(255) NOT NULL DEFAULT '',
    total_registros INT          NOT NULL DEFAULT 0,
    html            LONGTEXT     NOT NULL,
    id_usuario      INT          NULL,
    fecha_registro  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES Usuarios(id_usuario) ON DELETE SET NULL
);
