<?php
// =============================================
// ASISTENTE ZILARA — motor local basado en reglas
// Archivo: actions/asistente.php
// Responde preguntas sobre los datos reales del sistema (Equipos, Tareas,
// Mantenimientos, Bajas, Empleados, Áreas), sobre cómo usar el sistema, y
// puede levantar reportes de fallas que se envían por correo al admin.
// No usa ninguna IA externa: motor de intents por palabras clave + consultas
// preparadas contra la misma base de datos que ya usa el resto de la app.
// =============================================
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['reply' => 'Tu sesión expiró. Vuelve a iniciar sesión para usar el asistente.', 'chips' => []]);
    exit;
}

$db     = getDB();
$miId   = (int)$_SESSION['usuario']['id'];
$esAdm  = esAdmin();
$nombre = $_SESSION['usuario']['nombre'] ?? '';

$preguntaOriginal = trim($_POST['q'] ?? '');
if ($preguntaOriginal === '') {
    echo json_encode(['reply' => 'Escribe una pregunta y con gusto te ayudo 🙂', 'chips' => []]);
    exit;
}
if (mb_strlen($preguntaOriginal) > 500) $preguntaOriginal = mb_substr($preguntaOriginal, 0, 500);

function normalizar(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return $s;
}
function tiene(string $q, array $palabras): bool {
    foreach ($palabras as $p) { if (str_contains($q, $p)) return true; }
    return false;
}
// Frases típicas de "¿cómo hago X?" / "no sé cómo..." / "no puedo..." — para las FAQ de uso del sistema.
function esConsultaComo(string $q): bool {
    return tiene($q, ['como ', 'no se como', 'no puedo', 'no me deja', 'donde ', 'ayuda con', 'como hago', 'como le hago', 'que hago para']);
}

$q = normalizar($preguntaOriginal);

$CHIPS_DEFAULT = [
    '¿Cuántos equipos activos hay?',
    '¿Cuáles son mis tareas pendientes?',
    '¿Cómo registro un equipo nuevo?',
    'Quiero reportar un problema',
];
$reply = null;

