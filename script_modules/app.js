// script_modules/app.js

import { fetchData } from './utils.js'; 
import { closeAllActionPanels } from './ui.js';
import { 
    renderizarModoDiaADia, 
    renderizarModoObjetivos,
    initViewsModule
} from './views.js';
// CAMBIO: Importar desde el nuevo módulo de autenticación
import { checkLoginStatus } from './auth.js';

export let modosDisponibles = []; 
export let modoActivo = 'dia-a-dia'; 
export let fechaCalendarioActual = new Date(); 

let modoContenido = null;
let navModesContainer = null;
let confettiCanvas = null; 
export let myConfettiInstance = null; 

export function setFechaCalendarioActual(newDate) {
    if (newDate instanceof Date && !isNaN(newDate)) {
        const nuevaFechaNormalizada = new Date(Date.UTC(newDate.getFullYear(), newDate.getMonth(), newDate.getDate()));
        const actualNormalizada = new Date(Date.UTC(fechaCalendarioActual.getFullYear(), fechaCalendarioActual.getMonth(), fechaCalendarioActual.getDate()));

        if (nuevaFechaNormalizada.getTime() !== actualNormalizada.getTime()) {
            fechaCalendarioActual = newDate; 
            if (modoActivo === 'dia-a-dia') {
                activarModo('dia-a-dia'); 
            }
        }
    } else {
        console.error("setFechaCalendarioActual: Se recibió una fecha inválida:", newDate);
    }
}

// CAMBIO: Esta función se llamará después del login exitoso
export async function cargarModos() {
    // CAMBIO: Re-asignar contenedores aquí, porque el DOM se reconstruye tras el login
    modoContenido = document.getElementById('modo-contenido');
    navModesContainer = document.getElementById('nav-modes-container');

    const urlEndpoint = `modos`; 
    try {
        modosDisponibles = await fetchData(urlEndpoint); 
        renderizarBotonesModos();
        await activarModo(modoActivo); 
    } catch (error) {
        console.error("Error fatal al cargar modos.", error);
        if (navModesContainer) navModesContainer.innerHTML = `<p class="error-mensaje-nav">Error al cargar modos.</p>`;
        if (modoContenido) modoContenido.innerHTML = `<p class="error-mensaje">No se pudo iniciar la aplicación: ${error.message}</p>`;
    }
}

function renderizarBotonesModos() {
    if (!navModesContainer) { console.error("renderizarBotonesModos: Contenedor #nav-modes-container NO encontrado!"); return; }
    navModesContainer.innerHTML = '';
    if (!modosDisponibles || !Array.isArray(modosDisponibles) || modosDisponibles.length === 0) {
        navModesContainer.innerHTML = `<p class="error-mensaje-nav">No hay modos.</p>`;
        return;
    }
    modosDisponibles.forEach(modo => {
        const button = document.createElement('button');
        button.type = 'button'; button.classList.add('nav-button'); button.dataset.modeId = modo.id; button.textContent = modo.nombre;
        if (modo.id === modoActivo) { button.classList.add('active-mode'); }
        button.onclick = () => activarModo(modo.id);
        navModesContainer.appendChild(button);
    });
}

async function activarModo(modeId) { 
    closeAllActionPanels(); 
    modoActivo = modeId; 
    
    if (navModesContainer) {
        navModesContainer.querySelectorAll('.nav-button').forEach(btn => { 
            btn.classList.remove('active-mode');
            if (btn.dataset.modeId === modeId) { btn.classList.add('active-mode'); }
        });
    }
    
    if (!modoContenido) {
        modoContenido = document.getElementById('modo-contenido');
    }
    
    if (modosDisponibles.find(m => m.id === modeId)) { 
        if (modeId === 'dia-a-dia') {
            await renderizarModoDiaADia(); 
        } else if (modeId === 'corto-medio-plazo' || modeId === 'largo-plazo') {
            await renderizarModoObjetivos(modeId); 
        } else {
            if(modoContenido) modoContenido.innerHTML = `<p class="error-mensaje">Modo '${modeId}' no reconocido.</p>`;
        }
    } else {
        if(modoContenido) modoContenido.innerHTML = `<p class="error-mensaje">No se pudieron cargar los datos iniciales o el modo no es válido.</p>`;
    }
}

// CAMBIO: Esta función inicializa los módulos después de que el DOM de la app se ha cargado
export function initAppModules() {
    confettiCanvas = document.getElementById('confetti-canvas');
    if (confettiCanvas && typeof confetti !== 'undefined') { 
        myConfettiInstance = confetti.create(confettiCanvas, { resize: true, useWorker: true });
    } else {
        console.error("Elemento canvas para confetti no encontrado o librería confetti no cargada.");
    }

    const appContainer = document.getElementById('app-container');
    const modoContenidoEl = appContainer ? appContainer.querySelector('#modo-contenido') : null;

    initViewsModule({
        modoContenido: modoContenidoEl,
        appFechaCalendarioActual: fechaCalendarioActual, 
        appSetFechaCalendarioActual: setFechaCalendarioActual, 
        modosDisponibles: modosDisponibles, 
        appModoActivo: modoActivo, 
        myConfettiInstance: myConfettiInstance 
    });

    document.body.addEventListener('click', e => { 
        const panelFlotanteActivo = document.querySelector('.actions-panel-floating.actions-visible');
        if (panelFlotanteActivo && !panelFlotanteActivo.contains(e.target) && !e.target.closest('.action-toggle-btn')) { 
            closeAllActionPanels(); 
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    checkLoginStatus();
});