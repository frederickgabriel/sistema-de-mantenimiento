
-- SISTEMA DE GESTIÓN DE MANTENIMIENTO DE EQUIPOS
-- Base de Datos MySQL
-- =============================================

CREATE DATABASE IF NOT EXISTS sistema_mantenimiento CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_mantenimiento;

-- Tabla de Usuarios
CREATE TABLE IF NOT EXISTS Usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    cargo VARCHAR(80) NOT NULL,
    correo VARCHAR(120) NOT NULL UNIQUE,
    edad INT,
    password VARCHAR(255) NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Áreas / Salones
CREATE TABLE IF NOT EXISTS Areas (
    id_area INT AUTO_INCREMENT PRIMARY KEY,
    nombre_area VARCHAR(100) NOT NULL,
    ubicacion VARCHAR(150),
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Equipos
CREATE TABLE IF NOT EXISTS Equipos (
    numero_inventario VARCHAR(50) PRIMARY KEY,
    modelo VARCHAR(120) NOT NULL,
    marca VARCHAR(80),
    procesador VARCHAR(100),
    ram VARCHAR(50),
    disco VARCHAR(80),
    estado ENUM('Activo','Inactivo','En Reparacion') DEFAULT 'Activo',
    id_area INT,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_area) REFERENCES Areas(id_area) ON DELETE SET NULL
);

-- Tabla de Mantenimientos
CREATE TABLE IF NOT EXISTS Mantenimientos (
    id_mantenimiento INT AUTO_INCREMENT PRIMARY KEY,
    numero_inventario VARCHAR(50) NOT NULL,
    tipo_mantenimiento ENUM('Preventivo','Correctivo') NOT NULL,
    fecha_realizacion DATE NOT NULL,
    fecha_entrega DATE,
    proximo_mantenimiento DATE,
    detalles TEXT,
    id_tecnico INT,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (numero_inventario) REFERENCES Equipos(numero_inventario) ON DELETE CASCADE,
    FOREIGN KEY (id_tecnico) REFERENCES Usuarios(id_usuario) ON DELETE SET NULL
);

-- Tabla de Tareas
CREATE TABLE IF NOT EXISTS Tareas (
    id_tarea INT AUTO_INCREMENT PRIMARY KEY,
    nombre_tarea VARCHAR(150) NOT NULL,
    descripcion TEXT,
    numero_inventario VARCHAR(50),
    fecha_programada DATE,
    estado ENUM('Pendiente','En Proceso','Realizado','No Realizado') DEFAULT 'Pendiente',
    prioridad ENUM('Baja','Media','Alta') DEFAULT 'Media',
    id_usuario_asignado INT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (numero_inventario) REFERENCES Equipos(numero_inventario) ON DELETE SET NULL,
    FOREIGN KEY (id_usuario_asignado) REFERENCES Usuarios(id_usuario) ON DELETE SET NULL
);

-- Datos de prueba
INSERT INTO Areas (nombre_area, ubicacion) VALUES 
('Sala de Cómputo A', 'Edificio Principal - Planta Baja'),
('Sala de Cómputo B', 'Edificio Principal - Primer Piso'),
('Laboratorio de Diseño', 'Edificio Anexo');

INSERT INTO Equipos (numero_inventario, modelo, marca, estado, id_area) VALUES
('INV-001', 'OptiPlex 7090', 'Dell', 'Activo', 1),
('INV-002', 'EliteDesk 800 G6', 'HP', 'Activo', 1),
('INV-003', 'ThinkCentre M90q', 'Lenovo', 'En Reparacion', 2);
