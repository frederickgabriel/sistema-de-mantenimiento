<?php
$currentPage = basename($_SERVER['PHP_SELF']);

// Sincronizar foto_perfil y rol en sesión si no están cargados
if (isset($_SESSION['usuario']) && !array_key_exists('foto_perfil', $_SESSION['usuario'])) {
    try {
        $dbSide = getDB();
        $stSide = $dbSide->prepare("SELECT foto_perfil, rol FROM Usuarios WHERE id_usuario=?");
        $stSide->execute([$_SESSION['usuario']['id']]);
        $rowSide = $stSide->fetch();
        $_SESSION['usuario']['foto_perfil'] = $rowSide['foto_perfil'] ?? null;
        $_SESSION['usuario']['rol']         = $rowSide['rol'] ?? 'usuario';
    } catch (Exception $e) {
        $_SESSION['usuario']['foto_perfil'] = null;
        $_SESSION['usuario']['rol']         = 'usuario';
    }
}

function navLink(string $page, string $icon, string $label, string $current): string {
    $active = ($current === $page) ? 'active' : '';
    return "<li><a href=\"/pages/{$page}\" class=\"nav-item {$active}\"><span class=\"nav-icon\">{$icon}</span><span class=\"nav-label\">{$label}</span></a></li>";
}
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">🖥</div>
        <div class="brand-text">
            <span class="brand-name">ManteTech</span>
            <span class="brand-sub">Sistema de Gestión</span>
        </div>
    </div>

    <div class="sidebar-user">
        <?php $fotoSidebar = $_SESSION['usuario']['foto_perfil'] ?? null; ?>
        <?php if ($fotoSidebar): ?>
            <img src="/uploads/perfiles/<?= e($fotoSidebar) ?>"
                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0"
                 alt="Foto">
        <?php else: ?>
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['usuario']['nombre'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="user-info">
            <span class="user-name"><?= e($_SESSION['usuario']['nombre']) ?></span>
            <span class="user-role"><?= e($_SESSION['usuario']['cargo']) ?></span>
            <?php if (($_SESSION['usuario']['rol'] ?? 'usuario') === 'admin'): ?>
                <span style="font-size:10px;color:var(--purple);font-weight:700;letter-spacing:.04em">👑 ADMINISTRADOR</span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?= navLink('dashboard.php',     '◈',  'Dashboard',       $currentPage) ?>
            <?= navLink('equipos.php',        '🖥', 'Equipos y Áreas', $currentPage) ?>
            <?= navLink('mantenimientos.php', '🔧', 'Mantenimientos',  $currentPage) ?>
            <?= navLink('tareas.php',         '📋', 'Tareas',          $currentPage) ?>
            <?= navLink('calendario.php',     '📅', 'Calendario',      $currentPage) ?>
            <?= navLink('reportes.php',       '📊', 'Reportes PDF',    $currentPage) ?>
            <?php if (($_SESSION['usuario']['rol'] ?? 'usuario') === 'admin'): ?>
                <?= navLink('bajas.php',      '📛', 'Bajas de Equipos',$currentPage) ?>
                <?php
                // Badge de solicitudes pendientes para admin
                try {
                    $pendRoles = getDB()->query("SELECT COUNT(*) FROM SolicitudesRol WHERE estado='Pendiente'")->fetchColumn();
                } catch (Exception $e) { $pendRoles = 0; }
                ?>
                <li>
                    <a href="/pages/admin_roles.php" class="nav-item <?= $currentPage === 'admin_roles.php' ? 'active' : '' ?>">
                        <span class="nav-icon">👑</span>
                        <span class="nav-label">Gestión de Roles</span>
                        <?php if ($pendRoles > 0): ?>
                            <span style="margin-left:auto;background:var(--danger);color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:2px 7px;line-height:1.4">
                                <?= $pendRoles ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="/pages/configuracion.php" class="nav-item <?= $currentPage === 'configuracion.php' ? 'active' : '' ?>" style="margin-bottom:6px;display:flex">
            <span class="nav-icon">⚙</span><span class="nav-label">Configuración</span>
        </a>
        <a href="/actions/logout.php" class="logout-btn">
            <span>⏻</span> Cerrar Sesión
        </a>
    </div>
</aside>