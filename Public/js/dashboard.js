document.addEventListener('DOMContentLoaded', () => {
    
    // 1. Protección de Sesión
    const usuarioGuardado = localStorage.getItem('usuarioSistema');
    if (!usuarioGuardado) { window.location.href = 'index.html'; return; }

    // 2. Mostrar Usuario
    const usuario = JSON.parse(usuarioGuardado);
    document.getElementById('nombreUsuarioMenu').textContent = usuario.nombre;
    document.getElementById('cargoUsuarioMenu').textContent = usuario.cargo;

    // 3. Cerrar Sesión
    const btnCerrarSesion = document.getElementById('btnCerrarSesion');
    if (btnCerrarSesion) {
        btnCerrarSesion.addEventListener('click', (e) => {
            e.preventDefault(); localStorage.removeItem('usuarioSistema'); window.location.href = 'index.html';
        });
    }

    // 4. Cargar Áreas
    const cargarAreasEnSelect = async () => {
        try {
            const response = await fetch('/api/areas');
            const areas = await response.json();
            const selectArea = document.getElementById('areaEquipo');
            const selectAreaEdit = document.getElementById('editAreaEquipo');
            
            if (selectArea && selectAreaEdit) {
                selectArea.innerHTML = '<option value="">Seleccione un área...</option>';
                selectAreaEdit.innerHTML = '<option value="">Seleccione un área...</option>';
                areas.forEach(area => {
                    selectArea.innerHTML += `<option value="${area.id_area}">${area.nombre_area}</option>`;
                    selectAreaEdit.innerHTML += `<option value="${area.id_area}">${area.nombre_area}</option>`;
                });
            }
        } catch (error) { console.error('Error al cargar áreas:', error); }
    };

    // 5. Cargar Equipos (CON EL NUEVO BOTÓN REAGENDAR)
    const cargarEquipos = async () => {
        try {
            const response = await fetch('/api/equipos');
            const equipos = await response.json();
            const tbody = document.getElementById('tablaEquiposBody');
            if (!tbody) return;

            tbody.innerHTML = ''; 
            if (equipos.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No hay equipos registrados.</td></tr>';
                return;
            }
            
            equipos.forEach(equipo => {
                const tr = document.createElement('tr');
                let badgeColor = equipo.estado === 'Activo' ? 'bg-success' : equipo.estado === 'Inactivo' ? 'bg-secondary' : 'bg-warning text-dark';
                
                tr.innerHTML = `
                    <td class="fw-bold text-primary">${equipo.numero_inventario}</td>
                    <td>${equipo.modelo}</td>
                    <td>${equipo.nombre_area || '<span class="text-danger">Sin área</span>'}</td>
                    <td><span class="badge ${badgeColor}">${equipo.estado}</span></td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-dark me-1" onclick="abrirModalMantenimiento('${equipo.numero_inventario}')" title="Mantenimiento">
                            <i class="bi bi-tools"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-info me-1" onclick="abrirModalReagendar('${equipo.numero_inventario}')" title="Reagendar Mantenimiento">
                            <i class="bi bi-calendar-event"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning me-1" onclick="abrirModalEditar('${equipo.numero_inventario}', '${equipo.modelo}', '${equipo.id_area}', '${equipo.estado}')" title="Editar Equipo">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="eliminarEquipo('${equipo.numero_inventario}')" title="Eliminar Equipo">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        } catch (error) { console.error('Error al cargar equipos:', error); }
    };

    window.cargarEquiposGlobal = cargarEquipos; 
    cargarAreasEnSelect();
    cargarEquipos();

// 6. Formularios: Nueva Área, Nuevo Equipo, Editar Equipo, Nuevo Mantenimiento

    // ==========================================
    // Formulario de Nueva Área (EL QUE FALTABA)
    // ==========================================
    const formNuevaArea = document.getElementById('formNuevaArea');
    if (formNuevaArea) {
        formNuevaArea.addEventListener('submit', async (e) => {
            e.preventDefault(); 
            const nombre_area = document.getElementById('nombreArea').value;
            const ubicacion = document.getElementById('ubicacionArea').value;

            try {
                const response = await fetch('/api/areas', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ nombre_area, ubicacion })
                });
                const data = await response.json();

                if (response.ok) {
                    alert('✅ ' + data.mensaje);
                    formNuevaArea.reset(); 
                    bootstrap.Modal.getInstance(document.getElementById('modalNuevaArea')).hide();
                    cargarAreasEnSelect(); // Refresca los menús desplegables
                } else {
                    alert('❌ Error: ' + data.error);
                }
            } catch (error) { 
                console.error('Error:', error); 
            }
        });
    }

    // ==========================================
    // Formulario de Nuevo Equipo
    // ==========================================
    document.getElementById('formNuevoEquipo')?.addEventListener('submit', async (e) => {
        e.preventDefault(); 
        const datos = { numero_inventario: document.getElementById('numeroInventario').value, modelo: document.getElementById('modeloEquipo').value, id_area: document.getElementById('areaEquipo').value };
        if ((await fetch('/api/equipos', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos) })).ok) {
            e.target.reset(); bootstrap.Modal.getInstance(document.getElementById('modalNuevoEquipo')).hide(); cargarEquipos();
        }
    });

    // ==========================================
    // Formulario de Editar Equipo
    // ==========================================
    document.getElementById('formEditarEquipo')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('editNumeroInventario').value;
        const datos = { modelo: document.getElementById('editModeloEquipo').value, id_area: document.getElementById('editAreaEquipo').value, estado: document.getElementById('editEstadoEquipo').value };
        if ((await fetch(`/api/equipos/${id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos) })).ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalEditarEquipo')).hide(); cargarEquipos();
        }
    });

    // ==========================================
    // Formulario de Mantenimiento
    // ==========================================
    document.getElementById('formMantenimiento')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const datos = { numero_inventario: document.getElementById('mttoInventario').value, tipo_mantenimiento: document.getElementById('mttoTipo').value, fecha_realizacion: document.getElementById('mttoFecha').value, fecha_entrega: document.getElementById('mttoFechaEntrega').value, detalles: document.getElementById('mttoDetalles').value };
        if ((await fetch('/api/mantenimientos', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(datos) })).ok) {
            alert('✅ Historial guardado.'); e.target.reset(); bootstrap.Modal.getInstance(document.getElementById('modalMantenimiento')).hide();
        }
    });

    // 7. NUEVO Formulario: Reagendar Mantenimiento
    const formReagendar = document.getElementById('formReagendar');
    if (formReagendar) {
        formReagendar.addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('reagendarNumeroInventario').value;
            const nueva_fecha = document.getElementById('reagendarFecha').value;

            try {
                const response = await fetch(`/api/equipos/${id}/reagendar`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ nueva_fecha })
                });
                const data = await response.json();
                
                if (response.ok) {
                    alert('✅ ' + data.mensaje);
                    bootstrap.Modal.getInstance(document.getElementById('modalReagendar')).hide();
                } else {
                    alert('❌ ' + data.error);
                }
            } catch (error) { console.error(error); }
        });
    }
});

