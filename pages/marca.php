<?php
// =============================================
// MARCA / BRANDING DE REPORTES PDF (solo Admin)
// Archivo: pages/marca.php
// =============================================
require_once '../includes/config.php';
requireAdmin();

$db  = getDB();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'guardar_marca') {
        $nombreEmpresa   = trim($_POST['nombre_empresa'] ?? '') ?: 'Gestión de Mantenimiento';
        $colorPrimario   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color_primario'] ?? '') ? $_POST['color_primario'] : '#5b21b6';
        $colorSecundario = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color_secundario'] ?? '') ? $_POST['color_secundario'] : '#004085';
        $direccion  = trim($_POST['direccion'] ?? '') ?: null;
        $telefono   = trim($_POST['telefono'] ?? '') ?: null;
        $correo     = trim($_POST['correo'] ?? '') ?: null;
        $piePagina  = trim($_POST['pie_pagina'] ?? '') ?: null;
        $mostrarLogo    = isset($_POST['mostrar_logo']) ? 1 : 0;
        $mostrarInfo    = isset($_POST['mostrar_info_empresa']) ? 1 : 0;
        $mostrarNumPag  = isset($_POST['mostrar_numero_pagina']) ? 1 : 0;

        $db->prepare("UPDATE ConfiguracionMarca SET nombre_empresa=?, color_primario=?, color_secundario=?, direccion=?, telefono=?, correo=?, pie_pagina=?, mostrar_logo=?, mostrar_info_empresa=?, mostrar_numero_pagina=? WHERE id=1")
           ->execute([$nombreEmpresa, $colorPrimario, $colorSecundario, $direccion, $telefono, $correo, $piePagina, $mostrarLogo, $mostrarInfo, $mostrarNumPag]);
        $msg = '✅ Configuración de marca guardada.';

    } elseif ($action === 'subir_logo') {
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['logo'];
            $maxSize = 2 * 1024 * 1024; // 2 MB
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($file['size'] > $maxSize) {
                $err = 'El logo no debe superar 2 MB.';
            } elseif (!isset($allowed[$mime])) {
                $err = 'Solo se permiten imágenes JPG, PNG, WEBP o SVG.';
            } else {
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/marca/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $actual = $db->query("SELECT logo FROM ConfiguracionMarca WHERE id=1")->fetchColumn();
                if ($actual && file_exists($uploadDir . $actual)) unlink($uploadDir . $actual);

                $nombreArchivo = 'logo_' . time() . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $nombreArchivo)) {
                    $db->prepare("UPDATE ConfiguracionMarca SET logo=? WHERE id=1")->execute([$nombreArchivo]);
                    $msg = '✅ Logo actualizado.';
                } else {
                    $err = 'Error al subir el logo. Verifica los permisos del directorio.';
                }
            }
        } else {
            $err = 'No se recibió ninguna imagen válida.';
        }

    } elseif ($action === 'eliminar_logo') {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/marca/';
        $actual = $db->query("SELECT logo FROM ConfiguracionMarca WHERE id=1")->fetchColumn();
        if ($actual && file_exists($uploadDir . $actual)) unlink($uploadDir . $actual);
        $db->prepare("UPDATE ConfiguracionMarca SET logo=NULL WHERE id=1")->execute();
        $msg = '✅ Logo eliminado.';
    }

    if ($msg) flash('msg', $msg); else flash('err', $err);
    header('Location: /pages/marca.php');
    exit;
}

$msg = getFlash('msg') ?? '';
$err = getFlash('err') ?? '';
$marca = $db->query("SELECT * FROM ConfiguracionMarca WHERE id=1")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon/favicon-16.png">
    <link rel="apple-touch-icon" href="/img/favicon/favicon-180.png">
    <link rel="shortcut icon" href="/img/favicon/favicon.ico">
    <title>Marca / Reportes — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="/css/estilos.css?v=12">
    <style>
        .color-row { display: flex; align-items: center; gap: 12px; }
        .color-row input[type="color"] { width: 44px; height: 36px; border: 1px solid var(--border); border-radius: 6px; padding: 2px; background: var(--bg-card); cursor: pointer; }
        .marca-logo-preview { max-width: 180px; max-height: 70px; border-radius: 6px; border: 1px solid var(--border); padding: 8px; background: #fff; display: block; }
        .marca-toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border-light); }
        .marca-toggle-row:last-child { border-bottom: none; }
        .marca-toggle-label { font-size: 13px; color: var(--text-secondary); }
        .switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch-slider { position: absolute; inset: 0; background: var(--border); border-radius: 22px; transition: .2s; cursor: pointer; }
        .switch-slider::before { content: ''; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; }
        .switch input:checked + .switch-slider { background: var(--accent); }
        .switch input:checked + .switch-slider::before { transform: translateX(18px); }
    </style>
