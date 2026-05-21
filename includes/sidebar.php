<?php
$currentPage = basename($_SERVER['PHP_SELF']);

// Sincronizar foto y rol en sesión si no están cargados
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
    return "<li><a href=\"/pages/{$page}\" class=\"nav-item {$active}\"><span class=\"nav-icon\">{$icon}</span><span class=\"nav-label\">" . htmlspecialchars($label) . "</span></a></li>";
}

$foto    = $_SESSION['usuario']['foto_perfil'] ?? null;
$nombre  = $_SESSION['usuario']['nombre'] ?? '';
$cargo   = $_SESSION['usuario']['cargo']  ?? '';
$inicial = strtoupper(substr($nombre, 0, 1));
$esAdm   = esAdmin();

// Badge solicitudes pendientes
$pendRol = 0;
if ($esAdm) {
    try {
        $pendRol = (int)getDB()->query("SELECT COUNT(*) FROM SolicitudesRol WHERE estado='Pendiente'")->fetchColumn();
    } catch (Exception $e) { $pendRol = 0; }
}
?>

<!-- Topbar móvil (solo visible en pantallas pequeñas) -->
<div class="topbar" id="topbar">
    <button class="topbar-ham" id="hamBtn" onclick="sbToggle()" aria-label="Abrir menú">
        <span></span><span></span><span></span>
    </button>
    <span class="topbar-title">🖥 ManteTech</span>
    <a href="/pages/configuracion.php" class="topbar-av">
        <?php if ($foto): ?>
            <img src="/uploads/perfiles/<?= htmlspecialchars($foto) ?>" alt="Foto">
        <?php else: ?>
            <?= $inicial ?>
        <?php endif; ?>
    </a>
</div>

<!-- Overlay oscuro al abrir el menú en móvil -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="sbClose()"></div>

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-icon">🖥</div>
        <div>
            <span class="brand-name">TechCare</span>
            <span class="brand-sub">Gestión de Equipos</span>
        </div>
    </div>

    <div class="sidebar-user">
        <?php if ($foto): ?>
            <img src="/uploads/perfiles/<?= htmlspecialchars($foto) ?>"
                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0" alt="Foto">
        <?php else: ?>
            <div class="user-avatar"><?= $inicial ?></div>
        <?php endif; ?>
        <div class="user-info">
            <span class="user-name"><?= e($nombre) ?></span>
            <span class="user-role"><?= e($cargo) ?></span>
            <?php if ($esAdm): ?>
                <span style="font-size:10px;color:var(--purple);font-weight:700;display:block">👑 ADMINISTRADOR</span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?= navLink('dashboard.php',     '◈',  'Dashboard',        $currentPage) ?>
            <?= navLink('equipos.php',        '🖥', 'Equipos y Áreas',  $currentPage) ?>
            <?= navLink('mantenimientos.php', '🔧', 'Mantenimientos',   $currentPage) ?>
            <?= navLink('tareas.php',         '📋', 'Tareas',           $currentPage) ?>
            <?= navLink('calendario.php',     '📅', 'Calendario',       $currentPage) ?>
            <?= navLink('Estadisticas.php',   '📈', 'Estadísticas',     $currentPage) ?>
            <?= navLink('reportes.php',       '📊', 'Reportes PDF',     $currentPage) ?>
            <?php if ($esAdm): ?>
                <?= navLink('bajas.php',      '📛', 'Bajas de Equipos', $currentPage) ?>
                <li>
                    <a href="/pages/admin_roles.php" class="nav-item <?= $currentPage==='admin_roles.php' ? 'active' : '' ?>">
                        <span class="nav-icon">👑</span>
                        <span class="nav-label">Gestión de Roles</span>
                        <?php if ($pendRol > 0): ?>
                            <span style="margin-left:auto;background:var(--danger);color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:2px 7px">
                                <?= $pendRol ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="/pages/configuracion.php" class="nav-item <?= $currentPage==='configuracion.php' ? 'active' : '' ?>" style="margin-bottom:6px">
            <span class="nav-icon">⚙</span>
            <span class="nav-label">Configuración</span>
        </a>
        <a href="/actions/logout.php" class="logout-btn">
            <span>⏻</span> Cerrar Sesión
        </a>
    </div>

</aside>

<script>
function sbToggle() {
    const sb  = document.getElementById('sidebar');
    const ov  = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamBtn');
    const open = sb.classList.toggle('open');
    ov.classList.toggle('show', open);
    btn.classList.toggle('open', open);
    document.body.style.overflow = open ? 'hidden' : '';
}
function sbClose() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.getElementById('hamBtn').classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('#sidebar .nav-item, #sidebar .logout-btn').forEach(el => {
    el.addEventListener('click', () => { if (window.innerWidth <= 768) sbClose(); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') sbClose(); });
</script>