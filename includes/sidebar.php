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
    $labelEsc = htmlspecialchars($label);
    return "<li><a href=\"/pages/{$page}\" class=\"nav-item {$active}\" title=\"{$labelEsc}\"><span class=\"material-symbols-outlined nav-icon\">{$icon}</span><span class=\"nav-label\">{$labelEsc}</span></a></li>";
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

<script>
(function () {
    try {
        var guardado = localStorage.getItem('theme');
        var tema = guardado || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (tema === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    } catch (e) {}
})();
</script>

<!-- Topbar móvil (solo visible en pantallas pequeñas) -->
<div class="topbar" id="topbar">
    <button class="topbar-ham" id="hamBtn" onclick="sbToggle()" aria-label="Abrir menú">
        <span></span><span></span><span></span>
    </button>
    <span class="topbar-title"><span class="material-symbols-outlined mi-sm">computer</span> ZILARA TECHCARE</span>
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
        <button class="topbar-ham sidebar-ham" id="sidebarHamBtn" onclick="sbCollapseToggle()" aria-label="Contraer u expandir menú" title="Contraer / expandir menú">
            <span></span><span></span><span></span>
        </button>
        <img src="/img/hytta.png" alt="Zilara TechCare" class="brand-logo-img">
        <span class="brand-sub">Gestión de Equipos</span>
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
                <span style="font-size:10px;color:var(--purple);font-weight:700;display:block"><span class="material-symbols-outlined mi-xs" style="vertical-align:-2px">admin_panel_settings</span> ADMINISTRADOR</span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?= navLink('dashboard.php',     'dashboard',        'Dashboard',        $currentPage) ?>
            <?= navLink('equipos.php',        'computer',         'Equipos y Áreas',  $currentPage) ?>
            <?= navLink('mantenimientos.php', 'build',            'Mantenimientos',   $currentPage) ?>
            <?= navLink('tareas.php',         'checklist',        'Tareas',           $currentPage) ?>
            <?= navLink('calendario.php',     'calendar_month',   'Calendario',       $currentPage) ?>
            <?= navLink('Estadisticas.php',   'monitoring',       'Estadísticas',     $currentPage) ?>
            <?= navLink('reportes.php',       'bar_chart',        'Reportes PDF',     $currentPage) ?>
            <?php if ($esAdm): ?>
                <?= navLink('bajas.php',      'delete_forever',   'Bajas de Equipos', $currentPage) ?>
                <?= navLink('empleados.php',  'groups',           'Empleados',        $currentPage) ?>
                <li>
                    <a href="/pages/admin_roles.php" class="nav-item <?= $currentPage==='admin_roles.php' ? 'active' : '' ?>" title="Gestión de Roles">
                        <span class="material-symbols-outlined nav-icon">admin_panel_settings</span>
                        <span class="nav-label">Gestión de Roles</span>
                        <?php if ($pendRol > 0): ?>
                            <span class="nav-badge" style="margin-left:auto;background:var(--danger);color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:2px 7px">
                                <?= $pendRol ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <button type="button" class="theme-toggle-btn" id="themeToggleBtn" title="Cambiar a modo claro / oscuro">
            <span class="material-symbols-outlined" id="themeToggleIcon">dark_mode</span>
            <span class="theme-toggle-label" id="themeToggleLabel">Modo oscuro</span>
        </button>
        <a href="/pages/configuracion.php" class="nav-item <?= $currentPage==='configuracion.php' ? 'active' : '' ?>" style="margin-bottom:6px" title="Configuración">
            <span class="material-symbols-outlined nav-icon">settings</span>
            <span class="nav-label">Configuración</span>
        </a>
        <a href="/actions/logout.php" class="logout-btn" title="Cerrar Sesión">
            <span class="material-symbols-outlined mi-sm">logout</span> <span class="logout-label">Cerrar Sesión</span>
        </a>
    </div>

</aside>

<!-- Modal de confirmación reutilizable (reemplaza confirm() nativo) -->
<div class="modal-overlay" id="zcOverlay">
    <div class="modal-box zc-box">
        <div class="zc-icon tone-default" id="zcIcon"><span class="material-symbols-outlined">help</span></div>
        <div class="zc-title" id="zcTitle">¿Confirmar acción?</div>
        <div class="zc-msg" id="zcMsg"></div>
        <div class="zc-actions">
            <button type="button" class="btn btn-ghost" id="zcCancel">Cancelar</button>
            <button type="button" class="btn btn-primary" id="zcOk">Aceptar</button>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['usuario'])): ?>
<!-- Asistente Zilara (motor local basado en reglas) -->
<button class="chat-fab" id="chatFab" aria-label="Abrir asistente" title="Asistente Zilara">
    <span class="material-symbols-outlined">smart_toy</span>
    <span class="chat-fab-dot" id="chatFabDot"></span>
</button>
<div class="chat-panel" id="chatPanel" data-user-id="<?= (int)$_SESSION['usuario']['id'] ?>">
    <div class="chat-panel-header">
        <div class="chat-avatar"><span class="material-symbols-outlined">smart_toy</span></div>
        <div class="chat-header-text">
            <div class="chat-header-title">Asistente Zilara</div>
            <div class="chat-header-sub">En línea</div>
        </div>
        <button type="button" class="chat-close" id="chatCloseBtn" aria-label="Cerrar"><span class="material-symbols-outlined mi-sm">close</span></button>
    </div>
    <div class="chat-messages" id="chatMessages"></div>
    <div class="chat-chips" id="chatChips"></div>
    <form class="chat-input-row" id="chatForm">
        <input type="text" class="chat-input" id="chatInput" placeholder="Escribe tu pregunta…" autocomplete="off">
        <button type="submit" class="chat-send" aria-label="Enviar"><span class="material-symbols-outlined">send</span></button>
    </form>
</div>
<script src="/js/asistente.js" defer></script>
<?php endif; ?>

<script src="/js/ui.js?v=2"></script>
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

// Colapsar sidebar a solo-íconos (escritorio)
function sbCollapseToggle() {
    const layout = document.querySelector('.app-layout');
    if (!layout) return;
    const collapsed = layout.classList.toggle('sidebar-collapsed');
    document.getElementById('sidebarHamBtn')?.classList.toggle('open', collapsed);
    localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
}
(function () {
    if (localStorage.getItem('sidebarCollapsed') === '1' && window.innerWidth > 768) {
        document.querySelector('.app-layout')?.classList.add('sidebar-collapsed');
        document.getElementById('sidebarHamBtn')?.classList.add('open');
    }
})();

// Modo claro / oscuro
(function () {
    var btn = document.getElementById('themeToggleBtn');
    var icon = document.getElementById('themeToggleIcon');
    var label = document.getElementById('themeToggleLabel');
    if (!btn) return;

    function aplicarEstado(tema) {
        var esOscuro = tema === 'dark';
        icon.textContent = esOscuro ? 'light_mode' : 'dark_mode';
        label.textContent = esOscuro ? 'Modo claro' : 'Modo oscuro';
        btn.title = esOscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
    }

    var actual = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    aplicarEstado(actual);

    btn.addEventListener('click', function () {
        actual = actual === 'dark' ? 'light' : 'dark';
        if (actual === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        try { localStorage.setItem('theme', actual); } catch (e) {}
        aplicarEstado(actual);
    });
})();
</script>