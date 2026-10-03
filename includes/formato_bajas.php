<?php
// =============================================
// FORMATO INSTITUCIONAL DE BAJAS (BAJA DE ACTIVOS / BAJA DE EQUIPO OPERACIÓN)
// Archivo: includes/formato_bajas.php
// Lógica compartida por pages/formato_baja.php (vista imprimible) y
// pages/formato_baja_excel.php (descarga .xlsx).
//
// El Excel se genera rellenando la plantilla original includes/plantillas/formato_bajas.xlsx
// (con ZipArchive, sin Composer), así conserva exactamente logo, bordes, firmas y
// área de impresión. Para cambiar nombres de firmas o textos fijos basta con
// reemplazar esa plantilla (y ajustar FB_FIRMAS para la vista imprimible).
// =============================================

const FB_PLANTILLA     = __DIR__ . '/plantillas/formato_bajas.xlsx';
const FB_PRIMER_RENGLON = 17;   // primera fila de artículos en la plantilla
const FB_RENGLONES_BASE = 28;   // la plantilla trae las filas 17..44; el Excel se ajusta al número real de equipos

const FB_EMPRESA = ['Hyatt Zilara Riviera Maya', 'Hotel CAPRI Caribe S de RL de CV.'];

// Mismos nombres que trae la plantilla .xlsx (filas 47 y 49)
const FB_FIRMAS = [
    'solicita'  => ['titulo' => 'SOLICITADO POR',    'nombre' => 'ALEJANDRO MATHEIS', 'cargo' => 'JEFE DEPARTAMENTO'],
    'costos'    => ['titulo' => 'VERIFICADO COSTOS', 'nombre' => 'OSCAR VERDUZCO',    'cargo' => 'CONTRALOR DE COSTOS'],
    'seguridad' => ['titulo' => 'VALIDO',            'nombre' => '',                  'cargo' => 'JEFE DE SEGURIDAD'],
    'finanzas'  => ['titulo' => 'AUTORIZA',          'nombre' => 'CLAUDIA BAUTISTA',  'cargo' => 'SUBCONT. FINANZAS'],
    'gerente'   => ['titulo' => 'AUTORIZA',          'nombre' => 'XAVIER MANTECON',   'cargo' => 'GERENTE GENERAL'],
];

const FB_NOTAS = [
    '* SE DEBERAN ANEXAR LAS FOTOS DE SOPORTE PARA LA VERIFICACION FISICA.',
    '*EL FORMATO DEBERA DE ESTAR LLENO INCLUYENDO LO PRECIOS TOTALES.',
    '*UNA VEZ FIRMADO EL FORMATO REGRESAR EL ORIGINAL A COSTOS Y DEPARTAMENTO SOLICITADO SE QUEDA CON COPIA.',
    '*DEFINIR EL USO FINAL DE LOS ACTIVOS QUE SE DARAN DE BAJA (DONACION, VENTA, RECICLAJE, ETC.)',
];

/** Lee los datos de encabezado del formato desde la query string (con valores por defecto). */
function fbParametros(): array {
    // ids puede venir como lista separada por comas (ids=1,2,3: sin límite práctico de longitud)
    // o como arreglo (ids[]=1&ids[]=2)
    $ids = $_GET['ids'] ?? ($_GET['id'] ?? []);
    if (is_string($ids)) $ids = explode(',', $ids);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));

    $fecha = $_GET['fecha'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
    $verif = $_GET['fecha_verificacion'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $verif)) $verif = '';

    $tipo = $_GET['tipo'] ?? 'activos';
    if (!in_array($tipo, ['activos', 'operacion', ''], true)) $tipo = 'activos';

    return [
        'ids'                => $ids,
        'tipo'               => $tipo,
        'fecha'              => $fecha,
        'fecha_verificacion' => $verif,
        'depto'              => mb_strtoupper(trim($_GET['depto'] ?? '') ?: 'SISTEMAS'),
        'ubicacion'          => mb_strtoupper(trim($_GET['ubicacion'] ?? '') ?: 'STAFF'),
        'observaciones'      => trim($_GET['observaciones'] ?? ''),
    ];
}

