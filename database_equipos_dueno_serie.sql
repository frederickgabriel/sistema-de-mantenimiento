-- =============================================
-- MIGRACIÓN: Número de Serie y Usuario Dueño en Equipos
-- Ejecuta este script UNA VEZ sobre tu base de datos existente
-- (phpMyAdmin -> pestaña SQL, o: mysql -u root -p sistema_mantenimiento < database_equipos_dueno_serie.sql)
--
-- "usuario_dueno" es texto libre: el dueño del equipo puede ser cualquier
-- persona (maestro, empleado, etc.), no necesariamente alguien con cuenta
-- en este sistema.
-- =============================================

ALTER TABLE Equipos
    ADD COLUMN numero_serie  VARCHAR(100) NULL AFTER marca,
    ADD COLUMN usuario_dueno VARCHAR(150) NULL AFTER id_area;