// -----------------------------------------------------------
// Reporte de fallas en dos pasos: si en el mensaje anterior el bot pidió
// detalles de un problema, este mensaje se toma como la descripción y se
// envía por correo al admin, sin volver a pasar por el motor de intents.
// -----------------------------------------------------------
if (!empty($_SESSION['chat_awaiting_report'])) {
    unset($_SESSION['chat_awaiting_report']);

    if (tiene($q, ['cancelar', 'olvidalo', 'olvídalo', 'ya no', 'mejor no', 'nada']) && mb_strlen($preguntaOriginal) < 20) {
        echo json_encode(['reply' => 'Sin problema, cancelé el reporte. ¿En qué más te ayudo?', 'chips' => $CHIPS_DEFAULT], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $u = $db->prepare("SELECT correo, cargo FROM Usuarios WHERE id_usuario=?");
    $u->execute([$miId]);
    $u = $u->fetch();
    $enviado = enviarEmailReporteFalla($nombre, $u['cargo'] ?? '', $u['correo'] ?? '', $preguntaOriginal);
    $reply = $enviado
        ? "Listo, envié tu reporte al administrador. Gracias por avisar 🙏"
        : "Anoté tu reporte, pero no pude enviarlo por correo en este momento. Intenta de nuevo más tarde o avisa directamente a un administrador.";
    echo json_encode(['reply' => $reply, 'chips' => $CHIPS_DEFAULT], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------
// Intenta extraer un número de inventario mencionado en la pregunta
// buscándolo directamente contra la tabla Equipos (evita falsos positivos
// con un simple regex, ya que el formato de inventario no está fijo).
// -----------------------------------------------------------
function buscarInventarioMencionado(PDO $db, string $preguntaOriginal): ?array {
    $palabras = preg_split('/\s+/', $preguntaOriginal);
    foreach ($palabras as $palabra) {
        $palabra = trim($palabra, ".,;:¿?¡!()");
        if (mb_strlen($palabra) < 3) continue;
        $stmt = $db->prepare("SELECT * FROM Equipos WHERE numero_inventario LIKE ? LIMIT 1");
        $stmt->execute(["%{$palabra}%"]);
        $eq = $stmt->fetch();
        if ($eq) return $eq;
    }
    return null;
}

// =============================================
// INTENTS — se evalúan en orden, gana el primero que matchee
// =============================================
$intents = [];

// --- Saludo / ayuda ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'que puedes hacer', 'en que me ayudas', 'ayudame', 'ayuda']) && !tiene($q, ['contrasena', 'password']),
    'run' => function () use ($nombre) {
        $saludo = $nombre ? "¡Hola, {$nombre}! 👋" : "¡Hola! 👋";
        return "{$saludo} Soy el asistente de Zilara TechCare. Puedo contarte sobre tus equipos, tareas, mantenimientos" .
               " y bajas, explicarte cómo usar el sistema paso a paso, o levantar un reporte si algo falló." .
               " Prueba con alguna de las preguntas sugeridas abajo, o escribe la tuya.";
    },
];

// --- Quién soy / mi rol ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['quien soy', 'mi rol', 'mi perfil', 'mi cuenta', 'mi correo', 'mi cargo']),
    'run' => function () use ($nombre, $esAdm, $db, $miId) {
        $u = $db->prepare("SELECT correo, cargo FROM Usuarios WHERE id_usuario=?");
        $u->execute([$miId]);
        $u = $u->fetch();
        $rol = $esAdm ? 'Administrador' : 'Usuario';
        return "Estás conectado como <strong>{$nombre}</strong> ({$u['cargo']}), correo {$u['correo']}. Tu rol en el sistema es <strong>{$rol}</strong>.";
    },
];

// --- Reportar una falla / incidencia técnica (se envía por correo al admin) ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['reportar', 'reporte de falla', 'reporte de error', 'levantar un reporte', 'incidencia', ' bug', 'algo no funciona', 'no funciona el sistema', 'no funciona la pagina', 'encontre un error', 'hay un error en', 'fallo del sistema']),
    'run' => function () use ($db, $miId, $nombre, $preguntaOriginal) {
        $disparadores = ['quiero reportar', 'necesito reportar', 'reportar un problema', 'reportar una falla', 'reportar un error', 'reportar una incidencia', 'reportar un bug', 'reportar', 'levantar un reporte'];
        $descripcion = trim(str_ireplace($disparadores, '', $preguntaOriginal));

        if (mb_strlen($descripcion) >= 15) {
            $u = $db->prepare("SELECT correo, cargo FROM Usuarios WHERE id_usuario=?");
            $u->execute([$miId]);
            $u = $u->fetch();
            $enviado = enviarEmailReporteFalla($nombre, $u['cargo'] ?? '', $u['correo'] ?? '', $preguntaOriginal);
            return $enviado
                ? "Listo, envié tu reporte al administrador. Gracias por avisar 🙏"
                : "Anoté tu reporte, pero no pude enviarlo por correo en este momento. Intenta de nuevo más tarde.";
        }

        $_SESSION['chat_awaiting_report'] = true;
        return "Claro, cuéntame con el mayor detalle posible qué problema tuviste (qué intentabas hacer, qué pasó y en qué pantalla) y se lo envío al administrador por correo.";
    },
];

// --- Conteo / listado de equipos por estado ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['equipo']) && tiene($q, ['cuant', 'cantidad', 'numero de', 'total de']),
    'run' => function () use ($db, $q) {
        $estados = ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'reparacion' => 'En Reparacion', 'baja' => 'Baja'];
        foreach ($estados as $clave => $valor) {
            if (str_contains($q, $clave)) {
                $c = $db->prepare("SELECT COUNT(*) FROM Equipos WHERE estado=?");
                $c->execute([$valor]);
                return "Hay <strong>{$c->fetchColumn()}</strong> equipo(s) en estado <strong>{$valor}</strong>.";
            }
        }
        $total = (int)$db->query("SELECT COUNT(*) FROM Equipos")->fetchColumn();
        $rows = $db->query("SELECT estado, COUNT(*) c FROM Equipos GROUP BY estado")->fetchAll();
        $detalle = implode(', ', array_map(fn($r) => "{$r['estado']}: {$r['c']}", $rows));
        return "En total hay <strong>{$total}</strong> equipos registrados ({$detalle}).";
    },
];

