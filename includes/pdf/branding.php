<?php
// =============================================
// BRANDING — Identidad visual configurable para reportes PDF
// Archivo: includes/pdf/branding.php
// =============================================

// Trae la fila única de ConfiguracionMarca (con defaults seguros si aún no existe la tabla
// o la fila, para que ningún reporte truene si no se ha configurado nada todavía).
function getBranding(): array {
    static $branding = null;
    if ($branding !== null) return $branding;

    $defaults = [
        'nombre_empresa'        => SITE_NAME,
        'logo'                  => null,
        'color_primario'        => '#5b21b6',
        'color_secundario'      => '#004085',
        'direccion'             => null,
        'telefono'              => null,
        'correo'                => null,
        'pie_pagina'            => null,
        'mostrar_logo'          => 1,
        'mostrar_info_empresa'  => 1,
        'mostrar_numero_pagina' => 1,
    ];

    try {
        $row = getDB()->query("SELECT * FROM ConfiguracionMarca WHERE id=1")->fetch();
        $branding = $row ? array_merge($defaults, $row) : $defaults;
    } catch (Exception $e) {
        $branding = $defaults;
    }
    return $branding;
}

// Ruta pública del logo de marca (o null si no hay logo configurado)
function brandingLogoPath(): ?string {
    $logo = getBranding()['logo'] ?? null;
    return $logo ? $_SERVER['DOCUMENT_ROOT'] . '/uploads/marca/' . $logo : null;
}
