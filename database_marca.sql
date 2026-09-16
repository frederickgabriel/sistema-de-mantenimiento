-- =============================================
-- MIGRACIÓN: Configuración de Marca (branding) para reportes PDF
-- Ejecuta este script UNA VEZ sobre tu base de datos existente
-- (phpMyAdmin -> pestaña SQL, o: mysql -u root -p sistema_mantenimiento < database_marca.sql)
--
-- Fila única (id=1) con la identidad visual que usan todos los reportes PDF.
-- =============================================

CREATE TABLE IF NOT EXISTS ConfiguracionMarca (
    id                     INT PRIMARY KEY DEFAULT 1,
    nombre_empresa         VARCHAR(150) NOT NULL DEFAULT 'Gestión de Mantenimiento',
    logo                   VARCHAR(255) NULL,
    color_primario         VARCHAR(7)  NOT NULL DEFAULT '#5b21b6',
    color_secundario       VARCHAR(7)  NOT NULL DEFAULT '#004085',
    direccion              VARCHAR(255) NULL,
    telefono               VARCHAR(30)  NULL,
    correo                 VARCHAR(120) NULL,
    pie_pagina             VARCHAR(255) NULL,
    mostrar_logo           TINYINT(1) NOT NULL DEFAULT 1,
    mostrar_info_empresa   TINYINT(1) NOT NULL DEFAULT 1,
    mostrar_numero_pagina  TINYINT(1) NOT NULL DEFAULT 1,
    fecha_actualizacion    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO ConfiguracionMarca (id) VALUES (1);
