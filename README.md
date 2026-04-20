# 🖥 Sistema de Gestión de Mantenimiento — ManteTech

Sistema completo en **PHP + MySQL + HTML/CSS** para gestionar mantenimiento de equipos de cómputo.

---

## 📁 Estructura de archivos

```
sistema_mantenimiento/
├── index.php                  ← Login y Registro
├── .htaccess                  ← Configuración Apache
├── database.sql               ← Script de base de datos
│
├── css/
│   └── estilos.css            ← Todos los estilos
│
├── includes/
│   ├── config.php             ← Conexión BD, funciones globales
│   └── sidebar.php            ← Menú lateral (reutilizable)
│
├── pages/
│   ├── dashboard.php          ← Panel principal con estadísticas
│   ├── equipos.php            ← CRUD de Equipos y Áreas
│   ├── mantenimientos.php     ← CRUD Historial de mantenimientos
│   ├── tareas.php             ← CRUD Tareas con filtros por estado
│   └── reportes.php          ← Vista + descarga de reporte PDF
│
└── actions/
    └── logout.php             ← Cerrar sesión
```

---

## ⚙️ Instalación paso a paso

### 1. Requisitos
- PHP 8.0 o superior
- MySQL 5.7 o superior
- Servidor Apache (XAMPP, WAMP, Laragon, etc.)

### 2. Copiar archivos
Copia toda la carpeta `sistema_mantenimiento/` dentro de la carpeta pública de tu servidor:
- XAMPP → `C:/xampp/htdocs/sistema_mantenimiento/`
- Laragon → `C:/laragon/www/sistema_mantenimiento/`

### 3. Crear la base de datos
Abre **phpMyAdmin** y ejecuta el archivo `database.sql`.
Esto creará la base de datos, tablas y datos de ejemplo.

### 4. Configurar conexión
Edita `includes/config.php` y ajusta:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'TU_PASSWORD_AQUI');  // ← Cambia esto
define('DB_NAME', 'sistema_mantenimiento');
```

### 5. Habilitar mod_rewrite (Apache)
En XAMPP: edita `httpd.conf` y asegura que `AllowOverride All` esté activado.

### 6. Acceder al sistema
Abre: `http://localhost/sistema_mantenimiento/`

---

## ✅ Funcionalidades incluidas

| Módulo | Funciones |
|--------|-----------|
| **Login/Registro** | Registro con nombre, cargo, correo, edad · Login seguro con contraseñas hasheadas (bcrypt) |
| **Dashboard** | Estadísticas en tiempo real · Alertas de mantenimiento urgente · Gráficas de barras · Tareas recientes · Últimos mantenimientos |
| **Equipos** | CRUD completo · Campos: inventario, modelo, marca, CPU, RAM, disco, área, estado |
| **Áreas** | CRUD · Asignación de equipos por área |
| **Mantenimientos** | Historial completo · Tipo: Preventivo / Correctivo · Fecha de entrega · Próximo cita auto (6 meses) · Reagendar · Alertas por días |
| **Tareas** | Nueva tarea con nombre, descripción, equipo, fecha, prioridad, asignado · Estados: Pendiente / En Proceso / Realizado / No Realizado · Filtros por estado |
| **Reportes PDF** | Vista previa en pantalla · Botón "Imprimir/Guardar PDF" vía Print del navegador · Incluye estadísticas + alertas + historial |

---

## 🔒 Seguridad
- Contraseñas hasheadas con **bcrypt** (password_hash/verify)
- Consultas preparadas con **PDO** (protección contra SQL injection)
- Sesiones PHP del lado del servidor (sin localStorage)
- Protección de páginas con `requireLogin()`
- Escape de output con `htmlspecialchars` en toda la UI

---

## 💡 Notas adicionales

**¿Cómo generar el PDF?**
En la página de Reportes, haz clic en "Generar Reporte PDF". Se abrirá una página limpia optimizada para impresión. Usa `Ctrl+P` en tu navegador → **Guardar como PDF**.

**¿Cómo funciona la alerta de mantenimiento?**
Al registrar un mantenimiento, el sistema calcula automáticamente la próxima cita (6 meses). El Dashboard muestra alertas cuando la fecha está a ≤7 días o ya venció.

**Zona horaria:** Configurada en `America/Mexico_City` en `config.php`.
