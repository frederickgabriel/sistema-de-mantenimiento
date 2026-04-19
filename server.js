const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');
const bcrypt = require('bcrypt'); 

// ==========================================
// 1. Inicializar la aplicación
// ==========================================
const app = express();
const PORT = 3000; 

app.use(cors());
app.use(express.json()); 
app.use(express.urlencoded({ extended: true })); 
app.use(express.static('public')); 

// ==========================================
// 2. Configuración de la Base de Datos
// ==========================================
const db = mysql.createConnection({
    host: 'localhost',
    user: 'root', 
    password: '2004', // <-- RECUERDA: Tu contraseña de MySQL aquí
    database: 'sistema_mantenimiento'
});

db.connect((err) => {
    if (err) {
        console.error('Error al conectar con la base de datos:', err.message);
        return;
    }
    console.log('✅ Conexión exitosa a la base de datos MySQL');
});

// ==========================================
// 3. Rutas (Endpoints) de la API
// ==========================================

// --- USUARIOS ---
app.post('/api/registro', async (req, res) => {
    const { nombre, cargo, edad, correo, password } = req.body;
    try {
        const hashedPassword = await bcrypt.hash(password, 10);
        const query = `INSERT INTO Usuarios (nombre, cargo, correo, edad, password) VALUES (?, ?, ?, ?, ?)`;
        db.query(query, [nombre, cargo, correo, edad, hashedPassword], (err) => {
            if (err) {
                if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'El correo ya está registrado.' });
                return res.status(500).json({ error: 'Error interno.' });
            }
            res.status(201).json({ mensaje: 'Usuario registrado exitosamente' });
        });
    } catch (error) {
        res.status(500).json({ error: 'Error procesando seguridad.' });
    }
});

app.post('/api/login', (req, res) => {
    const { correo, password } = req.body;
    const query = `SELECT * FROM Usuarios WHERE correo = ?`;
    db.query(query, [correo], async (err, results) => {
        if (err || results.length === 0) return res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
        
        const usuario = results[0];
        const match = await bcrypt.compare(password, usuario.password);
        
        if (match) {
            res.status(200).json({ 
                mensaje: 'Inicio de sesión exitoso',
                usuario: { id: usuario.id_usuario, nombre: usuario.nombre, cargo: usuario.cargo }
            });
        } else {
            res.status(401).json({ error: 'Correo o contraseña incorrectos.' });
        }
    });
});

// --- ÁREAS ---
app.post('/api/areas', (req, res) => {
    const { nombre_area, ubicacion } = req.body;
    db.query(`INSERT INTO Areas (nombre_area, ubicacion) VALUES (?, ?)`, [nombre_area, ubicacion], (err) => {
        if (err) return res.status(500).json({ error: 'Error al guardar el área.' });
        res.status(201).json({ mensaje: 'Área registrada exitosamente' });
    });
});

app.get('/api/areas', (req, res) => {
    db.query(`SELECT * FROM Areas`, (err, results) => {
        if (err) return res.status(500).json({ error: 'Error al consultar las áreas.' });
        res.status(200).json(results);
    });
});

