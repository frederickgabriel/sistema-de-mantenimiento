-- =============================================
-- Registros cubiertos por cada reporte guardado.
-- Un equipo / mantenimiento incluido en un reporte guardado deja de aparecer en la vista previa
-- y en los reportes nuevos; al eliminar el reporte guardado vuelve a aparecer (ON DELETE CASCADE).
-- Ejecutar una sola vez sobre bases de datos existentes (requiere Reportes_Guardados).
-- =============================================
CREATE TABLE IF NOT EXISTS Reportes_Guardados_Items (
    id_reporte  INT NOT NULL,
    tipo        VARCHAR(20) NOT NULL,                      -- 'equipos' | 'mantenimientos'
    referencia  VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL, -- numero_inventario (equipos) o id_mantenimiento
    PRIMARY KEY (id_reporte, tipo, referencia),
    KEY idx_tipo_ref (tipo, referencia),
    FOREIGN KEY (id_reporte) REFERENCES Reportes_Guardados(id_reporte) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
