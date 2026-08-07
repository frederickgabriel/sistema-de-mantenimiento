<!-- Lightbox reutilizable: amplía cualquier foto de perfil o evidencia -->
<div class="lightbox-overlay" id="lightboxOverlay" onclick="cerrarLightbox(event)">
    <button class="lightbox-close" onclick="cerrarLightbox(event)" aria-label="Cerrar">
        <span class="material-symbols-outlined">close</span>
    </button>
    <img src="" alt="" class="lightbox-img" id="lightboxImg" onclick="event.stopPropagation()">
</div>
<script>
function abrirLightbox(src) {
    const ov  = document.getElementById('lightboxOverlay');
    const img = document.getElementById('lightboxImg');
    img.src = src;
    ov.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function cerrarLightbox(e) {
    if (e) e.stopPropagation();
    document.getElementById('lightboxOverlay').classList.remove('open');
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarLightbox(); });
</script>
