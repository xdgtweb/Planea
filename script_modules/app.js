// DEBUG 1: ¿Se está ejecutando este archivo?
console.log("[app.js] PASO 1: Archivo cargado y ejecutándose.");

import { checkLoginStatus, logout } from './auth.js';
import { showLoginScreen } from './ui_auth.js';
import { setupEventListeners, updateUsernameInUI, closeAllActionPanels } from './ui.js';
import { fetchData } from './utils.js';

// No importamos 'views.js' aquí para evitar la dependencia circular.

export let modosDisponibles = []; 
export let modoActivo = 'dia-a-dia'; 
export let fechaCalendarioActual = new Date(); 
export let myConfettiInstance = null; 

document.addEventListener('DOMContentLoaded', () => {
    console.log("[app.js] PASO 2: DOM listo. Llamando a checkLoginStatus...");
    checkLoginStatus().then(status => {
        console.log("[app.js] PASO 3: Respuesta recibida de checkLoginStatus:", status);
        if (status && status.loggedIn) {
            console.log("[app.js] PASO 4A: Decisión -> Usuario LOGUEADO. Llamando a cargarModos()...");
            cargarModos();
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
                </div>
            </header>
            <nav id="nav-modes-container" class="nav-modes"></nav>
            <main id="modo-contenido" class="modo-contenido"></main>
        </div>
    `;

    await initAppModules();
    
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
    closeAllActionPanels();
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
    }
}

async function initAppModules() {
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
        myConfettiInstance: myConfettiInstance
    });
    
    setupEventListeners();
    updateUsernameInUI();
}