// --- Detalle / historial de un equipo específico (por número de inventario) ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['equipo', 'inventario']) && tiene($q, ['detalle', 'informacion', 'historial', 'estado del', 'ver equipo']),
    'run' => function () use ($db, $preguntaOriginal) {
        $eq = buscarInventarioMencionado($db, $preguntaOriginal);
        if (!$eq) return "No encontré ningún equipo con ese número de inventario. Revisa el módulo de Equipos y Áreas para ver la lista completa.";
        $mttos = $db->prepare("SELECT COUNT(*) FROM Mantenimientos WHERE numero_inventario=?");
        $mttos->execute([$eq['numero_inventario']]);
        return "<strong>{$eq['numero_inventario']}</strong> — {$eq['modelo']} ({$eq['marca']}). Estado: <strong>{$eq['estado']}</strong>. " .
               "Tiene {$mttos->fetchColumn()} mantenimiento(s) registrado(s). Puedes ver el detalle completo en el módulo de Mantenimientos.";
    },
];

// --- Equipos con mantenimiento próximo a vencer ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['mantenimiento']) && tiene($q, ['proximo', 'pronto', 'vencer', 'vence', 'por hacer', 'programado']),
    'run' => function () use ($db, $esAdm, $miId) {
        $sql = "SELECT m.numero_inventario, e.modelo, m.proximo_mantenimiento
                FROM Mantenimientos m JOIN Equipos e ON e.numero_inventario=m.numero_inventario
                WHERE m.proximo_mantenimiento IS NOT NULL AND m.estado='Completado'
                  AND m.proximo_mantenimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)" .
                (!$esAdm ? " AND m.id_tecnico=?" : "") .
                " ORDER BY m.proximo_mantenimiento ASC LIMIT 5";
        $stmt = $db->prepare($sql);
        $stmt->execute($esAdm ? [] : [$miId]);
        $rows = $stmt->fetchAll();
        if (!$rows) return "No hay mantenimientos próximos a vencer en los siguientes 30 días. 🎉";
        $lista = implode("\n", array_map(fn($r) => "• {$r['numero_inventario']} ({$r['modelo']}) — " . fechaES($r['proximo_mantenimiento']), $rows));
        return "Estos equipos tienen mantenimiento próximo:\n{$lista}";
    },
];

// --- Historial de mantenimientos de un equipo ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['mantenimiento']) && tiene($q, ['historial', 'cuantos mantenimientos', 'ultimo mantenimiento']),
    'run' => function () use ($db, $preguntaOriginal) {
        $eq = buscarInventarioMencionado($db, $preguntaOriginal);
        if (!$eq) return "Dime el número de inventario del equipo y te digo su historial de mantenimientos. Ej: \"historial de mantenimientos del equipo INV-001\".";
        $stmt = $db->prepare("SELECT tipo_mantenimiento, fecha_realizacion, estado FROM Mantenimientos WHERE numero_inventario=? ORDER BY fecha_realizacion DESC LIMIT 5");
        $stmt->execute([$eq['numero_inventario']]);
        $rows = $stmt->fetchAll();
        if (!$rows) return "El equipo {$eq['numero_inventario']} no tiene mantenimientos registrados todavía.";
        $lista = implode("\n", array_map(fn($r) => "• {$r['tipo_mantenimiento']} — " . fechaES($r['fecha_realizacion']) . " ({$r['estado']})", $rows));
        return "Últimos mantenimientos de <strong>{$eq['numero_inventario']}</strong>:\n{$lista}";
    },
];

// --- FAQ: cómo marcar un mantenimiento como completado (antes que la FAQ genérica de mantenimientos) ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['mantenimiento']) && tiene($q, ['marc', 'complet', 'dar por terminado', 'como termino']),
    'run' => fn() => "Para marcar un mantenimiento como completado: ve a <strong>Mantenimientos</strong>, busca el registro y pulsa el ícono de check (\"Marcar como completado\"). Una vez completado ya no se puede editar, pero sí reagendar la próxima cita o eliminar el registro.",
];