/** Query string para pasar el mismo formato entre la vista imprimible y el Excel. */
function fbQuery(array $p): string {
    return http_build_query([
        'ids'                => implode(',', $p['ids']),
        'tipo'               => $p['tipo'],
        'fecha'              => $p['fecha'],
        'fecha_verificacion' => $p['fecha_verificacion'],
        'depto'              => $p['depto'],
        'ubicacion'          => $p['ubicacion'],
        'observaciones'      => $p['observaciones'],
    ]);
}

/** Convierte las bajas seleccionadas en los renglones del formato. */
function fbRenglones(PDO $db, array $ids): array {
    if (!$ids) return [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT b.id_baja, b.numero_inventario, b.motivo_baja, b.valor_actual_estimado,
               e.marca, e.modelo, e.numero_serie
        FROM Bajas b
        JOIN Equipos e ON e.numero_inventario = b.numero_inventario
        WHERE b.id_baja IN ($marcas)
        ORDER BY b.fecha_baja, b.id_baja
    ");
    $stmt->execute($ids);

    $renglones = [];
    foreach ($stmt->fetchAll() as $b) {
        $desc = trim(($b['marca'] ?? '') . ' ' . $b['modelo']);
        if (!empty($b['numero_serie'])) $desc .= ' S/N ' . $b['numero_serie'];
        $costo = $b['valor_actual_estimado'] !== null ? (float)$b['valor_actual_estimado'] : null;
        $renglones[] = [
            'codigo'        => $b['numero_inventario'],
            'cantidad'      => 1,
            'descripcion'   => mb_strtoupper($desc),
            'costo'         => $costo,
            'total'         => $costo ?? 0.0,
            'observaciones' => mb_strtoupper($b['motivo_baja']),
        ];
    }
    return $renglones;
}

// ---------------------------------------------
// Generación del .xlsx a partir de la plantilla
// ---------------------------------------------

function fbXmlTexto(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Reemplaza el contenido de una celda existente de la hoja conservando su estilo (s="N").
 * $valor: null = vacía, string = texto, int/float = número, ['f' => fórmula, 'v' => valor] = fórmula.
 * $estilo: índice de estilo de la plantilla para forzar uno distinto al que trae la celda.
 */
function fbCelda(string $xml, string $ref, mixed $valor, ?int $estilo = null): string {
    return preg_replace_callback(
        '#<c r="' . $ref . '"((?: [a-z]+="[^"]*")*)(?:/>|>.*?</c>)#s',
        function ($m) use ($ref, $valor, $estilo) {
            if ($estilo === null && preg_match('# s="(\d+)"#', $m[1], $s)) $estilo = (int)$s[1];
            $estilo = $estilo !== null ? " s=\"{$estilo}\"" : '';
            if ($valor === null || $valor === '') return "<c r=\"{$ref}\"{$estilo}/>";
            if (is_array($valor)) {
                return "<c r=\"{$ref}\"{$estilo}><f>" . fbXmlTexto($valor['f']) . '</f><v>' . (0 + $valor['v']) . '</v></c>';
            }
            if (is_int($valor) || is_float($valor)) return "<c r=\"{$ref}\"{$estilo}><v>{$valor}</v></c>";
            return "<c r=\"{$ref}\"{$estilo} t=\"inlineStr\"><is><t xml:space=\"preserve\">" . fbXmlTexto($valor) . '</t></is></c>';
        },
        $xml,
        1
    );
}

/** Fecha Y-m-d → número de serie de Excel. */
function fbSerialExcel(string $fecha): int {
    $dt = new DateTime($fecha . ' 00:00:00', new DateTimeZone('UTC'));
    return intdiv($dt->getTimestamp(), 86400) + 25569;
}

/**
 * Ajusta la hoja de la plantilla al número real de equipos ($n): genera exactamente $n filas de
 * artículos y desplaza hacia arriba/abajo el pie (total, firmas, observaciones). Devuelve el
 * XML de la hoja y el desplazamiento aplicado (para ajustar el área de impresión y la línea
 * vertical de firmas).
 */
function fbAjustarFilas(string $hoja, int $n): array {
    $delta = $n - FB_RENGLONES_BASE;
    $pieDesde = FB_PRIMER_RENGLON + FB_RENGLONES_BASE; // fila 45: "TOTAL $"

    $hoja = preg_replace_callback('#<row r="(\d+)".*?</row>#s', function ($m) use ($n, $delta, $pieDesde) {
        $fila = (int)$m[1];
        if ($fila < FB_PRIMER_RENGLON) return $m[0];            // encabezado: igual
        if ($fila < $pieDesde) {                                // filas de artículos: se regeneran abajo
            return $fila === FB_PRIMER_RENGLON ? '@@RENGLONES@@' : '';
        }
        // pie del formato: se renumera fila y celdas
        $nueva = $fila + $delta;
        $xml = preg_replace('#<row r="\d+"#', '<row r="' . $nueva . '"', $m[0], 1);
        return preg_replace_callback('#<c r="([A-Z]+)\d+"#', fn($c) => '<c r="' . $c[1] . $nueva . '"', $xml);
    }, $hoja);

    $renglones = '';
    for ($i = 0; $i < $n; $i++) {
        $f = FB_PRIMER_RENGLON + $i;
        $renglones .= '<row r="' . $f . '" spans="1:6" ht="36" customHeight="1">'
            . "<c r=\"A{$f}\" s=\"21\"/><c r=\"B{$f}\" s=\"23\"/><c r=\"C{$f}\" s=\"22\"/>"
            . "<c r=\"D{$f}\" s=\"38\"/><c r=\"E{$f}\" s=\"24\"/><c r=\"F{$f}\" s=\"25\"/></row>";
    }
    $hoja = str_replace('@@RENGLONES@@', $renglones, $hoja);

    // Celdas combinadas del pie
    $hoja = preg_replace_callback('#<mergeCell ref="([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?"/>#', function ($m) use ($pieDesde, $delta) {
        if ((int)$m[2] < $pieDesde) return $m[0];
        $ref = $m[1] . ((int)$m[2] + $delta);
        if (isset($m[3]) && $m[3] !== '') $ref .= ':' . $m[3] . ((int)$m[4] + $delta);
        return '<mergeCell ref="' . $ref . '"/>';
    }, $hoja);

    // Dimensión, ajuste de impresión: siempre 1 página de ancho, las páginas de alto que hagan falta
    $hoja = preg_replace('#<dimension ref="[^"]*"/>#', '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:F' . (80 + $delta) . '"/>', $hoja, 1);
    $hoja = preg_replace('#<pageSetup paperSize="9" scale="\d+"#', '<pageSetup paperSize="9" fitToWidth="1" fitToHeight="0"', $hoja, 1);

    return [$hoja, $delta];
}

/** Genera el .xlsx en un archivo temporal y devuelve su ruta (el llamador lo borra). */
function fbGenerarXlsx(array $p, array $renglones): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión zip de PHP no está habilitada (php.ini → extension=zip).');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'fbaja');
    if (!$tmp || !copy(FB_PLANTILLA, $tmp)) {
        throw new RuntimeException('No se pudo leer la plantilla del formato de bajas.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        throw new RuntimeException('La plantilla del formato de bajas está dañada.');
    }

    $n = max(1, count($renglones));
    [$hoja, $delta] = fbAjustarFilas($zip->getFromName('xl/worksheets/sheet1.xml'), $n);
    $filaTotal = FB_PRIMER_RENGLON + FB_RENGLONES_BASE + $delta; // fila de "TOTAL $"
    $filaObs   = 50 + $delta;                                    // "Observaciones adicionales"

    // Tipo de baja marcado con X
    if ($p['tipo'] === 'activos')   $hoja = fbCelda($hoja, 'A9', '( X )  BAJA DE ACTIVOS');
    if ($p['tipo'] === 'operacion') $hoja = fbCelda($hoja, 'C9', '( X )  BAJA DE EQUIPO OPERACIÓN');

    // Encabezado
    $hoja = fbCelda($hoja, 'B12', fbSerialExcel($p['fecha']));
    $hoja = fbCelda($hoja, 'B13', $p['depto']);
    $hoja = fbCelda($hoja, 'B14', $p['ubicacion']);
    $hoja = fbCelda($hoja, 'D13', $p['fecha_verificacion'] ? date('d/m/Y', strtotime($p['fecha_verificacion'])) : null);

    // Renglones de artículos (la plantilla trae datos de ejemplo: se sobrescriben todos).
    // Estilos de la plantilla: 21 texto, 23 centrado, 22 negrita, 38 "$"#,##0.00, 24 contable, 25 obs.
    $total = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $fila = FB_PRIMER_RENGLON + $i;
        $r = $renglones[$i] ?? null;
        $total += $r['total'] ?? 0;
        $hoja = fbCelda($hoja, "A{$fila}", $r['codigo'] ?? null, 21);
        $hoja = fbCelda($hoja, "B{$fila}", $r ? $r['cantidad'] : null, 23);
        $hoja = fbCelda($hoja, "C{$fila}", $r['descripcion'] ?? null, 22);
        $hoja = fbCelda($hoja, "D{$fila}", $r['costo'] ?? null, 38);
        $hoja = fbCelda($hoja, "E{$fila}", ['f' => "+B{$fila}*D{$fila}", 'v' => $r['total'] ?? 0], 24);
        $hoja = fbCelda($hoja, "F{$fila}", $r['observaciones'] ?? null, 25);
    }
    $ultima = FB_PRIMER_RENGLON + $n - 1;
    $hoja = fbCelda($hoja, "E{$filaTotal}", ['f' => 'SUM(E' . FB_PRIMER_RENGLON . ":E{$ultima})", 'v' => $total]);

    // Observaciones adicionales
    $hoja = fbCelda($hoja, "C{$filaObs}", $p['observaciones'] !== '' ? mb_strtoupper($p['observaciones']) : null);

    $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);

    // Línea vertical de firmas (dibujo): sigue al pie del formato
    $dibujo = $zip->getFromName('xl/drawings/drawing1.xml');
    $dibujo = preg_replace_callback('#<xdr:row>(44|49)</xdr:row>#', fn($m) => '<xdr:row>' . ((int)$m[1] + $delta) . '</xdr:row>', $dibujo);
    $zip->addFromString('xl/drawings/drawing1.xml', $dibujo);

    // Quitar la cadena de cálculo de la plantilla y pedir a Excel que recalcule al abrir
    $zip->deleteName('xl/calcChain.xml');
    $tipos = $zip->getFromName('[Content_Types].xml');
    $zip->addFromString('[Content_Types].xml', preg_replace('#<Override PartName="/xl/calcChain\.xml"[^>]*/>#', '', $tipos));
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $zip->addFromString('xl/_rels/workbook.xml.rels', preg_replace('#<Relationship [^>]*Target="calcChain\.xml"[^>]*/>#', '', $rels));
    $libro = $zip->getFromName('xl/workbook.xml');
    $libro = preg_replace('#(Print_Area" localSheetId="0">Hoja1!\$A\$2:\$F\$)55#', '${1}' . (55 + $delta), $libro);
    if (!str_contains($libro, 'fullCalcOnLoad')) {
        $libro = preg_replace('#<calcPr\b#', '<calcPr fullCalcOnLoad="1"', $libro, 1);
    }
    $zip->addFromString('xl/workbook.xml', $libro);

    $zip->close();
    return $tmp;
}
