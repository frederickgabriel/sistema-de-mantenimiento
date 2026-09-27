<?php
// =============================================
// ASISTENTE ZILARA — motor con IA (Gemini / Google AI Studio)
// Archivo: actions/asistente.php
// Responde cualquier pregunta usando el modelo Gemini, alimentado en cada
// llamada con un resumen en vivo de los datos reales del sistema (Equipos,
// Áreas, Mantenimientos, Tareas, Bajas, Empleados) y con quién es el usuario
// que pregunta, para que las respuestas sean naturales y no un listado fijo
// de respuestas prearmadas. El único flujo que sigue siendo determinístico
// es "reportar una falla", porque tiene un efecto real (enviar un correo al
// administrador) y conviene que no dependa de que el modelo decida activarlo.
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
if (mb_strlen($preguntaOriginal) > 800) $preguntaOriginal = mb_substr($preguntaOriginal, 0, 800);

function normalizar(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return $s;
}
function tiene(string $q, array $palabras): bool {
    foreach ($palabras as $p) { if (str_contains($q, $p)) return true; }
    return false;
}

$q = normalizar($preguntaOriginal);

$CHIPS_DEFAULT = [
    '¿Cuántos equipos activos hay?',
    '¿Cuáles son mis tareas pendientes?',
    '¿Cómo registro un equipo nuevo?',
    'Quiero reportar un problema',
];

// -----------------------------------------------------------
// Reporte de fallas en dos pasos — se mantiene 100% determinístico (envía un
// correo real), fuera del modelo. Si en el mensaje anterior el bot pidió
// detalles de un problema, este mensaje se toma como la descripción.
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