</head>
<body>
<div class="app-layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <div class="page-title"><span class="material-symbols-outlined mi-md">palette</span> Marca / Reportes</div>
                <div class="page-subtitle">Identidad visual que usan automáticamente todos los reportes PDF del sistema</div>
            </div>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= renderMsg($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-error"><?= renderMsg($err) ?></div><?php endif; ?>

        <div class="card" style="margin-bottom:24px">
            <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">image</span> Logo</div></div>
            <div class="card-body">
                <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                    <?php if ($marca['logo']): ?>
                        <img src="/uploads/marca/<?= e($marca['logo']) ?>?v=<?= time() ?>" class="marca-logo-preview" alt="Logo actual">
                    <?php else: ?>
                        <div class="marca-logo-preview" style="display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:12px">Sin logo</div>
                    <?php endif; ?>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="subir_logo">
                            <input type="file" name="logo" id="inputLogo" class="input-file-hidden" accept="image/png,image/jpeg,image/webp,image/svg+xml" onchange="this.form.submit()">
                            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('inputLogo').click()"><span class="material-symbols-outlined mi-sm">upload</span> Subir logo</button>
                        </form>
                        <?php if ($marca['logo']): ?>
                        <form method="POST" onsubmit="return zConfirm(this,'¿Eliminar el logo actual?','danger')">
                            <input type="hidden" name="action" value="eliminar_logo">
                            <button type="submit" class="btn btn-ghost btn-sm"><span class="material-symbols-outlined mi-sm">delete</span> Eliminar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <p style="font-size:12px;color:var(--text-muted);margin-top:12px">JPG, PNG, WEBP o SVG — máx. 2 MB. Se muestra en la portada de cada reporte PDF (si "Mostrar logo" está activado).</p>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="guardar_marca">

            <div class="card" style="margin-bottom:24px">
                <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">business</span> Datos de la Empresa</div></div>
                <div class="card-body">
                    <div class="form-group"><label>Nombre de la Empresa</label><input type="text" name="nombre_empresa" value="<?= e($marca['nombre_empresa']) ?>" placeholder="Gestión de Mantenimiento"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Dirección</label><input type="text" name="direccion" value="<?= e($marca['direccion'] ?? '') ?>" placeholder="Calle, número, ciudad"></div>
                        <div class="form-group"><label>Teléfono</label><input type="text" name="telefono" value="<?= e($marca['telefono'] ?? '') ?>" placeholder="998 123 4567"></div>
                    </div>
                    <div class="form-group"><label>Correo</label><input type="email" name="correo" value="<?= e($marca['correo'] ?? '') ?>" placeholder="contacto@empresa.com"></div>
                    <div class="form-group"><label>Pie de página (texto libre)</label><input type="text" name="pie_pagina" value="<?= e($marca['pie_pagina'] ?? '') ?>" placeholder="Ej: Documento confidencial — uso interno"></div>
                </div>
            </div>

            <div class="card" style="margin-bottom:24px">
                <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">palette</span> Colores Corporativos</div></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Color Primario</label>
                            <div class="color-row"><input type="color" name="color_primario" value="<?= e($marca['color_primario']) ?>"><span class="text-mono text-secondary"><?= e($marca['color_primario']) ?></span></div>
                        </div>
                        <div class="form-group">
                            <label>Color Secundario</label>
                            <div class="color-row"><input type="color" name="color_secundario" value="<?= e($marca['color_secundario']) ?>"><span class="text-mono text-secondary"><?= e($marca['color_secundario']) ?></span></div>
                        </div>
                    </div>
                    <p style="font-size:12px;color:var(--text-muted)">El primario se usa en la portada; el secundario en el encabezado, títulos de sección y tablas de los reportes.</p>
                </div>
            </div>

            <div class="card" style="margin-bottom:24px">
                <div class="card-header"><div class="card-title"><span class="material-symbols-outlined mi-md">visibility</span> Mostrar / Ocultar</div></div>
                <div class="card-body" style="padding-top:4px;padding-bottom:4px">
                    <div class="marca-toggle-row">
                        <span class="marca-toggle-label">Mostrar logo en la portada</span>
                        <label class="switch"><input type="checkbox" name="mostrar_logo" <?= $marca['mostrar_logo'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
                    </div>
                    <div class="marca-toggle-row">
                        <span class="marca-toggle-label">Mostrar dirección / teléfono / correo en la portada</span>
                        <label class="switch"><input type="checkbox" name="mostrar_info_empresa" <?= $marca['mostrar_info_empresa'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
                    </div>
                    <div class="marca-toggle-row">
                        <span class="marca-toggle-label">Mostrar número de página</span>
                        <label class="switch"><input type="checkbox" name="mostrar_numero_pagina" <?= $marca['mostrar_numero_pagina'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined mi-sm">save</span> Guardar Configuración</button>
        </form>

    </main>
</div>
</body>
</html>
