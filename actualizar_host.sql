-- =============================================
-- ACTUALIZACIÓN DE BASE DE DATOS PARA EL HOST
-- Marbetes de bajas + Reportes guardados
-- Ejecutar UNA SOLA VEZ (phpMyAdmin → pestaña SQL, con la base de datos ya seleccionada).
-- Si lo corres dos veces, el ALTER TABLE dará error "Duplicate column": es inofensivo.
-- =============================================

-- 1) Marbetes de bajas
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

-- 2) Reportes guardados (copia fija de cada reporte)
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

-- 3) Registros que cubre cada reporte guardado (desaparecen de la vista previa)
CREATE TABLE IF NOT EXISTS Reportes_Guardados_Items (
    id_reporte  INT NOT NULL,
    tipo        VARCHAR(20) NOT NULL,
    referencia  VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (id_reporte, tipo, referencia),
    KEY idx_tipo_ref (tipo, referencia),
    FOREIGN KEY (id_reporte) REFERENCES Reportes_Guardados(id_reporte) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
