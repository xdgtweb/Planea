// DEBUG 1: ¿Se está ejecutando este archivo?
console.log("[app.js] PASO 1: Archivo cargado y ejecutándose.");

import { checkLoginStatus, logout } from './auth.js';
import { showLoginScreen } from './ui_auth.js';
import * as ui from './ui.js'; // Importar ui como módulo
import * as modals from './modals.js';
import { fetchData } from './utils.js';
 
// No importamos 'views.js' aquí para evitar la dependencia circular.

export let modosDisponibles = []; 
export let modoActivo = 'dia-a-dia'; 
export let fechaCalendarioActual = new Date(); 
export let myConfettiInstance = null; 
export let currentUser = { // Nuevo: Almacenar la información del usuario actual
    id: null,
    username: null,
    email_verified: false,
    is_admin: false,
    is_admin_original_login: false // Para saber si el admin accedió como otro usuario
};

document.addEventListener('DOMContentLoaded', () => {
    console.log("[app.js] PASO 2: DOM listo. Llamando a checkLoginStatus...");
    checkLoginStatus().then(status => {
        console.log("[app.js] PASO 3: Respuesta recibida de checkLoginStatus:", status);
        if (status && status.loggedIn) {
            currentUser.id = status.usuario_id;
            currentUser.username = status.username;
            currentUser.email_verified = status.email_verified; // Actualizar estado de verificación
            currentUser.is_admin = status.is_admin; // Actualizar rol de administrador
            currentUser.is_admin_original_login = status.is_admin_original_login || false; // Si se accedió como admin

            console.log("APP.JS: currentUser después de verificar sesión:", currentUser); // Añade esta línea
            console.log("[app.js] PASO 4A: Decisión -> Usuario LOGUEADO. Llamando a cargarModos()...");
            cargarModos(); // Esto se encargará de configurar la UI y cargar el contenido
        } else {
            console.log("[app.js] PASO 4B: Decisión -> Usuario NO LOGUEADO. Llamando a showLoginScreen()...");
            showLoginScreen();
        }
    }).catch(error => {
        console.error("[app.js] ERROR en la cadena de promesas de checkLoginStatus:", error);
        showLoginScreen();
    });
});

export function setFechaCalendarioActual(newDate) {
    if (newDate instanceof Date && !isNaN(newDate)) {
        fechaCalendarioActual = newDate;
        if (document.querySelector('.app-container')) {
            activarModo('dia-a-dia');
        }
    }
}

export async function cargarModos() {
    const mainContent = document.getElementById('main-content');
    mainContent.innerHTML = `
        <div id="initial-loader" class="initial-loader" style="display: none;"><div class="spinner"></div></div>
        <div class="app-container">
            <header class="app-header">
                <div class="logo">
                    <h1>Planea</h1>
                </div>
                <div class="user-info">
                    <span id="username-display"></span>
                    <button id="logout-button" class="logout-button"><i class="fas fa-sign-out-alt"></i></button>
                    ${currentUser.is_admin ? '<button id="admin-button" class="logout-button" style="background-color: var(--color-warning); color: var(--text-on-primary);"><i class="fas fa-crown"></i> Admin</button>' : ''}
                </div>
            </header>
            <nav id="nav-modes-container" class="nav-modes"></nav>
            <main id="modo-contenido" class="modo-contenido"></main>
        </div>
        <div id="admin-panel-overlay" class="form-overlay hidden" role="dialog" aria-modal="true" aria-hidden="true" inert>
            <div class="form-modal">
                <div class="form-modal-header">
                    <h3 id="admin-panel-title">Panel de Administrador</h3>
                </div>
                <div id="admin-panel-content" class="form-modal-content">
                    <p>Cargando usuarios...</p>
                </div>
                <div class="form-modal-actions">
                    <button type="button" id="admin-panel-revert-btn" class="btn btn-secondary hidden">Volver a mi sesión</button>
                    <button type="button" id="admin-panel-close-btn" class="cancel-btn">Cerrar</button>
                </div>
            </div>
        </div>
    `;

    // Inicializar módulos de la aplicación aquí
    // El módulo UI se inicializa con el currentUser ya poblado
    ui.initUIModule({ 
        currentUser: currentUser 
    });
    // Se eliminan las llamadas directas a setupEventListeners() y updateUsernameInUI()
    // ya que ui.initUIModule() las maneja internamente.
    
    await initAppModules(); // Esto contiene otras inicializaciones de módulos

    try {
        modosDisponibles = await fetchData('/modos');
        renderizarBotonesModos();
        await activarModo(modoActivo);
    } catch (error) {
        console.error("Error fatal al cargar modos.", error);
    }

    document.getElementById('logout-button').addEventListener('click', async () => {
        const result = await logout();
        if (result.success) {
            showLoginScreen();
        } else {
            alert(result.message || "Error al cerrar sesión.");
        }
    });

    // Nuevo: Listener para el botón de administrador
    const adminButton = document.getElementById('admin-button');
    if (adminButton) {
        adminButton.addEventListener('click', showAdminPanel);
    }

    // Nuevo: Mostrar mensaje de verificación de correo si es necesario
    if (!currentUser.email_verified) {
        alert("¡Importante! Por favor, verifica tu correo electrónico para acceder a todas las funcionalidades. Revisa tu bandeja de entrada o spam.");
        // Podrías añadir un elemento HTML para un mensaje más permanente
    }
}

