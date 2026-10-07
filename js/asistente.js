// Asistente Zilara — widget de chat flotante, consulta actions/asistente.php.
// La conversación se guarda en sessionStorage (aislada por usuario) para no
// perderse al navegar entre páginas, y se borra al cerrar sesión.
(function () {
    const fab       = document.getElementById('chatFab');
    const fabDot    = document.getElementById('chatFabDot');
    const panel     = document.getElementById('chatPanel');
    const closeBtn  = document.getElementById('chatCloseBtn');
    const messages  = document.getElementById('chatMessages');
    const chipsWrap = document.getElementById('chatChips');
    const form      = document.getElementById('chatForm');
    const input     = document.getElementById('chatInput');
    if (!fab || !panel) return;

    // La conversación se guarda por usuario (sessionStorage) para que no se
    // mezcle con la de otra cuenta que haya iniciado sesión en el mismo
    // navegador, y se borra al cerrar sesión para no acumularse.
    const userId       = panel.dataset.userId || 'anon';
    const STORAGE_KEY  = 'zilaraChatHistory_' + userId;
    const SEEN_KEY     = 'zilaraChatSeen_' + userId;

    // Limpieza de una clave antigua sin aislar por usuario (versión anterior del widget).
    sessionStorage.removeItem('zilaraChatHistory');

    const GREETING = {
        role: 'bot',
        html: '¡Hola! 👋 Soy el asistente de <strong>Zilara TechCare</strong>. Puedo ayudarte con información sobre equipos, tareas, mantenimientos, bajas y empleados, explicarte cómo usar el sistema, o levantar un reporte si algo falló.',
    };
    const DEFAULT_CHIPS = [
        '¿Cuántos equipos activos hay?',
        '¿Cuáles son mis tareas pendientes?',
        '¿Cómo registro un equipo nuevo?',
        'Quiero reportar un problema',
    ];

    function loadHistory() {
        try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY)) || []; }
        catch (e) { return []; }
    }
    function saveHistory(history) {
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-40))); }
        catch (e) { /* almacenamiento lleno o deshabilitado: ignorar */ }
    }

    let history = loadHistory();

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function addBubble(role, html, persist = true) {
        const div = document.createElement('div');
        div.className = 'chat-msg ' + (role === 'user' ? 'user' : 'bot');
        div.innerHTML = html;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        if (persist) { history.push({ role, html }); saveHistory(history); }
    }

    function renderChips(chips) {
        chipsWrap.innerHTML = '';
        (chips || []).forEach((texto) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chat-chip';
            btn.textContent = texto;
            btn.addEventListener('click', () => enviarPregunta(texto));
            chipsWrap.appendChild(btn);
        });
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'chat-typing';
        div.id = 'chatTypingIndicator';
        div.innerHTML = '<span></span><span></span><span></span>';
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }
    function hideTyping() {
        document.getElementById('chatTypingIndicator')?.remove();
    }

    // Convierte el HTML guardado de un mensaje a texto plano, para mandarle al
    // asistente el historial de la conversación sin etiquetas.
    function htmlATexto(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return (div.textContent || '').trim();
    }

    function enviarPregunta(texto) {
        texto = texto.trim();
        if (!texto) return;

        // Historial previo (antes de agregar este mensaje) en texto plano, para
        // que el asistente tenga contexto de la conversación.
        const historialPrevio = history.slice(-10).map((m) => ({
            role: m.role === 'user' ? 'user' : 'model',
            text: htmlATexto(m.html),
        }));

        addBubble('user', escapeHtml(texto));
        renderChips([]);
        input.value = '';
        showTyping();

        const body = new URLSearchParams({ q: texto, history: JSON.stringify(historialPrevio) });
        fetch('/actions/asistente.php', { method: 'POST', body })
            .then((r) => r.json())
            .then((data) => {
                hideTyping();
                addBubble('bot', data.reply || 'No pude procesar tu pregunta.');
                renderChips(data.chips && data.chips.length ? data.chips : DEFAULT_CHIPS);
            })
            .catch(() => {
                hideTyping();
                addBubble('bot', 'Hubo un problema de conexión. Intenta de nuevo.');
                renderChips(DEFAULT_CHIPS);
            });
    }

    function renderHistory() {
        messages.innerHTML = '';
        if (!history.length) {
            addBubble('bot', GREETING.html);
        } else {
            history.forEach((m) => addBubble(m.role, m.html, false));
        }
        renderChips(DEFAULT_CHIPS);
    }

    // Mascota: los ojos siguen el cursor (si se movió hace poco) o miran alrededor por su cuenta;
    // flotar, parpadear, la onda de los puntos y el hover son CSS (css/estilos.css, bloque "Mascota Zilara").
    const mascota = fab.querySelector('.mascota');
    let accionTimer;
    function lanzarAccion(clase, ms) {
        if (!mascota) return;
        clearTimeout(accionTimer);
        mascota.classList.remove(clase);
        void mascota.offsetWidth; // reinicia la animación
        mascota.classList.add(clase);
        accionTimer = setTimeout(() => mascota.classList.remove(clase), ms);
    }
    // Parpadeo natural: cada 2.5–6 s cierra y abre los ojos; a veces parpadea dos veces seguidas
    function parpadear(veces) {
        mascota.classList.add('parpadea');
        setTimeout(() => {
            mascota.classList.remove('parpadea');
            if (veces > 1) setTimeout(() => parpadear(veces - 1), 140);
        }, 130);
    }
    (function ciclo() {
        setTimeout(() => {
            if (mascota && !document.hidden) parpadear(Math.random() < 0.25 ? 2 : 1);
            ciclo();
        }, 2500 + Math.random() * 3500);
    })();
    if (mascota) {
        const reducir = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        let ultimoMov = 0, esperaAzar = 0;
        const mirar = (x, y) => { mascota.style.setProperty('--ox', x.toFixed(2)); mascota.style.setProperty('--oy', y.toFixed(2)); };
        if (!reducir) {
            document.addEventListener('pointermove', (e) => {
                const r = fab.getBoundingClientRect();
                const dx = e.clientX - (r.left + r.width / 2), dy = e.clientY - (r.top + r.height / 2);
                const d = Math.hypot(dx, dy) || 1, f = Math.min(1, d / 260); // cerca del cursor mira más fuerte
                mirar((dx / d) * f, (dy / d) * f);
                ultimoMov = performance.now();
            }, { passive: true });
            setInterval(() => { // sin cursor cerca: mira alrededor de vez en cuando
                if (document.hidden || performance.now() - ultimoMov < 3500 || --esperaAzar > 0) return;
                esperaAzar = 1 + Math.floor(Math.random() * 3);
                mirar(Math.random() * 2 - 1, (Math.random() * 2 - 1) * .6);
            }, 1400);
        }
    }

    function openPanel() {
        fab.classList.add('abierto');
        lanzarAccion('a-feliz', 1200);
        panel.classList.add('open');
        fabDot?.remove();
        localStorage.setItem(SEEN_KEY, '1');
        input.focus();
    }
    function closePanel() { panel.classList.remove('open'); fab.classList.remove('abierto'); }

    if (localStorage.getItem(SEEN_KEY)) fabDot?.remove();

    fab.addEventListener('click', () => {
        panel.classList.contains('open') ? closePanel() : openPanel();
    });
    closeBtn.addEventListener('click', closePanel);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePanel(); });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        enviarPregunta(input.value);
    });

    // Al cerrar sesión se borra la conversación de este usuario para que no
    // se acumule ni quede visible si otra persona inicia sesión después.
    document.querySelectorAll('.logout-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            sessionStorage.removeItem(STORAGE_KEY);
        });
    });

    renderHistory();
})();