// ==========================================
// Funciones Globales para Botones de Tabla
// ==========================================
window.abrirModalMantenimiento = (id) => {
    document.getElementById('pcMantenimientoId').textContent = id; document.getElementById('mttoInventario').value = id;
    document.getElementById('mttoFecha').valueAsDate = new Date(); document.getElementById('mttoFechaEntrega').valueAsDate = new Date(); 
    new bootstrap.Modal(document.getElementById('modalMantenimiento')).show();
};

window.abrirModalEditar = (id, modelo, id_area, estado) => {
    document.getElementById('editInventarioTexto').textContent = id; document.getElementById('editNumeroInventario').value = id;
    document.getElementById('editModeloEquipo').value = modelo; document.getElementById('editAreaEquipo').value = id_area; document.getElementById('editEstadoEquipo').value = estado;
    new bootstrap.Modal(document.getElementById('modalEditarEquipo')).show();
};

window.eliminarEquipo = async (id) => {
    if (confirm(`¿Estás seguro de que deseas eliminar el equipo ${id}?`)) {
        if ((await fetch(`/api/equipos/${id}`, { method: 'DELETE' })).ok) window.cargarEquiposGlobal();
    }
};

// NUEVA Función Global: Abrir Modal Reagendar
window.abrirModalReagendar = (id) => {
    document.getElementById('reagendarInventarioTexto').textContent = id;
    document.getElementById('reagendarNumeroInventario').value = id;
    document.getElementById('reagendarFecha').value = ''; // Se limpia para obligar a seleccionar una fecha
    new bootstrap.Modal(document.getElementById('modalReagendar')).show();
};