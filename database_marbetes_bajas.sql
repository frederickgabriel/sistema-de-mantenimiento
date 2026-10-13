-- =============================================
-- Marbetes de bajas: archivo de formatos de baja guardados.
-- Una baja con id_marbete NULL está "por archivar" (aparece en Bajas);
-- una baja con id_marbete asignado vive solo dentro de su marbete.
-- Ejecutar una sola vez sobre bases de datos existentes.
-- =============================================
CREATE TABLE IF NOT EXISTS Marbetes_Bajas (
    id_marbete         INT AUTO_INCREMENT PRIMARY KEY,
    tipo               VARCHAR(10)  NOT NULL DEFAULT 'activos',
    fecha              DATE         NOT NULL,
    fecha_verificacion DATE         NULL,
    depto              VARCHAR(100) NOT NULL DEFAULT 'SISTEMAS',
    ubicacion          VARCHAR(100) NOT NULL DEFAULT 'STAFF',
    observaciones      VARCHAR(150) NULL,
    firma_solicita     VARCHAR(60)  NULL,
    firma_costos       VARCHAR(60)  NULL,
    firma_finanzas     VARCHAR(60)  NULL,
    firma_gerente      VARCHAR(60)  NULL,
    id_usuario         INT          NULL,
    fecha_registro     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES Usuarios(id_usuario) ON DELETE SET NULL
);

ALTER TABLE Bajas
    ADD COLUMN id_marbete INT NULL AFTER fecha_registro,
    ADD CONSTRAINT fk_bajas_marbete FOREIGN KEY (id_marbete) REFERENCES Marbetes_Bajas(id_marbete) ON DELETE SET NULL;