// --- EQUIPOS ---
app.post('/api/equipos', (req, res) => {
    const { numero_inventario, modelo, id_area } = req.body;
    db.query(`INSERT INTO Equipos (numero_inventario, modelo, estado, id_area) VALUES (?, ?, 'Activo', ?)`, [numero_inventario, modelo, id_area], (err) => {
        if (err && err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Ese número de inventario ya existe.' });
        res.status(201).json({ mensaje: 'Equipo registrado' });
    });
});

app.get('/api/equipos', (req, res) => {
    const query = `
        SELECT e.numero_inventario, e.modelo, e.estado, e.id_area, a.nombre_area 
        FROM Equipos e 
        LEFT JOIN Areas a ON e.id_area = a.id_area
    `;
    db.query(query, (err, results) => {
        if (err) return res.status(500).json({ error: 'Error al consultar equipos.' });
        res.status(200).json(results);
    });
});

app.put('/api/equipos/:id', (req, res) => {
    const { id } = req.params;
    const { modelo, estado, id_area } = req.body;
    db.query(`UPDATE Equipos SET modelo = ?, estado = ?, id_area = ? WHERE numero_inventario = ?`, [modelo, estado, id_area, id], (err) => {
        if (err) return res.status(500).json({ error: 'Error al actualizar.' });
        res.json({ mensaje: 'Equipo actualizado correctamente' });
    });
});

app.delete('/api/equipos/:id', (req, res) => {
    const { id } = req.params;
    db.query(`DELETE FROM Equipos WHERE numero_inventario = ?`, [id], (err) => {
        if (err) return res.status(500).json({ error: 'Error al eliminar.' });
        res.json({ mensaje: 'Equipo eliminado del sistema' });
    });
});

// NUEVO: Reagendar Próximo Mantenimiento
app.put('/api/equipos/:id/reagendar', (req, res) => {
    const { id } = req.params;
    const { nueva_fecha } = req.body;

    // Actualizamos solo el último mantenimiento registrado de esa PC
    const query = `
        UPDATE Mantenimientos 
        SET proximo_mantenimiento = ? 
        WHERE numero_inventario = ? 
        ORDER BY id_mantenimiento DESC 
        LIMIT 1
    `;
    
    db.query(query, [nueva_fecha, id], (err, result) => {
        if (err) return res.status(500).json({ error: 'Error al reagendar en la base de datos.' });
        
        if (result.affectedRows === 0) {
            return res.status(404).json({ error: 'Esta PC no tiene mantenimientos previos. Registra uno primero.' });
        }
        res.json({ mensaje: 'Fecha de próximo mantenimiento actualizada correctamente.' });
    });
});

// --- MANTENIMIENTOS Y DASHBOARD ---
app.post('/api/mantenimientos', (req, res) => {
    const { numero_inventario, tipo_mantenimiento, detalles, fecha_realizacion, fecha_entrega } = req.body;

    let fechaProd = new Date(fecha_entrega || fecha_realizacion);
    fechaProd.setDate(fechaProd.getDate() + 180); 
    const proximo_mantenimiento = fechaProd.toISOString().split('T')[0];

    const query = `INSERT INTO Mantenimientos (numero_inventario, tipo_mantenimiento, fecha_realizacion, fecha_entrega, proximo_mantenimiento, detalles) VALUES (?, ?, ?, ?, ?, ?)`;
    
    db.query(query, [numero_inventario, tipo_mantenimiento, fecha_realizacion, fecha_entrega, proximo_mantenimiento, detalles], (err) => {
        if (err) return res.status(500).json({ error: 'Error al registrar mantenimiento' });
        res.status(201).json({ mensaje: 'Mantenimiento registrado. Próxima cita: ' + proximo_mantenimiento });
    });
});

app.get('/api/resumen', (req, res) => {
    const q1 = "SELECT COUNT(*) as total FROM Equipos";
    const q2 = "SELECT COUNT(*) as hoy FROM Mantenimientos WHERE proximo_mantenimiento <= CURDATE()";
    const q3 = "SELECT COUNT(*) as tareas FROM Tareas WHERE estado = 'Pendiente'";
    const q4 = "SELECT COUNT(*) as areas FROM Areas";

    db.query(`${q1}; ${q2}; ${q3}; ${q4}`, (err, results) => {
        if (err) return res.status(500).send(err);
        res.json({
            equipos: results[0][0].total,
            mantenimientos: results[1][0].hoy,
            tareas: results[2][0].tareas, // Asumiendo que esta tabla se creará pronto
            areas: results[3][0].areas
        });
    });
});

app.listen(PORT, () => {
    console.log(`Servidor corriendo en http://localhost:${PORT}`);
});