function renderizarBotonesModos() {
    const navModesContainer = document.getElementById('nav-modes-container');
    if (!navModesContainer) return;
    navModesContainer.innerHTML = '';
    modosDisponibles.forEach(modo => {
        const button = document.createElement('button');
        button.className = 'nav-button';
        button.dataset.modeId = modo.id;
        button.textContent = modo.nombre;
        if (modo.id === modoActivo) button.classList.add('active-mode');
        button.onclick = () => activarModo(modo.id);
        navModesContainer.appendChild(button);
    });
}

async function activarModo(modeId) {
    const { renderizarModoDiaADia, renderizarModoObjetivos } = await import('./views.js');
    ui.closeAllActionPanels(); // Asegurarse de usar la función exportada de ui.js
    modoActivo = modeId;

    const navModesContainer = document.getElementById('nav-modes-container');
    if (navModesContainer) {
        navModesContainer.querySelectorAll('.nav-button').forEach(btn => {
            btn.classList.toggle('active-mode', btn.dataset.modeId === modeId);
        });
    }

    if (modeId === 'dia-a-dia') {
        await renderizarModoDiaADia();
    } else if (modeId === 'corto-medio-plazo' || modeId === 'largo-plazo') {
        await renderizarModoObjetivos(modeId);
    } else if (modeId === 'admin-panel') {
        // Nuevo: Mostrar pantalla de login si no es admin
        if (!currentUser.is_admin) {
            alert("Acceso denegado: solo los administradores pueden ver este panel.");
            // Opcional: Redirigir a otro modo si el acceso es denegado
            modoActivo = 'dia-a-dia'; 
            await activarModo(modoActivo); // Llama a activarModo para renderizar el nuevo modo
            renderizarBotonesModos(); // Para actualizar el botón activo visualmente
            return;
        }
        // Asumiendo que renderizarAdminPanel es una función en views.js o un módulo similar
        const { renderizarAdminPanel } = await import('./views.js');
        await renderizarAdminPanel();
    }
    // No hay necesidad de un default, los modos no reconocidos se gestionan arriba o no deberían ocurrir.
}

async function initAppModules() {
    // Importa initViewsModule dinámicamente ya que views.js no se importa a nivel superior
    const { initViewsModule } = await import('./views.js');

    const confettiCanvas = document.getElementById('confetti-canvas');
    if (confettiCanvas && typeof confetti !== 'undefined') {
        myConfettiInstance = confetti.create(confettiCanvas, { resize: true, useWorker: true });
    }

    initViewsModule({
        modoContenido: document.getElementById('modo-contenido'),
        appFechaCalendarioActual: fechaCalendarioActual,
        appSetFechaCalendarioActual: setFechaCalendarioActual,
        modosDisponibles: modosDisponibles,
        myConfettiInstance: myConfettiInstance,
        currentUser: currentUser // Pasar la información del usuario actual a views.js
    });
    
    // ui.initUIModule ya se llama en cargarModos. Se eliminan llamadas redundantes aquí.
}

