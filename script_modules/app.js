// script_modules/app.js (Módulo Principal y Orquestador)

// --- IMPORTACIONES DE OTROS MÓDULOS ---
import { fetchData } from './utils.js'; 
import { closeAllActionPanels } from './ui.js';
import { 
    renderizarModoDiaADia, 
    renderizarModoObjetivos,
    initViewsModule // Importar la función de inicialización de views.js
} from './views.js';

// --- VARIABLES DE ESTADO PRINCIPALES DE LA APP (ALGUNAS EXPORTADAS) ---
export let modosDisponibles = []; 
export let modoActivo = 'dia-a-dia'; 
export let fechaCalendarioActual = new Date(); 

// --- REFERENCIAS A ELEMENTOS DEL DOM (se asignan en DOMContentLoaded) ---
// DECLARACIÓN de las variables en el ámbito del módulo. Esto es lo que probablemente faltaba.
let modoContenido = null;
let navModesContainer = null;
let confettiCanvas = null; 
export let myConfettiInstance = null; 

// --- FUNCIÓN PARA ACTUALIZAR LA FECHA DEL CALENDARIO GLOBALMENTE (EXPORTADA) ---
export function setFechaCalendarioActual(newDate) {
    if (newDate instanceof Date && !isNaN(newDate)) {
        const nuevaFechaNormalizada = new Date(Date.UTC(newDate.getFullYear(), newDate.getMonth(), newDate.getDate()));
        const actualNormalizada = new Date(Date.UTC(fechaCalendarioActual.getFullYear(), fechaCalendarioActual.getMonth(), fechaCalendarioActual.getDate()));

        if (nuevaFechaNormalizada.getTime() !== actualNormalizada.getTime()) {
            fechaCalendarioActual = newDate; 
            console.log("App: fechaCalendarioActual actualizada a", fechaCalendarioActual.toISOString());
            if (modoActivo === 'dia-a-dia') {
                activarModo('dia-a-dia'); 
            }
        }
    } else {
        console.error("setFechaCalendarioActual: Se recibió una fecha inválida:", newDate);
    }
}

// --- LÓGICA PRINCIPAL DE LA APLICACIÓN ---

async function cargarModos() {
    console.log("cargarModos (app.js): Iniciando...");
    const urlEndpoint = `modos`; 
    try {
        modosDisponibles = await fetchData(urlEndpoint); 
        console.log("cargarModos (app.js): Modos recibidos:", modosDisponibles);
        renderizarBotonesModos();
        await activarModo(modoActivo); 
    } catch (error) {
        console.error("cargarModos (app.js): Error fatal al cargar modos.", error);
        let errorMessage = `No se pudo iniciar la aplicación: ${error.message}`;
        if (navModesContainer) navModesContainer.innerHTML = `<p class="error-mensaje-nav">Error al cargar modos.</p>`;
        if (modoContenido) modoContenido.innerHTML = `<p class="error-mensaje">${errorMessage.replace(/\n/g, '<br>')}</p>`;
    }
    console.log("cargarModos (app.js): Finalizado.");
}

function renderizarBotonesModos() {
    console.log("renderizarBotonesModos (app.js): Iniciando. Contenedor:", navModesContainer);
    if (!navModesContainer) { console.error("renderizarBotonesModos (app.js): Contenedor #nav-modes-container NO encontrado!"); return; }
    navModesContainer.innerHTML = '';
    if (!modosDisponibles || !Array.isArray(modosDisponibles) || modosDisponibles.length === 0) {
        console.warn("renderizarBotonesModos (app.js): No hay modos disponibles para renderizar.");
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
    console.log("renderizarBotonesModos (app.js): Finalizado.");
}

async function activarModo(modeId) { 
    console.log("activarModo (app.js): Activando modo:", modeId);
    closeAllActionPanels(); 
    modoActivo = modeId; 
    
    if (navModesContainer) {
        navModesContainer.querySelectorAll('.nav-button').forEach(btn => { 
            btn.classList.remove('active-mode');
            if (btn.dataset.modeId === modeId) { btn.classList.add('active-mode'); }
        });
    }
    
    if (modosDisponibles && modosDisponibles.find(m => m.id === modeId)) { 
        if (modeId === 'dia-a-dia') {
            console.log("activarModo (app.js): Llamando a renderizarModoDiaADia.");
            await renderizarModoDiaADia(); 
        } else if (modeId === 'corto-medio-plazo' || modeId === 'largo-plazo') {
            console.log("activarModo (app.js): Llamando a renderizarModoObjetivos para", modeId);
            await renderizarModoObjetivos(modeId); 
        } else {
            console.warn("activarModo (app.js): Modo desconocido:", modeId);
            if(modoContenido) modoContenido.innerHTML = `<p class="error-mensaje">Modo '${modeId}' no reconocido.</p>`;
        }
        console.log("activarModo (app.js): Modo", modeId, "activado y renderizado (o intento).");
    } else if (modosDisponibles && Array.isArray(modosDisponibles) && modosDisponibles.length > 0) { 
         console.warn("activarModo (app.js): El modo", modeId, "no está en la lista de modos disponibles.");
         if(modoContenido) modoContenido.innerHTML = `<p class="error-mensaje">Modo '${modeId}' no es válido.</p>`;
    } else if (modoContenido && !modoContenido.querySelector('.error-mensaje')) {
        modoContenido.innerHTML = `<p class="error-mensaje">No se pudieron cargar los datos iniciales o los modos. Intenta recargar la página.</p>`;
    }
}


// --- INICIALIZACIÓN DE LA APLICACIÓN ---
document.addEventListener('DOMContentLoaded', function() {
    // ASIGNACIÓN a las variables declaradas arriba
    modoContenido = document.getElementById('modo-contenido');
    navModesContainer = document.getElementById('nav-modes-container');
    confettiCanvas = document.getElementById('confetti-canvas');

    if (confettiCanvas && typeof confetti !== 'undefined') { 
        myConfettiInstance = confetti.create(confettiCanvas, { resize: true, useWorker: true });
    } else {
        console.error("Elemento canvas para confetti no encontrado o librería confetti no cargada.");
    }

    // Pasar las referencias y estado necesarios al módulo views.js
    initViewsModule({
        modoContenido: modoContenido, 
        appFechaCalendarioActual: fechaCalendarioActual, 
        appSetFechaCalendarioActual: setFechaCalendarioActual, 
        modosDisponibles: modosDisponibles, 
        appModoActivo: modoActivo, 
        myConfettiInstance: myConfettiInstance 
    });

    // Listener global para cerrar paneles de acción flotantes
    document.addEventListener('click', e => { 
        const panelFlotanteActivo = document.querySelector('.actions-panel-floating.actions-visible');
        if (panelFlotanteActivo && !panelFlotanteActivo.contains(e.target) && !e.target.closest('.action-toggle-btn')) { 
            closeAllActionPanels(); 
        }
    });
    
    console.log("app.js (DOMContentLoaded): Iniciando carga de modos...");
    cargarModos();
});