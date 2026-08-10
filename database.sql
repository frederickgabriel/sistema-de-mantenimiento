-- =============================================
-- SISTEMA DE GESTIÓN DE MANTENIMIENTO
-- Base de Datos Completa — Versión Final
-- Compatible con MySQL 5.7+
-- =============================================

CREATE DATABASE IF NOT EXISTS sistema_mantenimiento
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sistema_mantenimiento;

-- =============================================
-- TABLA: Usuarios
-- =============================================
CREATE TABLE IF NOT EXISTS Usuarios (
    id_usuario    INT AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(100)  NOT NULL,
    cargo         VARCHAR(80)   NOT NULL,
    correo        VARCHAR(120)  NOT NULL UNIQUE,
    edad          INT           NULL,
    password      VARCHAR(255)  NOT NULL,
    foto_perfil   VARCHAR(255)  NULL,
    telefono      VARCHAR(20)   NULL,
    bio           TEXT          NULL,
    tema          ENUM('oscuro','claro') DEFAULT 'oscuro',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- TABLA: Areas
-- =============================================
CREATE TABLE IF NOT EXISTS Areas (
    id_area       INT AUTO_INCREMENT PRIMARY KEY,
    nombre_area   VARCHAR(100) NOT NULL,
    ubicacion     VARCHAR(150) NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- TABLA: Equipos
-- =============================================
CREATE TABLE IF NOT EXISTS Equipos (
    numero_inventario VARCHAR(50)  PRIMARY KEY,
    modelo            VARCHAR(120) NOT NULL,
    marca             VARCHAR(80)  NULL,
    procesador        VARCHAR(100) NULL,
    ram               VARCHAR(50)  NULL,
    disco             VARCHAR(80)  NULL,
    estado            ENUM('Activo','Inactivo','En Reparacion','Baja') DEFAULT 'Activo',
    id_area           INT          NULL,
    fecha_registro    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_area) REFERENCES Areas(id_area) ON DELETE SET NULL
);

-- =============================================
-- TABLA: Mantenimientos
-- =============================================
CREATE TABLE IF NOT EXISTS Mantenimientos (
    id_mantenimiento    INT AUTO_INCREMENT PRIMARY KEY,
    numero_inventario   VARCHAR(50) NOT NULL,
    tipo_mantenimiento  ENUM('Preventivo','Correctivo') NOT NULL,
    fecha_realizacion   DATE        NOT NULL,
    fecha_entrega       DATE        NULL,
    proximo_mantenimiento DATE      NULL,
    detalles            TEXT        NULL,
    id_tecnico          INT         NULL,
    fecha_registro      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (numero_inventario) REFERENCES Equipos(numero_inventario) ON DELETE CASCADE,
    FOREIGN KEY (id_tecnico)        REFERENCES Usuarios(id_usuario)       ON DELETE SET NULL
);

-- =============================================
-- TABLA: Tareas
-- =============================================
CREATE TABLE IF NOT EXISTS Tareas (
    id_tarea             INT AUTO_INCREMENT PRIMARY KEY,
    nombre_tarea         VARCHAR(150) NOT NULL,
    descripcion          TEXT         NULL,
    numero_inventario    VARCHAR(50)  NULL,
    fecha_programada     DATE         NULL,
    estado               ENUM('Pendiente','En Proceso','Realizado','No Realizado') DEFAULT 'Pendiente',
    prioridad            ENUM('Baja','Media','Alta') DEFAULT 'Media',
    id_usuario_asignado  INT          NULL,
    fecha_creacion       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (numero_inventario)   REFERENCES Equipos(numero_inventario)  ON DELETE SET NULL,
    FOREIGN KEY (id_usuario_asignado) REFERENCES Usuarios(id_usuario)        ON DELETE SET NULL
);

-- =============================================
-- TABLA: Bajas
-- =============================================
CREATE TABLE IF NOT EXISTS Bajas (
    id_baja                   INT AUTO_INCREMENT PRIMARY KEY,
    numero_inventario         VARCHAR(50)   NOT NULL,
    motivo_baja               ENUM('Daño Irreparable','Obsolescencia','Robo/Extravío','Siniestro','Vida Útil Cumplida','Otro') NOT NULL,
    descripcion_falla         TEXT          NOT NULL,
    diagnostico_tecnico       TEXT          NOT NULL,
    intentos_reparacion       TEXT          NULL,
    costo_reparacion_estimado DECIMAL(10,2) NULL,
    valor_actual_estimado     DECIMAL(10,2) NULL,
    recomendacion             ENUM('Destrucción','Donación','Subasta','Reciclaje') NOT NULL DEFAULT 'Destrucción',
    id_tecnico_responsable    INT           NULL,
    nombre_autoriza           VARCHAR(150)  NULL,
    cargo_autoriza            VARCHAR(100)  NULL,
    fecha_baja                DATE          NOT NULL,
    estado_validacion         ENUM('Pendiente','Validado','Rechazado') DEFAULT 'Pendiente',
    fecha_validacion          TIMESTAMP     NULL,
    observaciones_validacion  TEXT          NULL,
    fecha_registro            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (numero_inventario)      REFERENCES Equipos(numero_inventario)  ON DELETE CASCADE,
    FOREIGN KEY (id_tecnico_responsable) REFERENCES Usuarios(id_usuario)        ON DELETE SET NULL
);


-- Agregar columna de rol a Usuarios
ALTER TABLE Usuarios
    ADD COLUMN rol ENUM('admin','usuario') DEFAULT 'usuario' AFTER correo;
 
-- Tabla de solicitudes de rol admin
CREATE TABLE IF NOT EXISTS SolicitudesRol (
    id_solicitud   INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario     INT NOT NULL,
    justificacion  TEXT NOT NULL,
    estado         ENUM('Pendiente','Aprobada','Rechazada') DEFAULT 'Pendiente',
    respuesta      TEXT NULL,
    token          VARCHAR(64) NULL,
    token_usado    TINYINT(1) NOT NULL DEFAULT 0,
    fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_respuesta TIMESTAMP NULL,
    FOREIGN KEY (id_usuario) REFERENCES Usuarios(id_usuario) ON DELETE CASCADE
);
 
-- El primer usuario registrado lo puedes hacer admin manualmente:
-- UPDATE Usuarios SET rol='admin' WHERE id_usuario = 1;
-- para volverte admin
UPDATE Usuarios SET rol='admin' WHERE id_usuario = 1;
-- =============================================
-- DATOS DE EJEMPLO (opcional, puedes borrarlos)
-- =============================================
INSERT INTO Areas (nombre_area, ubicacion) VALUES
('Sala de Cómputo A', 'Edificio Principal - Planta Baja'),
('Sala de Cómputo B', 'Edificio Principal - Primer Piso'),
('Laboratorio de Diseño', 'Edificio Anexo');

INSERT INTO Equipos (numero_inventario, modelo, marca, estado, id_area) VALUES
('INV-001', 'OptiPlex 7090',    'Dell',   'Activo',        1),
('INV-002', 'EliteDesk 800 G6', 'HP',     'Activo',        1),
('INV-003', 'ThinkCentre M90q', 'Lenovo', 'En Reparacion', 2);


ALTER TABLE SolicitudesRol
    ADD COLUMN token       VARCHAR(64) NULL AFTER respuesta,
    ADD COLUMN token_usado TINYINT(1) NOT NULL DEFAULT 0 AFTER token;

-- =============================================
-- MÓDULO EMPLEADOS (solo Admin) + evidencia fotográfica de equipos
-- =============================================

-- Registrar el momento exacto en que se completó una tarea
ALTER TABLE Tareas
    ADD COLUMN fecha_completado TIMESTAMP NULL DEFAULT NULL AFTER estado;

-- Evidencia fotográfica del equipo (antes de abrirlo), subida por el empleado
-- al registrar un Mantenimiento o al completar una Tarea
CREATE TABLE IF NOT EXISTS EvidenciasEquipo (
    id_evidencia      INT AUTO_INCREMENT PRIMARY KEY,
    origen            ENUM('Mantenimiento','Tarea') NOT NULL,
    id_origen         INT NOT NULL,
    numero_inventario VARCHAR(50)  NULL,
    id_usuario        INT NOT NULL,
    ruta_imagen       VARCHAR(255) NOT NULL,
    fecha_subida      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES Usuarios(id_usuario) ON DELETE CASCADE,
    INDEX idx_origen (origen, id_origen),
    INDEX idx_usuario (id_usuario)
);

-- Estado manual de un Mantenimiento (En Proceso / Completado). Una vez Completado,
-- el registro ya no se puede editar (aunque sí se puede reagendar, eliminar o gestionar sus fotos).
ALTER TABLE Mantenimientos
    ADD COLUMN estado ENUM('En Proceso','Completado') NOT NULL DEFAULT 'En Proceso' AFTER tipo_mantenimiento;

-- Dar de baja (desactivar) una cuenta sin borrarla: un usuario inactivo no puede iniciar sesión,
-- pero conserva su historial de tareas/mantenimientos/evidencias para auditoría. Reversible desde
-- Gestión de Roles. También se agregó ahí un botón de eliminación permanente (irreversible).
ALTER TABLE Usuarios
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol;

-- Permite iniciar sesión con Google: guarda el "sub" (ID único de cuenta de Google)
-- para vincularlo con la cuenta existente (por correo) o detectar cuentas creadas vía Google.
ALTER TABLE Usuarios
    ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER password;