$disparadoresReporte = ['quiero reportar', 'necesito reportar', 'reportar un problema', 'reportar una falla', 'reportar un error', 'reportar una incidencia', 'reportar un bug', 'levantar un reporte', 'hay un error en', 'encontre un error', 'fallo del sistema', 'no funciona el sistema', 'no funciona la pagina'];
if (tiene($q, $disparadoresReporte)) {
    $descripcion = trim(str_ireplace(['quiero reportar', 'necesito reportar', 'reportar un problema', 'reportar una falla', 'reportar un error', 'reportar una incidencia', 'reportar un bug', 'reportar', 'levantar un reporte'], '', $preguntaOriginal));

    if (mb_strlen($descripcion) >= 15) {
        $u = $db->prepare("SELECT correo, cargo FROM Usuarios WHERE id_usuario=?");
        $u->execute([$miId]);
        $u = $u->fetch();
        $enviado = enviarEmailReporteFalla($nombre, $u['cargo'] ?? '', $u['correo'] ?? '', $preguntaOriginal);
        $reply = $enviado
            ? "Listo, envié tu reporte al administrador. Gracias por avisar 🙏"
            : "Anoté tu reporte, pero no pude enviarlo por correo en este momento. Intenta de nuevo más tarde.";
        echo json_encode(['reply' => $reply, 'chips' => $CHIPS_DEFAULT], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['chat_awaiting_report'] = true;
    echo json_encode(['reply' => "Claro, cuéntame con el mayor detalle posible qué problema tuviste (qué intentabas hacer, qué pasó y en qué pantalla) y se lo envío al administrador por correo.", 'chips' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================
// Resumen en vivo del sistema: se reconstruye en cada pregunta con datos
// reales (respeta la misma regla ya usada en el resto del sistema de excluir
// los equipos dados de Baja de los conteos de inventario/actividad vigente).
// =============================================
function construirContextoSistema(PDO $db, bool $esAdm, int $miId, string $nombre): string {
    $ctx = "Eres el Asistente Zilara, el chatbot integrado en Zilara TechCare, un sistema web de gestión de mantenimiento de equipos de cómputo. ";
    $ctx .= "Respondes SIEMPRE en español, de forma natural, breve y conversacional (nunca como un listado robótico de respuestas fijas). ";
    $ctx .= "Abajo tienes el LISTADO COMPLETO y real de cada tabla del sistema tal como está registrada AHORA MISMO — revísalo entero antes de responder, incluso si la pregunta menciona una fecha, un número de inventario o un nombre específico que no reconozcas a simple vista; búscalo línea por línea en los listados. ";
    $ctx .= "Solo di que algo \"no está registrado\" si de verdad no aparece en ninguno de los listados de abajo — nunca lo digas por asumir sin revisar. Nunca inventes datos que no estén aquí. ";
    $ctx .= "Si preguntan algo fuera de estos datos o de este sistema, dilo con naturalidad y sugiere en qué módulo podrían encontrarlo. No generes HTML ni markdown con símbolos raros; puedes usar **negritas** y viñetas con \"- \" si ayuda a organizar una lista, pero mantenlo simple.\n\n";

    $ctx .= "MÓDULOS DEL SISTEMA Y CÓMO SE USAN:\n";
    $ctx .= "- Dashboard: panel resumen con estadísticas generales, actividad de mantenimientos y progreso de tareas.\n";
    $ctx .= "- Equipos y Áreas: inventario de equipos de cómputo y las áreas/salones donde están ubicados. \"Nuevo Equipo\" y \"Nueva Área\" son solo para Administrador.\n";
    $ctx .= "- Mantenimientos: registro de mantenimientos Preventivos y Correctivos por equipo, con fecha de próxima cita automática (+6 meses) y evidencia fotográfica opcional.\n";
    $ctx .= "- Tareas: pendientes/en proceso/realizadas/no realizadas, con prioridad Alta/Media/Baja; un usuario normal solo ve y crea las suyas, el Administrador puede asignarlas a cualquiera.\n";
    $ctx .= "- Calendario: vista mensual de próximos mantenimientos y mantenimientos ya realizados.\n";
    $ctx .= "- Estadísticas: gráficos de equipos por área, mantenimientos por mes, top equipos con más mantenimientos, etc.\n";
    $ctx .= "- Reportes PDF: genera reportes filtrables de mantenimientos o equipos para descargar/imprimir.\n";
    $ctx .= "- Bajas de Equipos (solo Administrador): da de baja un equipo con motivo, diagnóstico técnico y dictamen PDF; el equipo queda en estado Baja y deja de contar como inventario vigente.\n";
    $ctx .= "- Empleados (solo Administrador): actividad de cada técnico (tareas realizadas, mantenimientos, fotos subidas).\n";
    $ctx .= "- Gestión de Roles (solo Administrador): aprueba/rechaza solicitudes de rol Admin y activa/desactiva/elimina usuarios.\n";
    $ctx .= "- Configuración: cada usuario edita su perfil, foto, contraseña, y puede solicitar el rol de Administrador ahí.\n\n";

    // --- Equipos (listado completo, todas las filas, incluye los dados de Baja marcados como tal) ---
    $equipos = $db->query("SELECT e.numero_inventario, e.modelo, e.marca, e.estado, a.nombre_area FROM Equipos e LEFT JOIN Areas a ON e.id_area=a.id_area ORDER BY e.numero_inventario LIMIT 500")->fetchAll();
    $ctx .= "LISTADO COMPLETO DE EQUIPOS (" . count($equipos) . " en total, incluye los dados de Baja):\n";
    foreach ($equipos as $e) {
        $ctx .= "- {$e['numero_inventario']} | {$e['modelo']} {$e['marca']} | estado: {$e['estado']} | área: " . ($e['nombre_area'] ?? 'sin área') . "\n";
    }

    // --- Áreas ---
    $areas = $db->query("SELECT a.nombre_area, a.ubicacion, (SELECT COUNT(*) FROM Equipos WHERE id_area=a.id_area AND estado!='Baja') c FROM Areas a ORDER BY a.nombre_area")->fetchAll();
    $ctx .= "\nLISTADO COMPLETO DE ÁREAS/SALONES (" . count($areas) . " en total):\n";
    foreach ($areas as $a) {
        $ctx .= "- {$a['nombre_area']}" . ($a['ubicacion'] ? " ({$a['ubicacion']})" : "") . ": {$a['c']} equipo(s) vigente(s)\n";
    }

    // --- Mantenimientos (listado completo) ---
    $mttos = $db->query("
        SELECT m.numero_inventario, e.modelo, m.tipo_mantenimiento, m.fecha_realizacion, m.proximo_mantenimiento, m.estado, u.nombre AS tecnico
        FROM Mantenimientos m
        JOIN Equipos e ON e.numero_inventario=m.numero_inventario
        LEFT JOIN Usuarios u ON u.id_usuario=m.id_tecnico
        ORDER BY m.fecha_realizacion DESC LIMIT 500
    ")->fetchAll();
    $ctx .= "\nLISTADO COMPLETO DE MANTENIMIENTOS (" . count($mttos) . " en total):\n";
    foreach ($mttos as $m) {
        $ctx .= "- Equipo {$m['numero_inventario']} ({$m['modelo']}) | {$m['tipo_mantenimiento']} | realizado: " . fechaES($m['fecha_realizacion']) . " | próximo: " . ($m['proximo_mantenimiento'] ? fechaES($m['proximo_mantenimiento']) : 'sin definir') . " | estado: {$m['estado']} | técnico: " . ($m['tecnico'] ?? 'sin asignar') . "\n";
    }

    // --- Tareas: el Administrador ve TODAS, un usuario normal solo ve las suyas (mismo límite que la página Tareas) ---
    if ($esAdm) {
        $tareas = $db->query("
            SELECT t.nombre_tarea, t.estado, t.prioridad, t.fecha_programada, t.fecha_completado, t.numero_inventario, u.nombre AS asignado
            FROM Tareas t LEFT JOIN Usuarios u ON u.id_usuario=t.id_usuario_asignado
            ORDER BY t.fecha_programada ASC LIMIT 500
        ")->fetchAll();
        $ctx .= "\nLISTADO COMPLETO DE TAREAS DE TODO EL SISTEMA (" . count($tareas) . " en total):\n";
    } else {
        $stmtT = $db->prepare("
            SELECT t.nombre_tarea, t.estado, t.prioridad, t.fecha_programada, t.fecha_completado, t.numero_inventario, ? AS asignado
            FROM Tareas t WHERE t.id_usuario_asignado=?
            ORDER BY t.fecha_programada ASC LIMIT 500
        ");
        $stmtT->execute([$nombre, $miId]);
        $tareas = $stmtT->fetchAll();
        $ctx .= "\nLISTADO COMPLETO DE TAREAS ASIGNADAS A ESTE USUARIO (" . count($tareas) . " en total):\n";
    }
    foreach ($tareas as $t) {
        $ctx .= "- {$t['nombre_tarea']} | estado: {$t['estado']} | prioridad: {$t['prioridad']} | fecha programada: " . ($t['fecha_programada'] ? fechaES($t['fecha_programada']) : 'sin fecha') . ($t['fecha_completado'] ? " | completada: " . fechaES($t['fecha_completado']) : "") . " | asignada a: " . ($t['asignado'] ?? 'sin asignar') . ($t['numero_inventario'] ? " | equipo: {$t['numero_inventario']}" : "") . "\n";
    }
    if (!$tareas) $ctx .= "(sin tareas registradas)\n";

    if ($esAdm) {
        // --- Bajas ---
        $bajas = $db->query("SELECT numero_inventario, motivo_baja, estado_validacion, fecha_baja FROM Bajas ORDER BY fecha_baja DESC LIMIT 300")->fetchAll();
        $ctx .= "\nLISTADO COMPLETO DE BAJAS DE EQUIPOS (" . count($bajas) . " en total):\n";
        foreach ($bajas as $b) {
            $ctx .= "- Equipo {$b['numero_inventario']} | motivo: {$b['motivo_baja']} | validación: {$b['estado_validacion']} | fecha: " . fechaES($b['fecha_baja']) . "\n";
        }
        if (!$bajas) $ctx .= "(sin bajas registradas)\n";

        // --- Empleados ---
        $empleados = $db->query("SELECT nombre, cargo, correo, activo, rol FROM Usuarios ORDER BY rol DESC, nombre")->fetchAll();
        $ctx .= "\nLISTADO COMPLETO DE USUARIOS (" . count($empleados) . " en total):\n";
        foreach ($empleados as $u) {
            $ctx .= "- {$u['nombre']} ({$u['cargo']}) | correo: {$u['correo']} | rol: {$u['rol']} | " . ($u['activo'] ? 'activo' : 'desactivado') . "\n";
        }

        // --- Solicitudes de rol pendientes ---
        $solPend = (int)$db->query("SELECT COUNT(*) FROM SolicitudesRol WHERE estado='Pendiente'")->fetchColumn();
        $ctx .= "\nSolicitudes de rol Administrador pendientes de revisar: {$solPend}.\n";
    }

    $ctx .= "\nFecha de hoy en el sistema: " . fechaES(date('Y-m-d')) . ".\n";
    $ctx .= "USUARIO QUE PREGUNTA: {$nombre}, rol " . ($esAdm ? 'Administrador' : 'Usuario') . ".\n";
    $ctx .= "Si el usuario pide reportar una falla o un problema del sistema, dile que con gusto, que te cuente los detalles — ese flujo ya lo maneja el sistema por fuera, tú solo debes sonar dispuesto a ayudar.";

    return $ctx;
}

// =============================================
// Llamada a Gemini (Google AI Studio)
// =============================================
function llamarGemini(string $systemInstruction, array $turnos): ?string {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
        'contents' => $turnos,
        'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 1024],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Mismo bundle de CA que usa verificarTokenGoogle() — evita fallos de
    // verificación SSL típicos de Windows/Laragon (curl error 60).
    $bundleCA = __DIR__ . '/../includes/cacert.pem';

    // Google a veces responde 503 "alta demanda" de forma pasajera — se
    // reintenta un par de veces con una pausa corta antes de rendirse.
    $intentos = 3;
    for ($i = 1; $i <= $intentos; $i++) {
        $ch = curl_init($url);
        $opciones = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if (is_file($bundleCA)) $opciones[CURLOPT_CAINFO] = $bundleCA;
        curl_setopt_array($ch, $opciones);
        $respuesta = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            error_log('llamarGemini: fallo de cURL - ' . $curlError);
            return null;
        }
        $data = json_decode($respuesta, true);

        if ($httpCode === 200 && is_array($data)) {
            $texto = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            return is_string($texto) && trim($texto) !== '' ? trim($texto) : null;
        }

        error_log("llamarGemini: intento {$i}/{$intentos} — HTTP {$httpCode} - {$respuesta}");
        $reintentable = in_array($httpCode, [429, 500, 503], true);
        if (!$reintentable || $i === $intentos) return null;
        usleep(600000); // 0.6s antes de reintentar
    }
    return null;
}

// Da formato seguro a la respuesta del modelo: escapa cualquier HTML que
// haya podido colarse (el texto del usuario viaja dentro del prompt) y solo
// después reintroduce **negritas** y viñetas ya controladas — nunca se
// inyecta HTML crudo del modelo directamente en la página.
function formatearRespuestaIA(string $texto): string {
    $escapado = e($texto);
    $escapado = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escapado);
    $escapado = preg_replace('/^[-*]\s+/m', '• ', $escapado);
    return $escapado;
}

// =============================================
// Construir el historial de turnos para el modelo: mensajes previos de esta
// conversación (enviados por el cliente, ya en texto plano) + la pregunta
// actual. Se limita a los últimos 10 para no disparar el costo/latencia.
// =============================================
$turnos = [];
$historialRaw = $_POST['history'] ?? '';
if ($historialRaw !== '') {
    $historial = json_decode($historialRaw, true);
    if (is_array($historial)) {
        foreach (array_slice($historial, -10) as $m) {
            if (empty($m['text']) || !isset($m['role'])) continue;
            $rol = $m['role'] === 'user' ? 'user' : 'model';
            $turnos[] = ['role' => $rol, 'parts' => [['text' => mb_substr((string)$m['text'], 0, 1000)]]];
        }
    }
}
$turnos[] = ['role' => 'user', 'parts' => [['text' => $preguntaOriginal]]];

$contexto = construirContextoSistema($db, $esAdm, $miId, $nombre);
$textoIA  = llamarGemini($contexto, $turnos);

if ($textoIA !== null) {
    $reply = formatearRespuestaIA($textoIA);
} else {
    $reply = "Tuve un problema para conectarme con el asistente de IA en este momento. Intenta de nuevo en unos segundos, o si el problema sigue, revisa la configuración de la API en el sistema.";
}

echo json_encode(['reply' => $reply, 'chips' => $CHIPS_DEFAULT], JSON_UNESCAPED_UNICODE);
