document.addEventListener('DOMContentLoaded', function() {
    // Seleccionamos todos los botones de mostrar/ocultar contraseña
    const togglePasswordBtns = document.querySelectorAll('.toggle-password');

    togglePasswordBtns.forEach(button => {
        button.addEventListener('click', function() {
            // Obtenemos el ID del input asociado al botón
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');

            // Alternamos el tipo de input y el ícono
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash'); // Cambia a ícono de ojo tachado
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye'); // Cambia a ícono de ojo normal
            }
        });
    });
});

// ==========================================
// Lógica para el Registro de Usuarios
// ==========================================
const formRegistro = document.getElementById('formRegistro');

if (formRegistro) {
    formRegistro.addEventListener('submit', async function(e) {
        e.preventDefault(); // Evita que la página se recargue

        // Extraer los valores de los inputs
        const nombre = document.getElementById('regNombre').value;
        const cargo = document.getElementById('regCargo').value;
        const edad = document.getElementById('regEdad').value;
        const correo = document.getElementById('regCorreo').value;
        const password = document.getElementById('regPassword').value;

        try {
            // Enviar los datos al servidor backend usando Fetch API
            const response = await fetch('/api/registro', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ nombre, cargo, edad, correo, password })
            });

            const data = await response.json();

            // Verificar si el servidor respondió con éxito
            if (response.ok) {
                alert('¡Éxito! ' + data.mensaje);
                formRegistro.reset(); // Limpiar el formulario
                
                // Opcional: Cambiar a la pestaña de Iniciar Sesión automáticamente
                document.getElementById('pills-login-tab').click();
            } else {
                // Mostrar el error (por ejemplo, correo duplicado)
                alert('Error: ' + data.error);
            }

        } catch (error) {
            console.error('Error de conexión:', error);
            alert('Hubo un error al intentar comunicarse con el servidor.');
        }
    });
}

// ==========================================
// Lógica para Iniciar Sesión (Login)
// ==========================================
const formLogin = document.getElementById('formLogin');

if (formLogin) {
    formLogin.addEventListener('submit', async function(e) {
        e.preventDefault(); // Evita que la página se recargue

        // Extraer los valores de los inputs
        const correo = document.getElementById('loginCorreo').value;
        const password = document.getElementById('loginPassword').value;

        try {
            // Enviar los datos al servidor backend
            const response = await fetch('/api/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ correo, password })
            });

            const data = await response.json();

            // Verificar si el inicio de sesión fue exitoso
            if (response.ok) {
                // Guardar los datos del usuario en el almacenamiento local del navegador
                localStorage.setItem('usuarioSistema', JSON.stringify(data.usuario));
                
                // Redireccionar al Dashboard
                window.location.href = 'dashboard.html';
            } else {
                // Mostrar alerta de error (ej: contraseña incorrecta)
                alert('Error: ' + data.error);
            }

        } catch (error) {
            console.error('Error de conexión:', error);
            alert('Hubo un error al intentar comunicarse con el servidor.');
        }
    });
}

