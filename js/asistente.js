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

    function enviarPregunta(texto) {
        texto = texto.trim();
        if (!texto) return;
        addBubble('user', escapeHtml(texto));
        renderChips([]);
        input.value = '';
        showTyping();

        const body = new URLSearchParams({ q: texto });
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

    function openPanel() {
        panel.classList.add('open');
        fabDot?.remove();
        localStorage.setItem(SEEN_KEY, '1');
        input.focus();
    }
    function closePanel() { panel.classList.remove('open'); }

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