// --- Mis tareas pendientes / en proceso / alta prioridad ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['tarea']) && tiene($q, ['pendiente', 'en proceso', 'mis tareas', 'que tengo que hacer', 'prioridad']),
    'run' => function () use ($db, $esAdm, $miId, $q) {
        $conds = []; $params = [];
        if (tiene($q, ['alta prioridad', 'urgente'])) { $conds[] = "t.prioridad='Alta'"; }
        if (tiene($q, ['en proceso'])) { $conds[] = "t.estado='En Proceso'"; }
        elseif (tiene($q, ['pendiente'])) { $conds[] = "t.estado='Pendiente'"; }
        else { $conds[] = "t.estado IN ('Pendiente','En Proceso')"; }
        if (!$esAdm || !tiene($q, ['todas', 'de todos', 'del equipo'])) { $conds[] = "t.id_usuario_asignado=?"; $params[] = $miId; }
        $sql = "SELECT nombre_tarea, prioridad, fecha_programada FROM Tareas t WHERE " . implode(' AND ', $conds) . " ORDER BY FIELD(prioridad,'Alta','Media','Baja'), fecha_programada ASC LIMIT 6";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) return "No tienes tareas pendientes en esas condiciones. ¡Vas al día! ✅";
        $lista = implode("\n", array_map(fn($r) => "• {$r['nombre_tarea']} (prioridad {$r['prioridad']})" . ($r['fecha_programada'] ? ' — ' . fechaES($r['fecha_programada']) : ''), $rows));
        return "Estas son tus tareas:\n{$lista}";
    },
];

// --- Bajas pendientes de validar (admin) ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['baja']) && tiene($q, ['pendiente', 'validar', 'cuant']),
    'run' => function () use ($db, $esAdm) {
        if (!$esAdm) return "Solo un Administrador puede consultar las bajas pendientes de validar. Si necesitas ese acceso, pídelo desde Configuración → Solicitar rol de Administrador.";
        $c = (int)$db->query("SELECT COUNT(*) FROM Bajas WHERE estado_validacion='Pendiente'")->fetchColumn();
        if ($c === 0) return "No hay bajas pendientes de validar en este momento.";
        return "Hay <strong>{$c}</strong> baja(s) pendiente(s) de validar. Puedes revisarlas en el módulo de Bajas de Equipos.";
    },
];

// --- Empleados activos / por cargo (admin) ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['empleado', 'usuarios']) && tiene($q, ['cuant', 'cantidad', 'activo']),
    'run' => function () use ($db, $esAdm) {
        if (!$esAdm) return "Solo un Administrador puede consultar la lista de empleados. Si necesitas ese acceso, pídelo desde Configuración → Solicitar rol de Administrador.";
        $c = (int)$db->query("SELECT COUNT(*) FROM Usuarios WHERE rol='usuario' AND activo=1")->fetchColumn();
        return "Hay <strong>{$c}</strong> empleado(s) activo(s) registrados en el sistema.";
    },
];

// --- Áreas y equipos por área ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['area']) && tiene($q, ['cuant', 'cuales', 'lista', 'equipos tiene']),
    'run' => function () use ($db) {
        $rows = $db->query("SELECT a.nombre_area, COUNT(e.numero_inventario) c FROM Areas a LEFT JOIN Equipos e ON e.id_area=a.id_area GROUP BY a.id_area ORDER BY a.nombre_area")->fetchAll();
        if (!$rows) return "Todavía no hay áreas registradas. Puedes crear una desde el módulo de Equipos y Áreas.";
        $lista = implode("\n", array_map(fn($r) => "• {$r['nombre_area']}: {$r['c']} equipo(s)", $rows));
        return "Áreas registradas:\n{$lista}";
    },
];

// --- FAQ: cómo dar de baja un equipo (antes que "registrar equipo" para no chocar con la palabra "equipo") ---
$intents[] = [
    'match' => fn($q) => esConsultaComo($q) && tiene($q, ['baja']) && tiene($q, ['equipo']),
    'run' => fn() => "Dar de baja un equipo es distinto a eliminarlo: ve a <strong>Bajas de Equipos</strong> (solo Administrador) → \"Registrar Baja\" → indica el motivo, diagnóstico técnico y recomendación → Guardar. El equipo pasa a estado <strong>Baja</strong> automáticamente.",
];

