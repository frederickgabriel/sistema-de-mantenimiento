<?php
// =============================================
// SIDEBAR NAVIGATION
// Archivo: includes/sidebar.php
// =============================================
$currentPage = basename($_SERVER['PHP_SELF']);
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
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['usuario']['nombre'], 0, 1)) ?></div>
        <div class="user-info">
            <span class="user-name"><?= e($_SESSION['usuario']['nombre']) ?></span>
            <span class="user-role"><?= e($_SESSION['usuario']['cargo']) ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?= navLink('dashboard.php', '◈', 'Dashboard', $currentPage) ?>
            <?= navLink('equipos.php', '🖥', 'Equipos y Áreas', $currentPage) ?>
            <?= navLink('mantenimientos.php', '🔧', 'Mantenimientos', $currentPage) ?>
            <?= navLink('tareas.php', '📋', 'Tareas', $currentPage) ?>
            <?= navLink('reportes.php', '📊', 'Reportes PDF', $currentPage) ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="/actions/logout.php" class="logout-btn">
            <span>⏻</span> Cerrar Sesión
        </a>
    </div>
</aside>