// Nuevo: Función para mostrar el panel de administrador
async function showAdminPanel() {
    const adminPanelOverlay = document.getElementById('admin-panel-overlay');
    const adminPanelContent = document.getElementById('admin-panel-content');
    const adminPanelRevertBtn = document.getElementById('admin-panel-revert-btn');
    const adminPanelCloseBtn = document.getElementById('admin-panel-close-btn');

    adminPanelContent.innerHTML = '<p>Cargando usuarios...</p>';
    adminPanelOverlay.classList.remove('hidden');
    adminPanelOverlay.removeAttribute('inert');

    // Manejar botón de cerrar
    adminPanelCloseBtn.onclick = () => {
        adminPanelOverlay.classList.add('hidden');
        adminPanelOverlay.setAttribute('inert', 'true');
    };

    // Manejar botón de revertir sesión (solo visible si se está "accediendo como" otro usuario)
    if (currentUser.is_admin_original_login) {
        adminPanelRevertBtn.classList.remove('hidden');
        adminPanelRevertBtn.onclick = async () => {
            try {
                const response = await fetchData('/admin-users', 'POST', { action: 'revert-login' });
                if (response.success) {
                    alert(response.message);
                    // Recargar la app para reflejar la sesión del admin original
                    // Es importante actualizar el currentUser global
                    currentUser.id = response.usuario_id; // El ID del usuario al que se cambió
                    currentUser.username = response.username;
                    currentUser.email_verified = true; // Asumimos verificado
                    currentUser.is_admin = true;
                    currentUser.is_admin_original_login = false;
                    cargarModos();
                    adminPanelOverlay.classList.add('hidden');
                    adminPanelOverlay.setAttribute('inert', 'true');
                } else {
                    alert(response.error || "Error al revertir la sesión.");
                }
            } catch (error) {
                alert("Error de conexión al revertir la sesión: " + error.message);
            }
        };
    } else {
        adminPanelRevertBtn.classList.add('hidden');
    }


    try {
        const users = await fetchData('/admin-users', 'GET', { action: 'list' });
        if (users.length === 0) {
            adminPanelContent.innerHTML = '<p>No hay usuarios registrados (excepto el administrador actual).</p>';
            return;
        }

        let usersHtml = `
            <div class="form-group">
                <label for="admin-user-select" class="form-label">Seleccionar usuario:</label>
                <select id="admin-user-select" class="form-control">
                    ${users.map(user => `<option value="${user.id}">${user.username} (${user.email})</option>`).join('')}
                </select>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <button type="button" id="admin-login-as-btn" class="btn btn-primary">Acceder como este usuario</button>
            </div>
        `;
        adminPanelContent.innerHTML = usersHtml;

        const loginAsBtn = document.getElementById('admin-login-as-btn');
        loginAsBtn.onclick = async () => {
            const selectedUserId = document.getElementById('admin-user-select').value;
            if (selectedUserId) {
                try {
                    const response = await fetchData('/admin-users', 'POST', { action: 'login-as', target_user_id: parseInt(selectedUserId) });
                    if (response.success) {
                        alert(`Has accedido como ${response.username}.`);
                        // Recargar la app para reflejar la nueva sesión
                        // Es importante actualizar el currentUser global
                        currentUser.id = parseInt(selectedUserId); // El ID del usuario al que se cambió
                        currentUser.username = response.username;
                        currentUser.email_verified = true; // Asumimos que los que existen ya pueden ser tratados como verificados
                        currentUser.is_admin = false; // Ya no es admin en esta sesión "simulada"
                        currentUser.is_admin_original_login = true; // Flag para saber que es una sesión "simulada"

                        cargarModos();
                        adminPanelOverlay.classList.add('hidden');
                        adminPanelOverlay.setAttribute('inert', 'true');
                    } else {
                        alert(response.error || "Error al acceder como usuario.");
                    }
                } catch (error) {
                    alert("Error de conexión al acceder como usuario: " + error.message);
                }
            }
        };

    } catch (error) {
        adminPanelContent.innerHTML = `<p class="error-mensaje">Error al cargar usuarios: ${error.message}</p>`;
    }
}