// --- FAQ: cómo registrar un equipo ---
$intents[] = [
    'match' => fn($q) => esConsultaComo($q) && tiene($q, ['equipo']) && !tiene($q, ['baja']),
    'run' => fn() => "Para registrar un equipo: ve a <strong>Equipos y Áreas</strong> → botón \"Nuevo Equipo\" → completa número de inventario, modelo y los demás datos → Guardar. Solo el Administrador puede registrar equipos nuevos.",
];

// --- FAQ: cómo registrar/reagendar un mantenimiento ---
$intents[] = [
    'match' => fn($q) => esConsultaComo($q) && tiene($q, ['mantenimiento']),
    'run' => fn() => "Para registrar un mantenimiento: ve a <strong>Mantenimientos</strong> → \"Nuevo Mantenimiento\" → elige el equipo, tipo (Preventivo o Correctivo), fecha y detalles → Guardar. Para reagendar la próxima cita usa el botón de reagendar en la fila del mantenimiento.",
];

// --- FAQ: cómo crear/asignar una tarea (incluye "no sé cómo guardar una tarea") ---
$intents[] = [
    'match' => fn($q) => esConsultaComo($q) && tiene($q, ['tarea']),
    'run' => fn() => "Para crear una tarea: ve a <strong>Tareas</strong> → \"Nueva Tarea\" → escribe el nombre, elige prioridad y equipo (opcional) → Guardar. Solo el Administrador puede asignarla a otro empleado; un usuario normal la crea para sí mismo.",
];

// --- FAQ: cómo pedir rol admin ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['rol de administrador', 'ser administrador', 'ser admin', 'pedir admin', 'solicitar admin']) || (esConsultaComo($q) && tiene($q, ['admin'])),
    'run' => fn() => "Para solicitar el rol de Administrador ve a <strong>Configuración</strong> → pestaña de Rol/Permisos → \"Solicitar rol de Administrador\", escribe tu justificación y envíala. Un administrador la revisará y podrá aprobarla o rechazarla desde Gestión de Roles.",
];

// --- FAQ: cómo cambiar foto/contraseña/datos de perfil ---
$intents[] = [
    'match' => fn($q) => esConsultaComo($q) && tiene($q, ['foto', 'contrasena', 'password', 'perfil', 'telefono']),
    'run' => fn() => "Todo eso se hace desde <strong>Configuración</strong>: ahí puedes cambiar tu foto de perfil, tu contraseña, teléfono y demás datos personales.",
];

// --- FAQ: cómo generar reporte PDF ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['reporte', 'pdf']) && (esConsultaComo($q) || tiene($q, ['generar', 'descargar', 'imprimir'])),
    'run' => fn() => "Ve al módulo de <strong>Reportes PDF</strong>, elige los filtros que necesites (equipo, técnico, fechas) y pulsa \"Generar reporte\" para descargarlo o imprimirlo.",
];

// --- FAQ: glosario de estados ---
$intents[] = [
    'match' => fn($q) => tiene($q, ['que significa', 'que es el estado', 'estados de']),
    'run' => fn() => "Estados de <strong>Equipos</strong>: Activo, Inactivo, En Reparación, Baja.\n" .
                     "Estados de <strong>Tareas</strong>: Pendiente, En Proceso, Realizado, No Realizado.\n" .
                     "Estados de <strong>Mantenimientos</strong>: En Proceso, Completado.\n" .
                     "Estados de <strong>Bajas</strong>: Pendiente, Validado, Rechazado.",
];

// =============================================
// Ejecutar el primer intent que matchee
// =============================================
foreach ($intents as $intent) {
    if ($intent['match']($q)) {
        try {
            $reply = $intent['run']();
        } catch (Throwable $e) {
            $reply = "Tuve un problema consultando esos datos. Intenta de nuevo en un momento.";
        }
        break;
    }
}

if ($reply === null) {
    $reply = "No estoy seguro de haber entendido tu pregunta 🤔. Puedo ayudarte con equipos, tareas, mantenimientos, bajas y empleados, explicarte cómo usar el sistema, o levantar un reporte si algo falló. Prueba con una de estas preguntas:";
}

echo json_encode(['reply' => $reply, 'chips' => $CHIPS_DEFAULT], JSON_UNESCAPED_UNICODE);
