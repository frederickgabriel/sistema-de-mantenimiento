-- =============================================
-- Bajas: el motivo ahora es texto libre y la recomendación final ya no se captura.
-- Ejecutar una sola vez sobre bases de datos existentes (los valores anteriores se conservan).
-- =============================================
ALTER TABLE Bajas
    MODIFY motivo_baja   VARCHAR(255) NOT NULL,
    MODIFY recomendacion VARCHAR(20)  NULL DEFAULT NULL;
