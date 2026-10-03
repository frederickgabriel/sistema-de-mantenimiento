<!-- http://sistema-mantenimiento.test/index.php -->

<div align="center">

# Zilara TechCare — Sistema de Gestión de Mantenimiento

Plataforma web para administrar el inventario de equipos de cómputo, su mantenimiento preventivo/correctivo, tareas del equipo técnico, bajas de activos y reportes — con asistente inteligente integrado.

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](#)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](#)
[![JavaScript](https://img.shields.io/badge/JavaScript-ES6-F7DF1E?style=flat-square&logo=javascript&logoColor=black)](#)
[![Apache](https://img.shields.io/badge/Apache-mod__rewrite-D22128?style=flat-square&logo=apache&logoColor=white)](#)
[![PHPMailer](https://img.shields.io/badge/PHPMailer-SMTP-0A66C2?style=flat-square&logo=gmail&logoColor=white)](#)
[![Google Sign-In](https://img.shields.io/badge/Google-Sign--In-4285F4?style=flat-square&logo=google&logoColor=white)](#)
[![Gemini API](https://img.shields.io/badge/Gemini-API-8E75B2?style=flat-square&logo=googlegemini&logoColor=white)](#)

</div>

---

## Tabla de contenido

- [Descripción general](#descripción-general)
- [Módulos y funcionalidades](#módulos-y-funcionalidades)
- [Experiencia de usuario](#experiencia-de-usuario)
- [Seguridad](#seguridad)
- [Modelo de datos](#modelo-de-datos)
- [Estructura de archivos](#estructura-de-archivos)
- [Instalación paso a paso](#instalación-paso-a-paso)
- [Avances recientes más importantes](#avances-recientes-más-importantes)
- [Notas adicionales](#notas-adicionales)

---

## Descripción general

Sistema completo en **PHP + MySQL + JavaScript**, construido con arquitectura clásica de páginas server-side (sin frameworks), pensado para operar el mantenimiento de equipos de cómputo de un hotel/empresa: desde el registro del inventario y su organización por Departamento/Área, hasta el historial de mantenimientos, tareas del equipo técnico, bajas formales de equipo con dictamen en PDF, y un asistente conversacional con IA que responde preguntas sobre el estado del inventario en tiempo real.

---

## Módulos y funcionalidades

| Módulo | Detalle |
|---|---|
| **Autenticación** | Registro (nombre, cargo, correo, edad, contraseña) · Login con contraseña (bcrypt) · Inicio de sesión con **Google Sign-In** (crea la cuenta automáticamente la primera vez) · Bloqueo de cuentas desactivadas |
| **Roles y permisos** | Roles `admin` / `usuario` · Un usuario puede solicitar el rol de Administrador con justificación · La solicitud se notifica por correo (PHPMailer/SMTP) con enlaces de **Aprobar/Rechazar de un solo uso** · Panel de Gestión de Roles: aprobar, rechazar, otorgar o quitar el rol admin, desactivar/reactivar cuentas, eliminar cuentas permanentemente |
| **Dashboard** | Estadísticas en tiempo real (equipos activos, inactivos, en reparación, mantenimientos, áreas) · Contadores animados · Alertas de mantenimiento próximo a vencer (≤ 7 días) · Gráfica de actividad de los últimos 6 meses · Tareas recientes y últimos mantenimientos registrados |
| **Departamentos y Áreas** | Jerarquía real: un **Departamento** agrupa varias **Áreas** · Tabla expandible (clic para desplegar las áreas de cada departamento) · Alta/baja de departamentos y áreas · Borrado en cascada controlado (ver [Seguridad](#seguridad)) |
| **Inventario de Equipos** | CRUD completo (modelo, marca, número de serie, procesador, RAM, disco, usuario responsable) · **Número de inventario autogenerado** con formato `INV-XXX` (nunca se captura a mano) · Selección encadenada Departamento → Área al registrar o editar un equipo · Filtros por área, estado y orden · Ficha de detalle por equipo |
| **Mantenimientos** | Historial por equipo · Tipo Preventivo / Correctivo · Próxima cita calculada automáticamente a 6 meses · Reagendar cita · Evidencia fotográfica (hasta 5 fotos por registro, JPG/PNG/WEBP/GIF, 3 MB máx.) · Estados "En Proceso" / "Completado" (un mantenimiento completado ya no se puede editar) |
| **Tareas** | Alta de tareas con nombre, descripción, equipo asociado, fecha programada, prioridad y técnico asignado · Estados Pendiente / En Proceso / Realizado / No Realizado · Evidencia fotográfica al completar · Un usuario normal solo gestiona sus propias tareas; el admin gestiona todas |
| **Bajas de Equipos** | Registro de baja con motivo, descripción de falla, diagnóstico técnico, intentos de reparación, costos estimados y recomendación (destrucción, donación, subasta, reciclaje) · Flujo de validación (Pendiente / Validado / Rechazado) · **Dictamen individual en PDF** y **reporte general de bajas en PDF** · Reactivar un equipo dado de baja |
| **Calendario** | Vista consolidada de próximos mantenimientos, mantenimientos realizados y fechas de entrega |
| **Estadísticas** | Equipos por área, bajas por área y gráficas de apoyo a la toma de decisiones |
| **Reportes PDF** | Reporte de equipos, mantenimientos y bajas, listos para imprimir o guardar como PDF |
| **Empleados** | Panel de actividad por técnico: tareas realizadas, mantenimientos atendidos y fotos de evidencia subidas |
| **Configuración de perfil** | Editar datos personales, subir/eliminar foto de perfil, cambiar contraseña, solicitar el rol de administrador |
| **Asistente Zilara (IA)** | Widget de chat flotante conectado a la **API de Google Gemini**, con contexto en tiempo real de equipos, áreas y mantenimientos para responder preguntas sobre el inventario |
| **Identidad de reportes** | Los PDF (equipos, mantenimientos, bajas) aplican automáticamente el logo, colores y datos de la empresa configurados en `ConfiguracionMarca` |

---

## Experiencia de usuario

- Modo oscuro / claro con preferencia guardada por dispositivo.
- Barra lateral colapsable a solo íconos en escritorio, y menú tipo topbar en móvil.
- Guardado, edición y borrado **sin recargar la página** (peticiones AJAX que refrescan solo la zona afectada) con notificaciones tipo toast.
- Modal de confirmación propio para acciones destructivas (reemplaza el `confirm()` nativo del navegador).
- Diseño responsive de escritorio a móvil.

---

## Seguridad

- Contraseñas con **bcrypt** (`password_hash` / `password_verify`).
- Consultas preparadas con **PDO** en toda la aplicación (protección contra inyección SQL).
- Sesiones PHP del lado del servidor; ninguna credencial se guarda en el navegador.
- Verificación server-side del token de Google Sign-In contra el endpoint oficial de Google.
- Enlaces de aprobación de rol por correo protegidos con **token de un solo uso**, marcado atómicamente para evitar doble procesamiento.
- Acciones administrativas protegidas por `requireAdmin()` / `esAdmin()` en cada acción sensible.
- Validación de archivos subidos por tipo MIME real (no por extensión) y tamaño máximo.
- **Borrado en cascada consciente del negocio**: al eliminar un Área o un Departamento se eliminan sus equipos y todo lo que dependa exclusivamente de ellos (tareas, mantenimientos, evidencias fotográficas, archivos en disco incluidos) — **excepto** los equipos que ya tienen un dictamen de Baja registrado, que se conservan íntegros junto con su reporte, por ser un documento permanente.
- Escape de salida con `htmlspecialchars` en toda la interfaz.

---

## Modelo de datos

Tablas principales en MySQL:

`Usuarios` · `Departamentos` · `Areas` · `Equipos` · `Mantenimientos` · `Tareas` · `Bajas` · `EvidenciasEquipo` · `SolicitudesRol` · `ConfiguracionMarca`

La relación jerárquica es `Departamentos (1) → Areas (N) → Equipos (N)`, y desde cada Equipo cuelgan sus `Mantenimientos`, `Tareas` y `EvidenciasEquipo` (fotos ligadas a un Mantenimiento o a una Tarea).

---

## Estructura de archivos

```
sistema-de-mantenimiento/
├── index.php                      Login y registro (+ Google Sign-In)
├── .htaccess                      Enrutamiento (Apache mod_rewrite)
├── database.sql                   Esquema completo + migraciones acumuladas
├── composer.json                  Dependencias PHP (PHPMailer)
│
├── css/
│   └── estilos.css                Estilos de toda la aplicación
│
├── js/
│   ├── ui.js                      Confirmaciones, filtros y guardado por AJAX
│   ├── asistente.js               Widget del Asistente Zilara
│   └── countup.js                 Animación de contadores del Dashboard
│
├── includes/
│   ├── config.php                 Conexión BD, sesión, helpers globales y de seguridad
│   ├── sidebar.php                Menú lateral y widgets globales (tema, chat)
│   ├── lightbox.php               Visor de imágenes reutilizable
│   └── pdf/
│       └── branding.php           Lectura de la identidad visual para los PDF
│
├── pages/
│   ├── dashboard.php               Panel principal
│   ├── equipos.php                 Departamentos, Áreas e Inventario de Equipos
│   ├── mantenimientos.php          Historial de mantenimientos + evidencias
│   ├── tareas.php                  Tareas del equipo técnico
│   ├── bajas.php / formato_baja.php / formato_baja_excel.php   Bajas de equipo y su formato (imprimible y Excel)
│   ├── calendario.php              Calendario de mantenimientos
│   ├── Estadisticas.php            Gráficas y estadísticas
│   ├── reportes.php                Reportes PDF generales
│   ├── empleados.php / empleado_detalle.php   Actividad por técnico
│   ├── admin_roles.php             Gestión de roles y cuentas
│   ├── configuracion.php           Perfil del usuario
│   └── procesar_solicitud_email.php   Aprobar/rechazar rol desde el correo
│
└── actions/
    ├── logout.php                  Cerrar sesión
    ├── google_login.php            Verificación de Google Sign-In
    └── asistente.php                Backend del Asistente Zilara (Gemini API)
```

---

## Instalación paso a paso

### 1. Requisitos
- PHP 8.0 o superior
- MySQL 5.7 o superior
- Servidor Apache con `mod_rewrite` (XAMPP, WAMP, Laragon, etc.)
- Composer (para instalar PHPMailer)

### 2. Copiar archivos
Copia el proyecto completo dentro de la carpeta pública del servidor, por ejemplo con Laragon: `C:/laragon/www/sistema-de-mantenimiento/`.

### 3. Instalar dependencias
```bash
composer install
```

### 4. Crear la base de datos
Abre **phpMyAdmin** (o el cliente de MySQL de tu preferencia) y ejecuta `database.sql`. Esto crea la base de datos, todas las tablas y las migraciones acumuladas del proyecto.

### 5. Configurar `includes/config.php`
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'TU_PASSWORD_AQUI');
define('DB_NAME', 'sistema_mantenimiento');

define('GOOGLE_CLIENT_ID', 'TU_CLIENT_ID.apps.googleusercontent.com');
define('GEMINI_API_KEY', 'TU_API_KEY_DE_GEMINI');

define('SMTP_USER', 'tu_correo@gmail.com');
define('SMTP_PASS', 'tu_contraseña_de_aplicación');
```

### 6. Habilitar `mod_rewrite` (Apache)
Asegura que `AllowOverride All` esté activado en la configuración de tu servidor.

### 7. Acceder al sistema
Abre la URL configurada para el proyecto, por ejemplo: `http://sistema-mantenimiento.test/`.

---

## Avances recientes más importantes

- **Reestructuración de Áreas a jerarquía Departamento → Área.** Antes las "Áreas / Salones" eran una lista plana con un campo de texto libre de ubicación; ahora primero se crean Departamentos, y cada Departamento agrupa sus propias Áreas, reflejado tanto en la base de datos (tabla `Departamentos` + `Areas.id_departamento`) como en una tabla expandible en la interfaz.
- **Número de inventario automático.** Los equipos ya no se registran con un número de inventario escrito a mano: el sistema asigna `INV-XXX` de forma automática y secuencial al guardar.
- **Selección en cascada Departamento → Área** en los formularios de alta y edición de equipos, siempre sincronizada con los datos reales (sin listas obsoletas tras guardar por AJAX).
- **Borrado en cascada seguro.** Eliminar un Área o un Departamento limpia correctamente todo lo que dependía de sus equipos (tareas, mantenimientos, fotos de evidencia en disco), pero protege de forma permanente cualquier equipo con historial de Baja — nunca se pierde un dictamen de baja por una limpieza de inventario.
- **Corrección de evidencias huérfanas.** Se corrigieron rutas donde, al eliminar una Tarea o un Equipo, las fotos de evidencia (archivo físico y registro en base de datos) quedaban huérfanas sin limpiarse.
- **Validación reforzada al registrar mantenimientos**, evitando errores no controlados cuando falta el equipo o la fecha de realización.

---

## Notas adicionales

**¿Cómo se genera un PDF?**
Desde Reportes o desde Bajas, al generar un documento se abre una vista optimizada para impresión. Usa `Ctrl+P` en el navegador → **Guardar como PDF**.

**¿Cómo funciona la alerta de mantenimiento?**
Al registrar un mantenimiento, el sistema calcula automáticamente la próxima cita (6 meses después). El Dashboard muestra una alerta cuando esa fecha está a 7 días o menos, o ya venció.

**Zona horaria:** configurada en `America/Mexico_City` dentro de `includes/config.php`.
